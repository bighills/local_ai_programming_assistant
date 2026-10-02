<?php
/**
 * AI 编程助手 - 核心管理器
 *
 * 统一管理 AI 对话的核心逻辑，参考 block_ai_chat 的 manager 设计模式。
 * 封装内容过滤、AI 调用、日志记录等逻辑，提供统一的接口供外部 API 和流式控制器调用。
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */
namespace local_ai_programming_assistant;

defined('MOODLE_INTERNAL') || die();

class manager {

    /** @var \context Moodle 上下文对象 */
    private \context $context;

    /** @var int 用户 ID */
    private int $userid;

    /** @var string 组件名称 */
    private string $component;

    /** @var array 请求参数 */
    private array $params;

    /** @var array 解码后的对话历史 */
    private array $history;

    /** @var string AI 后端类型 */
    private string $backend;

    /** @var bool 日志是否启用 */
    private bool $loggingenabled;

    /** @var int 对话会话 ID */
    private int $conversationid = 0;

    /** @var array|null 请求级页面上下文缓存 */
    private ?array $pagecontext = null;

    /** @var array|null 请求级日志上下文缓存 */
    private ?array $logcontext = null;

    /**
     * 构造函数
     *
     * @param \context $context Moodle 上下文对象
     * @param int $userid 用户 ID
     * @param array $params 请求参数
     * @param array $history 解码后的对话历史
     * @param string $component 组件名称
     */
    public function __construct(\context $context, int $userid, array $params, array $history, string $component = 'local_ai_programming_assistant') {
        $this->context = $context;
        $this->userid = $userid;
        $this->params = $params;
        $this->history = $history;
        $this->component = $component;

        $pluginconfig = config_manager::get_plugin_config();
        $this->backend = !empty($pluginconfig->ai_backend) ? $pluginconfig->ai_backend : 'core_ai';
        $this->loggingenabled = !empty($pluginconfig->logging);

        $this->conversationid = $params['conversationid'] ?? 0;
    }

    /**
     * 执行 AI 请求（阻塞模式）
     *
     * @return array 包含 success、message、error 等字段的结果数组
     */
    public function request_ai(): array {
        $message = $this->params['message'];

        // 内容过滤（filter_content 通过引用修改 $message 执行输入净化）
        $filtered = prompt_filter::filter_content($message);
        if ($filtered !== null) {
            return [
                'success' => true,
                'message' => $filtered,
                'conversationid' => $this->conversationid,
            ];
        }
        // 将净化后的消息写回 params，确保后续 AI 请求使用已净化的内容
        $this->params['message'] = $message;

        // 调用 AI
        $result = $this->execute_ai_request();

        return $result;
    }

    /**
     * 执行 AI 请求（流式模式）
     *
     * @param callable $on_complete 完成回调
     * @param callable $on_error 错误回调
     * @param callable|null $on_metadata 可选的知识库元数据回调，在流式开始前调用（参数为 fileid→filename 映射数组）
     */
    public function stream_request_ai(callable $on_complete, callable $on_error, ?callable $on_metadata = null): void {
        $message = $this->params['message'];

        // 内容过滤（filter_content 通过引用修改 $message 执行输入净化）
        $filtered = prompt_filter::filter_content($message);
        if ($filtered !== null) {
            $on_complete([
                'success' => true,
                'message' => $filtered,
                'conversationid' => $this->conversationid,
            ]);
            return;
        }
        // 将净化后的消息写回 params，确保后续 AI 请求使用已净化的内容
        $this->params['message'] = $message;

        // 执行流式 AI 请求
        $this->execute_stream_ai_request($on_complete, $on_error, $on_metadata);
    }

    /**
     * 构建页面上下文（请求级缓存，避免重复构建）
     */
    private function build_page_context(): array {
        if ($this->pagecontext !== null) {
            return $this->pagecontext;
        }
        $questiontext = (string)($this->params['questiontext'] ?? '');
        // 前端未提取到题目文本时（mod/page 等无 .que DOM 的页面），
        // 尝试从页面内容中提取 tiny_embedquestion 嵌入题作为题目上下文。
        if (trim($questiontext) === '') {
            $questiontext = embedded_questions::extract_questiontext($this->context);
        }
        $this->pagecontext = [
            'questiontext' => $questiontext,
            'studentanswer' => $this->params['studentanswer'] ?? '',
            'testresults' => $this->params['testresults'] ?? '',
            'programminglanguage' => $this->params['programminglanguage'] ?? '',
            'actiontype' => $this->params['actiontype'] ?? 'free',
            'questionid' => (int)($this->params['questionid'] ?? 0),
            'quizid' => (int)($this->params['quizid'] ?? 0),
        ];
        return $this->pagecontext;
    }

    /**
     * 执行 AI 请求（阻塞模式）
     */
    private function execute_ai_request(): array {
        $message = $this->params['message'];
        $pagecontext = $this->build_page_context();

        $completion = new completion($message, $this->history, $pagecontext, $this->conversationid, $this->userid);
        $this->conversationid = $completion->get_conversation_id();

        $starttime = microtime(true);
        
        try {
            $result = $completion->create_completion($this->context, false, true);
        } catch (\Throwable $e) {
            $responsetime = (int)((microtime(true) - $starttime) * 1000);
            config_manager::debug_log('execute_ai_request: exception caught', [
                'msg' => $e->getMessage(),
                'class' => get_class($e),
            ]);
            
            $this->log_result([
                'airesponse' => '',
                'model' => '',
                'ai_backend' => $this->backend,
                'success' => false,
                'errormessage' => $e->getMessage(),
                'responsetime' => $responsetime,
            ]);
            
            return [
                'success' => false,
                'message' => '',
                'error' => get_string('error_service_unavailable', 'local_ai_programming_assistant'),
                'conversationid' => $this->conversationid,
            ];
        }

        // 速率限制命中：直接返回友好的限流提示。
        // 不经过 map_user_facing_error 降级（否则限流消息被替换为通用错误），
        // 也不记入失败统计（与 stream_message.php 流式入口的限流处理行为一致：
        // 限流是客户端节流，非 AI 服务失败，不应污染成功率统计）。
        if (($result['result'] ?? '') === 'error' && !empty($result['ratelimited'])) {
            return [
                'success' => false,
                'message' => '',
                'error' => $result['message'],
                'conversationid' => $this->conversationid,
            ];
        }
        
        $result['responsetime'] = (int)((microtime(true) - $starttime) * 1000);

        $issuccess = ($result['result'] ?? '') === 'success';

        if (!$issuccess) {
            // 记录失败详情到服务端日志
            config_manager::debug_log('execute_ai_request: AI request failed', [
                'message' => $result['message'] ?? 'unknown error',
                'model' => $result['model'] ?? '',
                'response_time_ms' => $result['responsetime'],
            ]);

            $this->log_result([
                'airesponse' => '',
                'model' => $result['model'] ?? '',
                'ai_backend' => $this->backend,
                'success' => false,
                'errormessage' => $result['message'] ?? '',
                'responsetime' => $result['responsetime'],
            ]);

            // 对用户友好的错误消息：
            // AI 后端返回的 $result['message'] 可能包含异常堆栈、SQL 片段、
            // API 原始响应等敏感信息，不能直接返回前端。
            // 仅对已知的友好错误关键词做映射，其余统一返回通用错误消息。
            $userError = self::map_user_facing_error($result['message'] ?? '');

            return [
                'success' => false,
                'message' => '',
                'error' => $userError,
                'conversationid' => $this->conversationid,
            ];
        }

        // 日志记录（同时写入 conversation + message 表）
        $finalconversationid = $this->log_result([
            'airesponse' => $result['message'],
            'model' => $result['model'] ?? '',
            'ai_backend' => $this->backend,
            'success' => true,
            'responsetime' => $result['responsetime'],
        ]);

        $debugprompt = '';
        if (config_manager::get_config_cached('debug')) {
            $promptinfo = $completion->get_prompt_info();
            $messages = $completion->build_stream_messages();
            $debugprompt = $completion->build_debug_output($promptinfo, $messages);
        }

        return [
            'success' => true,
            'message' => $result['message'],
            'debug_prompt' => $debugprompt,
            'conversationid' => $finalconversationid,
        ];
    }

    /**
     * 执行流式 AI 请求
     *
     * @param callable $on_complete 完成回调（外层 stream_controller 的 SSE 发送 handler）
     * @param callable $on_error 错误回调（外层 stream_controller 的 SSE 错误 handler）
     * @param callable|null $on_metadata 可选的知识库元数据回调
     */
    private function execute_stream_ai_request(callable $on_complete, callable $on_error, ?callable $on_metadata = null): void {
        $message = $this->params['message'];
        $pagecontext = $this->build_page_context();

        $completion = new completion($message, $this->history, $pagecontext, $this->conversationid, $this->userid);
        $this->conversationid = $completion->get_conversation_id();

        $logfn = $this->build_log_function();
        $innerSuccess = $this->build_stream_success_handler($logfn);
        $innerError = $this->build_stream_error_handler($logfn);

        // 串联回调：先执行内部日志 handler，再将结果（含 conversationid）传递给外层 SSE handler
        $chainedSuccess = function($response) use ($innerSuccess, $on_complete) {
            $processed = $innerSuccess($response);
            // innerSuccess 返回包含 conversationid 和 success 标记的完整结果
            $finalResponse = is_array($processed) ? $processed : $response;
            $on_complete($finalResponse);
        };
        $chainedError = function($error) use ($innerError, $on_error) {
            $processed = $innerError($error);
            $on_error(is_array($processed) ? ($processed['error'] ?? $error) : $error);
        };

        if ($this->backend === 'direct') {
            try {
                $messages = $completion->build_stream_messages();

                // 调试模式下记录发送给 AI 的完整提示词
                if (config_manager::get_config_cached('debug')) {
                    $promptinfo = $completion->get_prompt_info();
                    $debugOutput = $completion->build_debug_output($promptinfo, $messages);
                    config_manager::debug_log('stream_ai_request: debug prompt sent to AI', [
                        'debug_prompt' => $debugOutput,
                    ]);
                }

                // 发送知识库元数据（fileid→filename 映射），供前端流式渲染生成准确链接
                if ($on_metadata !== null) {
                    $kbresults = kb_service::get_last_search_results();
                    if (!empty($kbresults)) {
                        $files = [];
                        foreach ($kbresults as $result) {
                            if (!empty($result['filename']) && !empty($result['fileid'])) {
                                $files[$result['filename']] = (int)$result['fileid'];
                            }
                        }
                        if (!empty($files)) {
                            $on_metadata($files);
                        }
                    }
                }

                $completion->stream_create_completion_direct_with_messages(
                    $messages,
                    $chainedSuccess,
                    $chainedError
                );
            } catch (\Throwable $e) {
                error_log('[ai_programming_assistant] stream direct error: ' . $e->getMessage());
                $chainedError($e->getMessage());
            }
        } else {
            $this->execute_blocking_stream($completion, $chainedSuccess, $chainedError);
        }
    }

    /**
     * 阻塞模式的流式执行
     */
    private function execute_blocking_stream(completion $completion, callable $onsuccess, callable $onerror): void {
        ignore_user_abort(true);

        $starttime = microtime(true);
        try {
            $result = $completion->create_completion($this->context, true, true);
            $elapsed = (int)((microtime(true) - $starttime) * 1000);
            $result['responsetime'] = $elapsed;

            if (($result['result'] ?? '') === 'success') {
                $response = [
                    'message' => $result['message'] ?? '',
                    'model' => $result['model'] ?? '',
                    'thinking_content' => $result['thinking_content'] ?? '',
                    'finish_reason' => $result['finish_reason'] ?? 'stop',
                    'truncated' => $result['truncated'] ?? false,
                    'multiturn' => $result['multiturn'] ?? false,
                    'responsetime' => $elapsed,
                ];
                // 调用 onsuccess（内部会记录日志并附加 conversationid，再将结果传递给外层 SSE handler）
                $onsuccess($response);
            } else {
                $onerror($result['message'] ?? get_string('erroroccurred', 'local_ai_programming_assistant'));
            }
        } catch (\Throwable $e) {
            error_log('[ai_programming_assistant] execute_blocking_stream error: ' . $e->getMessage());
            $onerror($e->getMessage());
        }
    }

    /**
     * 构建流式成功回调
     */
    private function build_stream_success_handler(callable $logfn): callable {
        return function($response) use ($logfn) {
            // 注意：AI 回复的 filter_ai_response 已在后端方法内部完成：
            // - 流式 direct：handle_stream_success 已过滤
            // - 阻塞流式：create_completion → 后端方法已过滤
            // 此处不再重复调用，避免多次正则匹配和 DB 查询开销。

            $responsetime = (int)($response['responsetime'] ?? 0);

            $finalconversationid = $logfn([
                'airesponse' => $response['message'],
                'model' => $response['model'] ?? '',
                'success' => true,
                'responsetime' => $responsetime,
            ]);

            return array_merge($response, [
                'success' => true,
                'conversationid' => $finalconversationid,
            ]);
        };
    }

    /**
     * 构建流式错误回调
     */
    private function build_stream_error_handler(callable $logfn): callable {
        return function($error) use ($logfn) {
            $rawerror = is_string($error) ? $error : '';
            $logfn(['success' => false, 'errormessage' => mb_substr($rawerror, 0, 1000, 'UTF-8')]);
            return [
                'success' => false,
                'error' => self::map_user_facing_error($rawerror),
            ];
        };
    }

    /**
     * 将 AI 后端原始错误消息映射为用户友好的分类提示
     *
     * @param string $rawError 原始错误消息（可能含敏感信息）
     * @return string 用户友好的错误消息
     */
    private static function map_user_facing_error(string $rawError): string {
        if ($rawError === '') {
            return get_string('error_ai_request_failed', 'local_ai_programming_assistant');
        }
        if (strpos($rawError, 'json.exception.parse_error') !== false
            || strpos($rawError, 'empty input') !== false) {
            return get_string('error_empty_request_body', 'local_ai_programming_assistant');
        }
        if (strpos($rawError, 'error_config_incomplete') !== false
            || strpos($rawError, get_string('error_config_incomplete', 'local_ai_programming_assistant')) !== false) {
            return get_string('error_config_incomplete', 'local_ai_programming_assistant');
        }
        return get_string('error_ai_request_failed', 'local_ai_programming_assistant');
    }

    /**
     * 构建日志记录闭包
     */
    private function build_log_function(): callable {
        $logcontext = $this->build_log_context();

        return function(array $extras) use ($logcontext) {
            $entry = log_entry::from_array(array_merge($logcontext, $extras));
            return feedback_repository::try_log($logcontext['loggingenabled'], $entry);
        };
    }

    /**
     * 记录日志（写入 conversation + message 表）
     */
    private function log_result(array $extras): int {
        $logcontext = $this->build_log_context();
        $entry = log_entry::from_array(array_merge($logcontext, $extras));

        return feedback_repository::try_log($this->loggingenabled, $entry);
    }

    /**
     * 构建日志公共上下文（请求级缓存，避免重复解析课程信息）
     */
    private function build_log_context(): array {
        if ($this->logcontext !== null) {
            // 仅更新会话 ID（可能在 AI 调用后变化）
            $this->logcontext['conversationid'] = $this->conversationid;
            return $this->logcontext;
        }

        // 解析课程信息，解析失败时使用安全默认值
        try {
            $courseinfo = helper::resolve_course_and_cm_from_context($this->context);
        } catch (\Throwable $e) {
            config_manager::debug_log('build_log_context: resolve_course failed', [
                'msg' => $e->getMessage(),
            ]);
            $courseinfo = ['courseid' => 0, 'cmid' => 0];
        }

        $this->logcontext = [
            'loggingenabled' => $this->loggingenabled,
            'userid' => $this->userid,
            'usermessage' => $this->params['message'] ?? '',
            'questionid' => $this->params['questionid'] ?? 0,
            'actiontype' => $this->params['actiontype'] ?? 'free',
            'ai_backend' => $this->backend,
            'quizid' => $this->params['quizid'] ?? 0,
            'attemptid' => $this->params['attemptid'] ?? 0,
            'usageid' => $this->params['usageid'] ?? 0,
            'slot' => $this->params['slot'] ?? 0,
            'studentanswer' => $this->params['studentanswer'] ?? '',
            'contextid' => $this->params['contextid'] ?? 0,
            'courseid' => $courseinfo['courseid'],
            'cmid' => $courseinfo['cmid'],
            'pagetype' => $this->params['pagetype'] ?? '',
            'conversationid' => $this->conversationid,
        ];
        return $this->logcontext;
    }
}