<?php
/**
 * AI 编程助手 - 补全/对话编排器
 *
 * 核心编排类，按以下职责委派子组件：
 * - prompt_builder：提示词构建与历史管理
 * - ai_backend：AI 后端通信适配
 *
 * @author zfl
 * @package    local_ai_programming_assistant
 */
namespace local_ai_programming_assistant;
defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

use local_ai_programming_assistant\strategy\strategy_factory;
use local_ai_programming_assistant\strategy\action_strategy_interface;

class completion {

    /** AI 后端类型常量：Moodle 核心 AI 子系统 */
    const BACKEND_CORE_AI = 'core_ai';
    /** AI 后端类型常量：AI 工具管理器插件 */
    const BACKEND_AI_MANAGER = 'ai_manager';
    /** AI 后端类型常量：直接调用 OpenAI 兼容 API */
    const BACKEND_DIRECT = 'direct';

    /** @var string 用户输入的消息 */
    private string $message = '';
    /** @var array<int, array{role: string, content: string}> 对话历史记录（统一格式） */
    private array $history = [];
    /** @var string 助手名称（用于对话格式化） */
    private string $assistantname = '';
    /** @var string 用户名称（用于对话格式化） */
    private string $username = '';
    /** @var array<string, mixed> 页面上下文数据 */
    private array $pagecontext = [];
    /** @var string 当前操作类型 */
    private string $actiontype = 'free';
    /** @var int 对话会话 ID（0 表示无会话，使用客户端传入的历史） */
    private int $conversationid = 0;

    /** @var int 当前用户 ID（用于会话归属验证，防止 IDOR） */
    private int $userid = 0;

    /** @var action_strategy_interface 当前操作策略实例 */
    private action_strategy_interface $strategy;

    /** @var prompt_builder 提示词构建器 */
    private prompt_builder $promptBuilder;

    /** @var ai_backend|null AI 后端适配器（懒加载） */
    private ?ai_backend $aiBackend = null;

    /** @var bool 请求级守卫：是否已调用 apply_time_limit */
    private static bool $timelimitapplied = false;

    /** @var array 允许的操作类型列表 */
    const ALLOWED_ACTION_TYPES = ['free', 'question_analysis', 'idea_organization', 'code_debugging'];

    /** @var int 用户消息最大字符数 */
    const MAX_MESSAGE_LENGTH = 10000;

    /**
     * 重置所有静态缓存（用于 PHP-FPM 常驻进程或单元测试）
     */
    public static function reset(): void {
        self::$timelimitapplied = false;
    }

    /**
     * 构造函数 - 初始化 AI 补全对象
     *
     * @param string $message 用户输入的消息
     * @param array  $history 对话历史记录
     * @param array  $pagecontext 页面上下文数据（包含 actiontype、questiontext 等）
     * @param int    $conversationid 对话会话 ID（>0 时从服务端加载历史）
     * @param int    $userid 当前用户 ID（用于会话归属验证，防止 IDOR）
     */
    public function __construct(string $message, array $history = [], array $pagecontext = [], int $conversationid = 0, int $userid = 0) {
        $rawtype = $pagecontext['actiontype'] ?? 'free';
        $this->actiontype = in_array($rawtype, self::ALLOWED_ACTION_TYPES, true) ? $rawtype : 'free';

        $this->message = (mb_strlen($message, 'UTF-8') > self::MAX_MESSAGE_LENGTH)
            ? mb_substr($message, 0, self::MAX_MESSAGE_LENGTH, 'UTF-8')
            : $message;

        $this->conversationid = $conversationid;
        $this->userid = $userid;

        // 优先从服务端 DB 加载历史（验证会话归属），否则使用客户端传入的历史
        if ($this->conversationid > 0) {
            $this->history = $this->load_history_from_db($this->conversationid);
        } else {
            $maxhistory = config_manager::get_max_history_count();
            // 取最近 N 条（数组尾部），与 DB 路径 message_model::get_recent_messages()
            // 和前端 buildRequestData 的截断方向保持一致，避免丢弃最近的上下文。
            $this->history = array_slice($history, -$maxhistory);
        }

        $this->strategy = strategy_factory::create($this->actiontype);

        $this->assistantname = config_manager::get_config_cached('assistantname',
            get_string('assistantname', 'local_ai_programming_assistant'));
        $this->username = config_manager::get_config_cached('username',
            get_string('username', 'local_ai_programming_assistant'));

        $this->pagecontext = $pagecontext;

        $this->promptBuilder = new prompt_builder($this->strategy);
    }

    /**
     * 获取 AI 后端适配器（懒加载）
     */
    private function get_ai_backend(): ai_backend {
        if ($this->aiBackend === null) {
            $this->aiBackend = new ai_backend($this->message, $this->assistantname);
        }
        return $this->aiBackend;
    }

    /**
     * 从服务端 DB 加载对话历史
     *
     * 先验证会话归属当前用户（防止 IDOR），不属于则返回空历史。
     *
     * @param int $conversationid 会话 ID
     * @return array 对话历史数组，每项含 ['role', 'content']（统一格式）
     */
    private function load_history_from_db(int $conversationid): array {
        global $DB;
        $convmodel = new conversation_model($DB);

        // 安全检查：验证会话属于当前用户，防止 IDOR 越权读取他人对话
        $conversation = $convmodel->get_conversation($conversationid);
        if ($conversation === null || ($this->userid > 0 && (int)$conversation->userid !== $this->userid)) {
            // 会话不存在或不属于当前用户：重置 conversationid 并返回空历史
            $this->conversationid = 0;
            return [];
        }

        $msgmodel = new message_model($DB);
        $maxhistory = config_manager::get_max_history_count();
        $messages = $msgmodel->get_recent_messages($conversationid, $maxhistory);

        $history = [];
        foreach ($messages as $msg) {
            $history[] = prompt_builder::normalize_message([
                'role' => $msg->role,
                'content' => $msg->content,
            ]);
        }
        return $history;
    }

    /**
     * 获取当前对话会话 ID
     *
     * @return int
     */
    public function get_conversation_id(): int {
        return $this->conversationid;
    }

    /**
     * 创建 AI 补全（主入口方法）
     *
     * @param object $context Moodle 上下文对象
     * @param bool $skipratelimit 是否跳过速率限制检查
     * @param bool $skipfilter 是否跳过内容过滤
     * @return array 包含 result（success/error）和 message 的数组
     */
    public function create_completion($context, bool $skipratelimit = false, bool $skipfilter = false): array {
        // 速率限制检查
        if (!$skipratelimit) {
            $ratelimitresult = self::check_rate_limit();
            if ($ratelimitresult !== null) {
                return $ratelimitresult;
            }
        }

        // 应用执行时间限制
        self::apply_time_limit();

        // 内容过滤
        if (!$skipfilter) {
            $filtered = $this->apply_content_filter();
            if ($filtered !== null) {
                return $filtered;
            }
        }

        // 调用 AI 后端（direct 后端使用完整的消息数组，其他后端保持原有方式）
        $backend = $this->get_backend();
        $ai = $this->get_ai_backend();

        // 根据模式和后端类型获取提示词信息
        $buildMode = config_manager::get_config_cached('prompt_build_mode', 'original');

        // 双重模式仅支持 direct 后端，其他后端自动回退到原模式
        $effectiveBuildMode = $buildMode;
        if ($buildMode === 'dual' && $backend !== self::BACKEND_DIRECT) {
            $effectiveBuildMode = 'original';
            config_manager::debug_log('dual_mode: dual mode only supports direct backend, auto-fallback to original mode', [
                'backend' => $backend,
            ]);
        }

        // 构建提示词并生成消息数组
        if ($effectiveBuildMode === 'dual') {
            $promptinfo = $this->promptBuilder->get_dual_prompt_info(
                $this->history, $this->pagecontext, $this->actiontype,
                $this->assistantname, $this->message
            );

            // 注入知识库上下文
            $this->inject_kb_context($promptinfo, 'userprompt');

            $messages = $this->build_messages_from_prompt_info($promptinfo);
            $systemprompt = $promptinfo['systemprompt'];
            // 双重模式下 userprompt 已包含所有内容（策略+试题+历史对话+代码+当前问题），
            // 且 dual mode 仅在 direct 后端生效（非 direct 后端自动回退 original 模式），
            // 因此无需为 contextprompt/usermessage 赋值——它们仅被非 direct 后端使用。
            $contextprompt = $promptinfo['userprompt'] ?? '';
            $usermessage = '';
        } else {
            $promptinfo = $this->promptBuilder->get_prompt_info(
                $this->history, $this->pagecontext, $this->actiontype,
                $this->assistantname, $this->message
            );

            // 注入知识库上下文
            $this->inject_kb_context($promptinfo, 'contextprompt');

            $messages = $this->build_messages_from_prompt_info($promptinfo);
            $systemprompt = $promptinfo['systemprompt'];
            $contextprompt = $promptinfo['contextprompt'] ?? $promptinfo['pagecontextstr'] ?? '';
            $usermessage = $promptinfo['usermessage'] ?? $this->message;
        }
        $starttime = microtime(true);

        $result = match ($backend) {
            self::BACKEND_AI_MANAGER => $ai->create_completion_ai_manager(
                $systemprompt, $contextprompt, $usermessage, $context, []
            ),
            self::BACKEND_DIRECT => $ai->create_completion_direct_with_messages($messages),
            default => $ai->create_completion_core_ai(
                $systemprompt, $contextprompt, $usermessage, $context, [],
                $this->assistantname, $this->username
            ),
        };
        $result['responsetime'] = (int) ((microtime(true) - $starttime) * 1000);

        // 注意：AI 回复的后处理过滤（outputblocklist + 引用链接转换）已在各后端方法内部完成，
        // 此处不再重复调用 filter_ai_response，避免多次正则匹配和 DB 查询的开销。
        // 后端方法：create_completion_direct_with_messages / create_completion_core_ai /
        //          create_completion_ai_manager / handle_stream_success 均已内部过滤。

        // 调试模式下附加完整提示词（包含 messages 数组的 JSON 格式输出）
        if (config_manager::get_config_cached('debug')) {
            $result['debug_prompt'] = $this->build_debug_output($promptinfo, $messages);
        }

        $result['ai_backend'] = $backend;
        $result['actiontype'] = $this->actiontype;

        return $result;
    }

    /**
     * 检查内容过滤（拦截规则 + 输入净化）
     *
     * @return array|null 被拦截时返回结果数组，未拦截返回 null
     */
    private function apply_content_filter(): ?array {
        $filtered = prompt_filter::filter_content($this->message);
        if ($filtered !== null) {
            return [
                'result' => 'success',
                'message' => $filtered,
            ];
        }
        return null;
    }

    /**
     * 获取当前构建的提示词信息（双层次架构）
     *
     * 根据管理员配置的 prompt_build_mode 选择不同的提示词构建方式：
     * - original：原模式，使用 get_prompt_info() 返回 systemprompt + contextprompt + usermessage
     * - dual：双重模式，使用 get_dual_prompt_info() 返回 systemprompt + userprompt
     *
     * 在原有提示词基础上，注入知识库（RAG）检索结果作为参考材料。
     *
     * @return array 提示词信息数组
     */
    public function get_prompt_info(): array {
        $buildMode = config_manager::get_config_cached('prompt_build_mode', 'original');

        if ($buildMode === 'dual') {
            // 双重模式
            $info = $this->promptBuilder->get_dual_prompt_info(
                $this->history,
                $this->pagecontext,
                $this->actiontype,
                $this->assistantname,
                $this->message
            );

            // 注入知识库上下文到用户提示词
            $this->inject_kb_context($info, 'userprompt');
        } else {
            // 原模式（默认）
            $info = $this->promptBuilder->get_prompt_info(
                $this->history,
                $this->pagecontext,
                $this->actiontype,
                $this->assistantname,
                $this->message
            );

            // 注入知识库上下文到上下文提示词
            $this->inject_kb_context($info, 'contextprompt');
        }

        return $info;
    }

    /**
     * 构建流式响应所需的完整对话消息数组
     *
     * 与 create_completion() 使用相同的消息构建逻辑，
     * 确保流式和非流式模式使用一致的对话历史。
     *
     * @return array 完整的消息数组
     */
    public function build_stream_messages(): array {
        $promptinfo = $this->get_prompt_info();
        return $this->build_messages_from_prompt_info($promptinfo);
    }

    /**
     * 从提示词信息构建完整的对话消息数组（公共实现）
     *
     * 统一处理 create_completion() 和 build_stream_messages() 中的消息构建逻辑。
     * 根据 promptinfo 的结构自动判断使用原模式或双重模式。
     *
     * @param array $promptinfo 提示词信息数组
     * @return array 完整的对话消息数组
     */
    private function build_messages_from_prompt_info(array $promptinfo): array {
        $systemprompt = $promptinfo['systemprompt'];

        // 判断是否为双重模式（存在 userprompt 字段）
        $isDualMode = isset($promptinfo['userprompt']);

        if ($isDualMode) {
            // 双重模式：
            // - 所有历史对话（第一轮、中间摘要、最后N轮）都已嵌入 UserPrompt
            // - 消息结构简化为两条：system → user
            // - 跳过 compress_conversation_messages()，避免双重压缩
            $userprompt = $promptinfo['userprompt'];
            return $this->promptBuilder->build_dual_conversation_messages(
                $systemprompt, $userprompt
            );
        } else {
            // 原模式：保留 firstround 和 recenthistory 作为独立消息
            $recenthistory = $promptinfo['recenthistory'] ?? [];
            $firstround = $promptinfo['firstround'] ?? [];
            $contextprompt = $promptinfo['contextprompt'] ?? $promptinfo['pagecontextstr'];
            $usermessage = $promptinfo['usermessage'] ?? $this->message;
            $messages = $this->promptBuilder->build_conversation_messages(
                $systemprompt, $contextprompt, $usermessage,
                $recenthistory, $firstround, $this->assistantname
            );

            // 原模式下需要压缩历史消息，防止超出 token 限制
            return $this->promptBuilder->compress_conversation_messages($messages);
        }
    }

    /**
     * 通过流式输出获取 AI 反馈（使用完整消息数组）
     *
     * @param array $messages 完整的消息数组
     * @param callable $on_complete 流式完成时回调
     * @param callable $on_error 出错时回调
     */
    public function stream_create_completion_direct_with_messages(
        array $messages,
        callable $on_complete,
        callable $on_error
    ): void {
        $this->get_ai_backend()->stream_create_completion_direct_with_messages(
            $messages, $on_complete, $on_error
        );
    }

    // ===================== 简单委托方法和静态工具 =====================

    /**
     * 注入知识库上下文到提示词信息中
     *
     * 消除 create_completion() 和 get_prompt_info() 中 dual/original 模式各 2 处的重复 KB 注入代码。
     *
     * @param array &$promptinfo 提示词信息数组（引用传递，直接修改）
     * @param string $targetkey 目标键名：dual 模式为 'userprompt'，original 模式为 'contextprompt'
     */
    private function inject_kb_context(array &$promptinfo, string $targetkey): void {
        if (!kb_service::is_enabled()) {
            return;
        }
        $kbcontext = kb_service::format_kb_context(
            $this->message,
            $this->pagecontext['questiontext'] ?? ''
        );
        if (!empty($kbcontext)) {
            $promptinfo[$targetkey] = $kbcontext . "\n\n" . ($promptinfo[$targetkey] ?? '');
        }
    }

    /**
     * 获取配置的 AI 后端类型
     * @return string 后端类型标识符
     */
    private function get_backend(): string {
        $backend = config_manager::get_config_cached('ai_backend');
        if (empty($backend)) {
            $backend = self::BACKEND_CORE_AI;
        }
        return $backend;
    }

    /**
     * 检查速率限制（委托给 rate_limiter）
     *
     * @return array|null 超过限制时返回错误数组，否则返回 null
     */
    public static function check_rate_limit(): ?array {
        return rate_limiter::check_rate_limit();
    }

    /**
     * 构建调试输出（用于调试模式下展示发送给 AI 的完整提示词）
     *
     * 统一生成调试信息，包含：
     * - 分解的提示词字段（systemprompt、userprompt、contextprompt、usermessage）
     * - 完整的 $messages 数组（JSON格式），这是实际发送给 AI API 的内容
     *
     * @param array $promptinfo 提示词信息数组
     * @param array $messages 完整的消息数组（发送给 AI API 的内容）
     * @return string 格式化的调试输出
     */
    public function build_debug_output(array $promptinfo, array $messages): string {
        $buildMode = config_manager::get_config_cached('prompt_build_mode', 'original');
        $output = '';

        if ($buildMode === 'dual') {
            // 双重模式：只有 system + user 两条消息，所有历史嵌入 userprompt
            $output .= "===== SYSTEM PROMPT =====\n\n";
            $output .= ($promptinfo['systemprompt'] ?? '') . "\n\n";

            // UserPrompt（策略 + 试题 + 历史对话 + 代码 + 当前问题）
            $output .= "===== USER PROMPT (策略+试题+历史对话+代码+当前问题) =====\n\n";
            $output .= ($promptinfo['userprompt'] ?? '') . "\n\n";
        } else {
            // 原模式：systemprompt + contextprompt + usermessage 结构
            $output .= "===== SYSTEM PROMPT =====\n\n";
            $output .= ($promptinfo['systemprompt'] ?? '') . "\n\n";
            $output .= "===== CONTEXT PROMPT =====\n\n";
            $output .= ($promptinfo['contextprompt'] ?? $promptinfo['pagecontextstr'] ?? '') . "\n\n";
            $output .= "===== USER MESSAGE =====\n\n";
            $output .= ($promptinfo['usermessage'] ?? $this->message) . "\n\n";
        }

        // 输出完整的 messages 数组（JSON格式），这是实际发送给 AI API 的内容
        $output .= "===== MESSAGES ARRAY (sent to AI) =====\n";
        $output .= json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

        // 附加统计信息（消息可能含多种格式，统一用 normalize_message 提取内容）
        $totalChars = 0;
        $messageCount = count($messages);
        $roles = prompt_builder::extract_roles($messages);
        foreach ($messages as $msg) {
            $normalized = prompt_builder::normalize_message($msg);
            $totalChars += mb_strlen($normalized['content'], 'UTF-8');
        }
        $estimatedTokens = (int)ceil($totalChars / 4);
        $output .= "===== STATS =====\n";
        $output .= "Messages count: {$messageCount}\n";
        $output .= "Roles: " . implode(', ', $roles) . "\n";
        $output .= "Total chars: {$totalChars}\n";
        $output .= "Estimated tokens (chars/4): {$estimatedTokens}\n";
        $output .= "\n";

        return $output;
    }

    /**
     * 应用 PHP 最大执行时间限制（委托给 config_manager）
     */
    private static function apply_time_limit(): void {
        if (self::$timelimitapplied) {
            return;
        }
        self::$timelimitapplied = true;
        config_manager::apply_time_limit();
    }
}