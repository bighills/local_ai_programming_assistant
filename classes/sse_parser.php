<?php
/**
 * AI 编程助手 - SSE 流式响应解析器
 *
 * 负责解析 Server-Sent Events 格式的 API 流式响应，
 * 提取 content token、thinking token 和 finish_reason。
 *
 * @package    local_ai_programming_assistant
 */
namespace local_ai_programming_assistant;
defined('MOODLE_INTERNAL') || die();

class sse_parser {

    /** @var string 累积的完整正文内容 */
    private string $fullcontent = '';
    /** @var int 完整正文内容长度（缓存，避免重复 strlen() 调用） */
    private int $fullcontentlength = 0;
    /** @var string 累积的 thinking 内容 */
    private string $thinkingcontent = '';
    /** @var int thinking 内容长度（缓存，避免重复 strlen() 调用） */
    private int $thinkingcontentlength = 0;
    /** @var string 完成原因 */
    private string $finishreason = '';
    /** @var bool 是否启用 thinking 输出 */
    private bool $enablethinking;

    /** @var int 最大内容长度限制（防止 OOM），默认 1MB */
    private int $maxcontentlength;

    /** @var callable|null token 回调 */
    private $ontoken = null;
    /** @var callable|null thinking token 回调 */
    private $onthinking = null;

    /**
     * @param bool $enablethinking 是否启用 thinking 模式
     * @param int $maxcontentlength 最大内容长度限制
     */
    public function __construct(bool $enablethinking = false, int $maxcontentlength = 1048576) {
        $this->enablethinking = $enablethinking;
        $this->maxcontentlength = $maxcontentlength;
    }

    /**
     * 设置 token 和 thinking 的回调函数
     *
     * @param callable $ontoken    收到普通 token 时调用
     * @param callable $onthinking 收到 thinking token 时调用
     */
    public function set_callbacks(callable $ontoken, callable $onthinking): void {
        $this->ontoken = $ontoken;
        $this->onthinking = $onthinking;
    }

    /**
     * 解析一行 SSE 数据
     *
     * @param string $line 单行 SSE 数据
     */
    public function parse_line(string $line): void {
        // 快速首字符检查：SSE data 行必须以 'd' 开头（部分网关会发送大写？保守起见只认小写）
        if ($line === '' || $line[0] !== 'd') {
            return;
        }
        // 兼容两种格式：标准 "data: {...}" 以及部分网关/代理输出的 "data:{...}"（冒号后无空格）
        if (strncmp($line, 'data:', 5) !== 0) {
            return;
        }
        $raw = trim(substr($line, 5));
        if ($raw === '' || $raw === '[DONE]') {
            // 空数据或 [DONE] 标记
            return;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            config_manager::debug_log('sse_parser: JSON decode failed for raw data', $raw);
            return;
        }

        $token = $this->extract_token($decoded);
        $thinkingtoken = $this->extract_thinking_token($decoded);

        // 字段级截断：严格遵守 maxcontentlength 上限，单个 token 超过剩余空间时截取前缀
        // 而非跳过整个事件（避免超大 delta 导致后续 token 全部丢失）
        $remainingcontent = max(0, $this->maxcontentlength - $this->fullcontentlength);
        $remainingthinking = max(0, $this->maxcontentlength - $this->thinkingcontentlength);

        if ($thinkingtoken !== '' && $remainingthinking > 0) {
            $thinkinglen = strlen($thinkingtoken);
            if ($thinkinglen > $remainingthinking) {
                $thinkingtoken = substr($thinkingtoken, 0, $remainingthinking);
                $thinkinglen = $remainingthinking;
            }
            $this->thinkingcontent .= $thinkingtoken;
            $this->thinkingcontentlength += $thinkinglen;
            if ($this->enablethinking && $this->onthinking) {
                ($this->onthinking)($thinkingtoken);
            }
        }

        if ($token !== '' && $remainingcontent > 0) {
            $tokenlen = strlen($token);
            if ($tokenlen > $remainingcontent) {
                $token = substr($token, 0, $remainingcontent);
                $tokenlen = $remainingcontent;
            }
            $this->fullcontent .= $token;
            $this->fullcontentlength += $tokenlen;
            if ($this->ontoken) {
                ($this->ontoken)($token);
            }
        }

        $this->extract_finish_reason($decoded);
    }

    /**
     * 解析 SSE 事件块（以 \n\n 分隔的多行文本）
     *
     * @param string $eventblock 一个完整的 SSE 事件块
     */
    public function parse_event(string $eventblock): void {
        foreach (explode("\n", $eventblock) as $line) {
            if ($line === '') {
                continue;
            }
            $this->parse_line($line);
        }
    }

    /**
     * 从缓冲区尾部提取残留的 SSE 数据
     *
     * @param string $buffer 残留缓冲区内容
     */
    public function parse_remaining_buffer(string $buffer): void {
        // 与 parse_line() 保持一致：同时接受 "data: " 与 "data:" 前缀
        if ($buffer !== '' && (strpos($buffer, 'data: ') !== false || strpos($buffer, 'data:') !== false)) {
            foreach (explode("\n", $buffer) as $line) {
                $this->parse_line($line);
            }
        }
    }

    /**
     * 尝试从非 SSE 格式的 JSON 响应中提取内容（回退）
     *
     * 安全说明：对完整响应体做 json_decode 可能造成内存耗尽，
     * 因此对未解析的响应体施加 maxcontentlength 上限；超过上限时仅
     * 在调试日志中记录，不做解析，直接交由 handle_stream_empty 处理。
     * 提取出的字段同样遵守 maxcontentlength 限制，防止单字段异常膨胀。
     *
     * @param string $buffer 原始响应体
     * @return bool 是否成功提取
     */
    public function try_parse_json_fallback(string $buffer): bool {
        if ($this->fullcontent !== '' || $buffer === '') {
            return false;
        }
        // 未解析响应体不得超过内容上限的 4 倍（留足 JSON 包装余量）。
        // 超过上限视为异常响应，避免 json_decode 造成内存耗尽。
        if (strlen($buffer) > $this->maxcontentlength * 4) {
            config_manager::debug_log('sse_parser: try_parse_json_fallback buffer exceeds maxcontentlimit', [
                'buffer_len' => strlen($buffer),
                'maxcontentlength' => $this->maxcontentlength,
            ]);
            return false;
        }
        $decoded = @json_decode($buffer, true);
        if (!is_array($decoded)) {
            return false;
        }

        $truncate = function (string $s): string {
            if (strlen($s) <= $this->maxcontentlength) {
                return $s;
            }
            return substr($s, 0, $this->maxcontentlength);
        };

        if (isset($decoded['choices'][0]['message']['content'])) {
            $this->fullcontent = $truncate($decoded['choices'][0]['message']['content']);
            $this->fullcontentlength = strlen($this->fullcontent);
            if (isset($decoded['choices'][0]['message']['reasoning_content'])) {
                $this->thinkingcontent = $truncate($decoded['choices'][0]['message']['reasoning_content']);
                $this->thinkingcontentlength = strlen($this->thinkingcontent);
            }
            $this->finishreason = $decoded['choices'][0]['finish_reason'] ?? '';
            return true;
        }
        if (isset($decoded['content'])) {
            $this->fullcontent = $truncate($decoded['content']);
            $this->fullcontentlength = strlen($this->fullcontent);
            if (isset($decoded['reasoning_content'])) {
                $this->thinkingcontent = $truncate($decoded['reasoning_content']);
                $this->thinkingcontentlength = strlen($this->thinkingcontent);
            }
            return true;
        }
        if (isset($decoded['text'])) {
            $this->fullcontent = $truncate($decoded['text']);
            $this->fullcontentlength = strlen($this->fullcontent);
            return true;
        }
        return false;
    }

    // ===== Getters =====

    public function get_full_content(): string { return $this->fullcontent; }
    public function get_thinking_content(): string { return $this->thinkingcontent; }
    public function get_finish_reason(): string { return $this->finishreason; }
    public function has_content(): bool { return $this->fullcontent !== ''; }

    // ===== Private helpers =====

    private function extract_token(array $decoded): string {
        if (isset($decoded['choices'][0]['delta']['content']) && is_string($decoded['choices'][0]['delta']['content'])) {
            return $decoded['choices'][0]['delta']['content'];
        }
        if (isset($decoded['content']) && is_string($decoded['content'])) {
            return $decoded['content'];
        }
        if (isset($decoded['text']) && is_string($decoded['text'])) {
            return $decoded['text'];
        }
        if (isset($decoded['response']) && is_string($decoded['response'])) {
            return $decoded['response'];
        }
        return '';
    }

    private function extract_thinking_token(array $decoded): string {
        if (isset($decoded['choices'][0]['delta']['reasoning_content']) && is_string($decoded['choices'][0]['delta']['reasoning_content'])) {
            return $decoded['choices'][0]['delta']['reasoning_content'];
        }
        if (isset($decoded['reasoning_content']) && is_string($decoded['reasoning_content'])) {
            return $decoded['reasoning_content'];
        }
        if (isset($decoded['delta']['reasoning_content']) && is_string($decoded['delta']['reasoning_content'])) {
            return $decoded['delta']['reasoning_content'];
        }
        return '';
    }

    private function extract_finish_reason(array $decoded): void {
        if (!empty($decoded['choices'][0]['finish_reason'])) {
            $this->finishreason = $decoded['choices'][0]['finish_reason'];
        } else if (!empty($decoded['finish_reason'])) {
            $this->finishreason = $decoded['finish_reason'];
        }
    }
}