<?php
/**
 * AI 编程助手 - 钩子回调处理
 *
 * 通过 Moodle 的 Hook 机制，在页面 footer 生成前注入 AI 助手的
 * HTML 模板和 JavaScript 初始化代码。
 *
 * @author zfl
 * @package    local_ai_programming_assistant
 */
namespace local_ai_programming_assistant;

use core\hook\output\before_footer_html_generation;
use core\hook\output\before_standard_head_html_generation;

defined('MOODLE_INTERNAL') || die();

class hook_callbacks {

    /** @var bool|null 请求级缓存：是否应该显示助手 */
    private static ?bool $showassistant = null;

    /** @var array|null 请求级缓存：允许的页面类型列表 */
    private static ?array $allowedpagetypes = null;

    /** 允许显示助手的页面类型前缀列表（默认值，配置为空时回退） */
    private const ALLOWED_PAGE_TYPES = [
        'mod-quiz-attempt',
        'mod-quiz-review',
        'mod-quiz-summary',
        'mod-assign',
        'mod-lesson',
        'mod-page',
    ];

    /**
     * 在 HTML head 生成前注册 CSS 样式
     *
     * @param before_standard_head_html_generation $hook
     */
    public static function before_standard_head_html_generation(before_standard_head_html_generation $hook): void {
        if (!self::should_show_assistant()) {
            return;
        }

        global $PAGE;
        $PAGE->requires->css('/local/ai_programming_assistant/styles.css');
    }

    /**
     * 在页面 footer 生成前注入 AI 助手界面
     *
     * @param before_footer_html_generation $hook
     */
    public static function before_footer_html_generation(before_footer_html_generation $hook): void {
        if (!self::should_show_assistant()) {
            return;
        }

        global $PAGE, $USER;

        $pluginconfig = config_manager::get_plugin_config();
        $assistantname = $pluginconfig->assistantname ?? get_string('assistantname', 'local_ai_programming_assistant');

        $quizid = 0;
        $attemptid = 0;
        $cm = $PAGE->cm;
        if ($cm && $cm->modname === 'quiz') {
            $quizid = (int)$cm->instance;
        }
        $pagetype = $PAGE->pagetype;
        if (str_starts_with($pagetype, 'mod-quiz')) {
            $attemptid = (int)optional_param('attempt', 0, PARAM_INT);
        }

        // 将所有配置数据放入 JSON，通过 DOM data-config 属性传递给前端
        // 避免 js_call_amd 参数超限（超过 1024 字符会产生 Moodle 警告）
        $config = [
            'userName' => $USER->username,
            'assistantName' => $assistantname,
            'contextid' => $PAGE->context->id,
            'pagetype' => $PAGE->pagetype,
            'quizid' => $quizid,
            'attemptid' => $attemptid,
            'debug' => !empty($pluginconfig->debug),
            'enableThinking' => !empty($pluginconfig->enable_thinking),
            'enableNonCodeRunner' => !empty($pluginconfig->enable_non_coderunner),
            'responsemode' => $pluginconfig->responsemode ?? 'streaming',
            'maxHistoryCount' => \local_ai_programming_assistant\config_manager::get_max_history_count(),
            'strNoQuestions' => get_string('noquestionsfound', 'local_ai_programming_assistant'),
            'strAllEmbeddedQuestions' => get_string('all_embedded_questions', 'local_ai_programming_assistant'),
            'strDebugPromptLabel' => get_string('debug_prompt_label', 'local_ai_programming_assistant'),
            'strDefaultQuestionAnalysisMsg' => get_string('default_question_analysis_msg', 'local_ai_programming_assistant'),
            'strDefaultIdeaOrganizationMsg' => get_string('default_idea_organization_msg', 'local_ai_programming_assistant'),
            'strDefaultCodeDebuggingMsg' => get_string('default_code_debugging_msg', 'local_ai_programming_assistant'),
            'strAiSender' => get_string('ai_sender', 'local_ai_programming_assistant'),
            'strMe' => get_string('me', 'local_ai_programming_assistant'),
            'strCopy' => get_string('copy', 'local_ai_programming_assistant'),
            'strCopied' => get_string('copied', 'local_ai_programming_assistant'),
            'strRetry' => get_string('retry', 'local_ai_programming_assistant'),
            'strExpand' => get_string('expand', 'local_ai_programming_assistant'),
            'strCollapse' => get_string('collapse', 'local_ai_programming_assistant'),
            'strAiFeedbackLoading' => get_string('ai_feedback_loading', 'local_ai_programming_assistant'),
            'strRequestError' => get_string('request_error', 'local_ai_programming_assistant'),
            'strErrorOccurred' => get_string('erroroccurred', 'local_ai_programming_assistant'),
        ];
        // 使用 htmlspecialchars 编码 JSON 以便安全嵌入 HTML data 属性
        // 在 Mustache 模板中用 {{{ }}} 三括号避免重复转义
        $configjson = json_encode($config, JSON_UNESCAPED_UNICODE);
        if ($configjson === false) {
            // 输入含非法 UTF-8 字节时 json_encode 返回 false，记录明确错误便于诊断，
            // 并回退为 {} 让前端走默认配置，避免 data-config 为空导致界面异常
            debugging('[ai_programming_assistant] hook_callbacks: config json_encode 失败: '
                . json_last_error_msg(), DEBUG_DEVELOPER);
            $configjson = '{}';
        }
        $configjson = htmlspecialchars($configjson, ENT_QUOTES, 'UTF-8');

        // js_call_amd 传非空参数确保 Moodle 生成 amd.init(true) 而非 amd.init()
        // 所有配置数据从 DOM data-config 读取（放在模板 HTML 中）
        $PAGE->requires->js_call_amd('local_ai_programming_assistant/lib', 'init', [true]);

        $renderer = $PAGE->get_renderer('core');
        $html = $renderer->render_from_template('local_ai_programming_assistant/assistant', [
            'assistant_name' => $assistantname,
            'user_name' => $USER->username,
            'config_json' => $configjson,
        ]);

        $hook->add_html($html);
    }

    /**
     * 获取允许显示助手的页面类型列表
     *
     * 从管理员配置 allowed_page_types 读取（每行一个页面类型前缀），
     * 解析时过滤空行和 # 注释行。若配置为空则回退到 ALLOWED_PAGE_TYPES 默认值。
     * 结果在请求级别缓存。
     *
     * @return array
     */
    private static function get_allowed_page_types(): array {
        if (self::$allowedpagetypes !== null) {
            return self::$allowedpagetypes;
        }

        $raw = config_manager::get_config_cached('allowed_page_types', '');
        $types = [];
        if ($raw !== '') {
            $lines = preg_split('/\r\n|\r|\n/', $raw);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                $types[] = $line;
            }
        }

        if (empty($types)) {
            $types = self::ALLOWED_PAGE_TYPES;
        }

        self::$allowedpagetypes = $types;
        return $types;
    }

    /**
     * 判断当前页面是否应该显示 AI 助手
     *
     * @return bool
     */
    private static function should_show_assistant(): bool {
        if (self::$showassistant !== null) {
            return self::$showassistant;
        }

        global $PAGE;

        // 防御纵深：仅对已登录用户注入面板。
        // has_capability 对 guest 通常也返回 false 作为兜底，但先做 isloggedin()
        // 检查可避免 before_footer_html_generation 中 $USER->username 暴露给访客，
        // 同时防止登录页/找回密码页等公开场景意外通过页面类型白名单。
        if (!isloggedin() || isguestuser()) {
            self::$showassistant = false;
            return false;
        }

        // 复用 before_footer_html_generation 中已加载的配置
        $pluginconfig = config_manager::get_plugin_config();

        if (empty($pluginconfig->enable)) {
            self::$showassistant = false;
            return false;
        }

        $pagetype = $PAGE->pagetype;

        $isquizpage = str_starts_with($pagetype, 'mod-quiz');
        $nonquizenabled = !empty($pluginconfig->enable_non_quiz_pages);

        if (!$isquizpage && !$nonquizenabled) {
            self::$showassistant = false;
            return false;
        }

        $allowed = false;
        foreach (self::get_allowed_page_types() as $type) {
            if (str_starts_with($pagetype, $type)) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            self::$showassistant = false;
            return false;
        }

        $context = $PAGE->context;
        if (!$context) {
            self::$showassistant = false;
            return false;
        }

        $capability = 'local/ai_programming_assistant:use';
        if (!has_capability($capability, $context)) {
            self::$showassistant = false;
            return false;
        }

        self::$showassistant = true;
        return true;
    }
}