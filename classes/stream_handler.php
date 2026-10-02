<?php
/**
 * AI 编程助手 - SSE 流式消息处理器
 *
 * 封装 Server-Sent Events (SSE) 相关的所有逻辑，包括：
 * - SSE 事件发送
 * - 缓存/拦截响应发送
 * - 流式头部设置
 * - Shutdown 处理器注册
 *
 * 从 stream_message.php 中提取，消除全局函数，提高可测试性。
 *
 * @package    local_ai_programming_assistant
 * @author     zfl
 */

namespace local_ai_programming_assistant;

defined('MOODLE_INTERNAL') || die();

class stream_handler {

    /** @var int SSE padding 行数（约 1KB，用于强制底层 flush） */
    const SSE_PADDING_LINES = 10;

    /** @var bool 流是否已完成 */
    private bool $streamdone = false;

    /**
     * 发送 SSE 事件
     *
     * @param string $event 事件名（仅允许小写字母和下划线）
     * @param mixed  $data  事件数据（将编码为 JSON）
     */
    public function send_sse(string $event, $data): void {
        if (connection_aborted()) {
            return;
        }
        // 注意：不能用 ctype_lower() 校验，它会拒绝含下划线的合法事件名（如 kb_metadata）。
        if ($event === '' || !preg_match('/^[a-z_]+$/', $event)) {
            return;
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            // 尝试回退编码，容忍非法 UTF-8 字符
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
        }
        if ($json === false) {
            // 最终降级：发送最小化 JSON
            $json = '{}';
            if ($event === 'error') {
                $json = '{"message":"Internal encoding error"}';
            }
        }
        echo "event: {$event}\ndata: {$json}\n\n";
        // 刷新所有输出缓冲层（ob_flush 不降低层级，需按初始层级计数）
        $maxlevel = ob_get_level();
        for ($i = 0; $i < $maxlevel; $i++) {
            @ob_flush();
        }
        flush();
    }

    /**
     * 设置 SSE 响应头部并清空所有输出缓冲区
     */
    public function setup_sse_headers(): void {
        \core\session\manager::write_close();

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        header('Content-Encoding: identity');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');

        $maxclean = 100;
        while (ob_get_level() > 0 && $maxclean > 0) {
            ob_end_clean();
            $maxclean--;
        }
        ob_implicit_flush(true);

        // 发送 ~1KB 的 SSE 注释 padding，强制底层 flush 并触发浏览器开始解析流
        echo str_repeat(": keepalive - this is a comment line to force flush\n\n", self::SSE_PADDING_LINES);
        flush();
    }

    /**
     * 发送 HTTP 错误响应
     *
     * @param int    $httpcode HTTP 状态码
     * @param string $message  错误消息
     */
    public static function http_error(int $httpcode, string $message): void {
        // 清理所有输出缓冲区，防止 headers already sent 和响应污染
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header("HTTP/1.1 {$httpcode}");
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * 注册 Shutdown 处理器，确保致命错误时通过 SSE 通知前端
     *
     * 安全说明：原始 PHP 错误消息可能包含服务器内部路径、SQL 片段、
     * 文件系统结构等敏感信息，直接发送到前端会造成信息泄露。
     * 此处仅向前端发送通用错误提示，详细错误已由 error_log() 记录到服务端日志。
     */
    public function register_shutdown_handler(): void {
        register_shutdown_function(function () {
            if ($this->streamdone) {
                return;
            }
            $lastError = error_get_last();
            // E_USER_ERROR 是 trigger_error() 触发的可恢复错误，不应视为致命错误
            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
            if (!in_array($lastError['type'] ?? 0, $fatalTypes, true)) {
                $this->streamdone = true;
                return;
            }
            // 详细错误信息仅记录到服务端日志，不发送到前端
            error_log('[ai_programming_assistant] shutdown fatal error: ' . ($lastError['message'] ?? 'unknown'));
            // 前端只显示通用错误消息，附带时间戳供管理员定位日志
            @$this->send_sse('error', ['message' => get_string('erroroccurred', 'local_ai_programming_assistant')]);
            @$this->send_sse('done', ['success' => false]);
        });
    }

    /**
     * 发送标准 SSE 错误响应序列（error + done 两条事件）
     *
     * 消除 stream_message.php 中多个 catch 块重复的 error/done 发送模式。
     *
     * @param string $message 错误消息
     * @param bool   $markdone 是否标记流为已完成（默认 true）
     */
    public function send_error_response(string $message, bool $markdone = true): void {
        $this->send_sse('error', ['message' => $message]);
        $this->send_sse('done', ['success' => false, 'feedbackid' => 0]);
        if ($markdone) {
            $this->mark_done();
        }
    }

    /**
     * 检查流是否已完成
     *
     * @return bool
     */
    public function is_done(): bool {
        return $this->streamdone;
    }

    /**
     * 标记流为已完成
     */
    public function mark_done(): void {
        $this->streamdone = true;
    }

}