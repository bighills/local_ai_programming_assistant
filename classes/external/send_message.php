<?php
/**
 * AI 编程助手 - 外部 Web 服务接口
 *
 * 定义 AJAX 可调用的外部服务方法，用于接收前端发送的消息并返回 AI 回复。
 * 遵循 Moodle 外部 API 规范，包含参数验证和返回值定义。
 *
 * @author zfl
 * @package    local_ai_programming_assistant
 */
namespace local_ai_programming_assistant\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_ai_programming_assistant\config_manager;

class send_message extends external_api {

    /**
     * 定义 execute 方法的参数结构
     * @return external_function_parameters 参数定义
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'message' => new external_value(PARAM_RAW, 'The user message'),
            'history' => new external_value(PARAM_RAW, 'JSON encoded conversation history', VALUE_DEFAULT, '[]'),
            'questiontext' => new external_value(PARAM_RAW, 'Question text from the page', VALUE_DEFAULT, ''),
            'studentanswer' => new external_value(PARAM_RAW, 'Student code/answer', VALUE_DEFAULT, ''),
            'testresults' => new external_value(PARAM_RAW, 'Test results text', VALUE_DEFAULT, ''),
            'programminglanguage' => new external_value(PARAM_RAW, 'Programming language', VALUE_DEFAULT, ''),
            'contextid' => new external_value(PARAM_INT, 'Context ID', VALUE_DEFAULT, 0),
            'actiontype' => new external_value(PARAM_ALPHAEXT, 'Action type: free, question_analysis, idea_organization, code_debugging', VALUE_DEFAULT, 'free'),
            'questionid' => new external_value(PARAM_INT, 'Question ID for session cache', VALUE_DEFAULT, 0),
            'usageid' => new external_value(PARAM_INT, 'Question usage ID (question_attempt.id) for question ID resolution', VALUE_DEFAULT, 0),
            'slot' => new external_value(PARAM_INT, 'Question slot number', VALUE_DEFAULT, 0),
            'quizid' => new external_value(PARAM_INT, 'Quiz instance ID for logging', VALUE_DEFAULT, 0),
            'attemptid' => new external_value(PARAM_INT, 'Quiz attempt ID for logging', VALUE_DEFAULT, 0),
            'pagetype' => new external_value(PARAM_ALPHANUMEXT, 'Page type (e.g. mod-quiz-attempt)', VALUE_DEFAULT, ''),
            'conversationid' => new external_value(PARAM_INT, 'Conversation session ID', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * 定义 execute 方法的返回值结构
     * @return external_single_structure 返回值定义
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the request was successful'),
            'message' => new external_value(PARAM_RAW, 'The AI response text'),
            'error' => new external_value(PARAM_RAW, 'Error message if failed', VALUE_DEFAULT, ''),
            'debug_prompt' => new external_value(PARAM_RAW, 'Full prompt sent to AI (debug mode only)', VALUE_DEFAULT, ''),
            'conversationid' => new external_value(PARAM_INT, 'Conversation session ID', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * 执行发送消息的外部服务方法
     *
     * 验证参数 → 构建请求 → 委托给 manager 类处理 → 返回结果
     *
     * @param string $message 用户消息
     * @param string $history JSON 编码的对话历史
     * @param string $questiontext 页面中的题目文本
     * @param string $studentanswer 学生代码/答案
     * @param string $testresults 测试结果文本
     * @param string $programminglanguage 编程语言
     * @param int    $contextid Moodle 上下文 ID
     * @param string $actiontype 操作类型
     * @param int    $questionid 题目 ID
     * @param int    $usageid 试题用法 ID
     * @param int    $slot 试题插槽号
     * @param int    $quizid 测验实例 ID
     * @param int    $attemptid 测验尝试 ID
     * @param string $pagetype 页面类型
     * @param int    $conversationid 对话会话 ID
     * @return array 包含 success、message、error、debug_prompt 的数组
     */
    public static function execute($message, $history = '[]', $questiontext = '', $studentanswer = '',
            $testresults = '', $programminglanguage = '', $contextid = 0, $actiontype = 'free',
            $questionid = 0, $usageid = 0, $slot = 0, $quizid = 0, $attemptid = 0, $pagetype = '', $conversationid = 0) {

        try {
            global $USER;

            // 验证并清理参数
            $params = self::validate_parameters(self::execute_parameters(), [
                'message' => $message,
                'history' => $history,
                'questiontext' => $questiontext,
                'studentanswer' => $studentanswer,
                'testresults' => $testresults,
                'programminglanguage' => $programminglanguage,
                'contextid' => $contextid,
                'actiontype' => $actiontype,
                'questionid' => $questionid,
                'usageid' => $usageid,
                'slot' => $slot,
                'quizid' => $quizid,
                'attemptid' => $attemptid,
                'pagetype' => $pagetype,
                'conversationid' => $conversationid,
            ]);

            $context = \local_ai_programming_assistant\helper::resolve_context_from_id($params['contextid']);
            self::validate_context($context);
            require_capability('local/ai_programming_assistant:use', $context);

            // 插件启用检查：与 stream_message.php 保持一致，管理员禁用插件后
            // 阻塞模式入口也必须拒绝请求，防止配置被绕过。
            $pluginconfig = \local_ai_programming_assistant\config_manager::get_plugin_config();
            if (empty($pluginconfig->enable)) {
                return [
                    'success' => false,
                    'message' => '',
                    'error' => get_string('error_not_enabled', 'local_ai_programming_assistant'),
                ];
            }

            // 响应模式检查：阻塞模式 API 仅在 responsemode=blocking 时可用。
            // 若管理员切换到 streaming 模式，前端会调用 stream_message.php 而非此 API；
            // 此检查防止缓存的前端 JS 或手动调用绕过管理员配置。
            if (($pluginconfig->responsemode ?? 'streaming') !== 'blocking') {
                return [
                    'success' => false,
                    'message' => '',
                    'error' => get_string('error_not_blocking_mode', 'local_ai_programming_assistant'),
                ];
            }

            // 解码 JSON 对话历史
            $historyDecoded = \local_ai_programming_assistant\request_handler::decode_history($params['history']);

            // 创建 manager 实例并执行 AI 请求
            $manager = new \local_ai_programming_assistant\manager(
                $context,
                (int)$USER->id,
                $params,
                $historyDecoded
            );

            // 认证/权限/限流检查已完成，AI 请求最长可达 180 秒（CURLOPT_TIMEOUT）。
            // 释放会话锁，避免同用户的其他 AJAX/页面请求在此阻塞排队；
            // 与流式路径 stream_handler::setup_sse_headers() 的 write_close() 行为对齐。
            // 注意：write_close() 之后不可再写 $SESSION。
            \core\session\manager::write_close();

            $response = $manager->request_ai();

            // Moodle 5.0 validate_param 对所有类型执行严格相等性检查（(string)$param !== (string)$cleaned 时抛出异常）
            // PARAM_RAW 的 clean() 调用 fix_utf8()。AI 回复可能包含无效 UTF-8 字符导致 fix_utf8 修改字符串，
            // 触发相等性检查失败抛出 invalid_parameter_exception → invalid_response_exception。
            // 此处预清洗所有 PARAM_RAW 字段，保证通过后续验证。
            $cleanmessage = fix_utf8($response['message'] ?? '');
            $cleanerror = fix_utf8($response['error'] ?? '');
            $cleandebug = fix_utf8($response['debug_prompt'] ?? '');
            $returnvalue = [
                'success' => $response['success'],
                'message' => $cleanmessage,
                'error' => $cleanerror,
                'debug_prompt' => $cleandebug,
                'conversationid' => $response['conversationid'] ?? 0,
            ];
            return $returnvalue;

        } catch (\Throwable $e) {
            // 外层兜底异常处理：原始异常消息可能包含数据库结构、SQL 片段、
            // 文件路径等敏感信息，绝不能直接返回给前端。
            // 详细错误信息记录到服务端日志供管理员诊断，前端只显示通用错误提示
            // （附带时间戳，便于管理员根据用户反馈定位对应日志条目）。
            $errormsg = $e->getMessage();
            $timestamp = date('Y-m-d H:i:s');
            error_log('[ai_programming_assistant] send_message.execute() exception at ' . $timestamp . ': ' . $errormsg);
            error_log('[ai_programming_assistant] Exception trace: ' . $e->getTraceAsString());
            config_manager::debug_log('send_message.execute() caught exception: ' . $errormsg, [
                'class' => get_class($e),
                'userid' => $USER->id ?? 0,
                'timestamp' => $timestamp,
            ]);
            return [
                'success' => false,
                'message' => '',
                'error' => get_string('error_internal_error_safe', 'local_ai_programming_assistant', $timestamp),
            ];
        }
    }

}