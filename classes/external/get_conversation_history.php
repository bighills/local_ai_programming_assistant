<?php
/**
 * AI 编程助手 - 获取对话历史外部服务接口
 *
 * 根据策略模式类型，从日志数据库中获取当前用户的对话历史记录。
 * 切换策略模式时前端调用此接口，获取对应模式的对话记录并渲染显示。
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */

namespace local_ai_programming_assistant\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;
use local_ai_programming_assistant\config_manager;

class get_conversation_history extends external_api {

    /**
     * 定义 execute 方法的参数结构
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'actiontype' => new external_value(PARAM_ALPHAEXT, '策略模式: free, question_analysis, idea_organization, code_debugging'),
            'contextid' => new external_value(PARAM_INT, '页面上下文 ID（自由模式非试题页面使用）', VALUE_DEFAULT, 0),
            'quizid' => new external_value(PARAM_INT, '测验 ID，用于判断当前页面是否为试题页面', VALUE_DEFAULT, 0),
            'usageid' => new external_value(PARAM_INT, '试题用法 ID，切换试题时传入以过滤对应试题的历史', VALUE_DEFAULT, 0),
            'slot' => new external_value(PARAM_INT, '试题插槽号，配合 usageid 定位试题', VALUE_DEFAULT, 0),
            'questionid' => new external_value(PARAM_INT, '题库题目 ID（mod/page 嵌入题页面直接传入，无需 usageid 解析）', VALUE_DEFAULT, 0),
            'conversationid' => new external_value(PARAM_INT, '对话会话 ID，直接加载指定会话的消息', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * 定义 execute 方法的返回值结构
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'messages' => new external_multiple_structure(
                new external_single_structure([
                    'usermessage' => new external_value(PARAM_RAW, '用户消息'),
                    'airesponse' => new external_value(PARAM_RAW, 'AI 回复'),
                    'timecreated' => new external_value(PARAM_INT, '创建时间戳'),
                ])
            ),
            'conversationid' => new external_value(PARAM_INT, '当前会话 ID', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * 获取指定策略模式的对话历史记录
     *
     * 从 conversation/message 表加载对话历史。
     *
     * @param string $actiontype 策略模式
     * @param int $contextid 页面上下文 ID
     * @param int $quizid 测验 ID
     * @param int $usageid 试题用法 ID
     * @param int $slot 试题插槽号
     * @param int $questionid 题库题目 ID（嵌入题页面直传）
     * @param int $conversationid 对话会话 ID
     * @return array 包含 messages 数组和 conversationid 的关联数组
     */
    public static function execute($actiontype, $contextid = 0, $quizid = 0, $usageid = 0, $slot = 0,
            $questionid = 0, $conversationid = 0) {
        global $DB, $USER;

        try {
            $params = self::validate_parameters(self::execute_parameters(), [
                'actiontype' => $actiontype,
                'contextid' => $contextid,
                'quizid' => $quizid,
                'usageid' => $usageid,
                'slot' => $slot,
                'questionid' => $questionid,
                'conversationid' => $conversationid,
            ]);
            $actiontype = $params['actiontype'];
            $contextid = $params['contextid'];
            $quizid = $params['quizid'];
            $usageid = $params['usageid'];
            $slot = $params['slot'];
            $questionid = $params['questionid'];
            $conversationid = $params['conversationid'];

            $context = \local_ai_programming_assistant\helper::resolve_context_from_id($contextid);
            self::validate_context($context);
            require_capability('local/ai_programming_assistant:use', $context);

            // 插件启用检查：与 send_message / create_new_conversation 保持一致。
            // 管理员禁用插件后，所有请求入口都必须拒绝服务，防止配置被绕过。
            $pluginconfig = config_manager::get_plugin_config();
            if (empty($pluginconfig->enable)) {
                return ['messages' => [], 'conversationid' => 0];
            }

            $userid = (int)$USER->id;

            if ($conversationid > 0) {
                return self::load_from_conversation_table($conversationid, $userid);
            }

            $questionid = (int)$questionid;
            if ($questionid <= 0 && $usageid > 0 && $slot > 0) {
                $resolved = \local_ai_programming_assistant\feedback_repository::resolve_usageid_static($usageid, $slot);
                if ($resolved > 0) {
                    $questionid = $resolved;
                }
            }

            $convmodel = new \local_ai_programming_assistant\conversation_model($DB);
            $existing = $convmodel->find_recent_conversation(
                $userid, $questionid, $actiontype, $quizid, $contextid
            );
            if ($existing !== null) {
                return self::load_from_conversation_table((int)$existing->id, $userid);
            }

            return ['messages' => [], 'conversationid' => 0];

        } catch (\Throwable $e) {
            $timestamp = date('Y-m-d H:i:s');
            error_log('[ai_programming_assistant] get_conversation_history.execute() exception at ' . $timestamp . ': ' . $e->getMessage());
            config_manager::debug_log('get_conversation_history.execute() caught exception: ' . $e->getMessage(), [
                'class' => get_class($e),
                'userid' => $USER->id ?? 0,
                'timestamp' => $timestamp,
            ]);
            return ['messages' => [], 'conversationid' => 0];
        }
    }

    /**
     * 从新 conversation/message 表加载消息
     */
    private static function load_from_conversation_table(int $conversationid, int $userid): array {
        global $DB;
        $convmodel = new \local_ai_programming_assistant\conversation_model($DB);
        $conversation = $convmodel->get_conversation($conversationid);

        // 权限校验：确保会话属于当前用户
        if ($conversation === null || (int)$conversation->userid !== $userid) {
            return ['messages' => [], 'conversationid' => 0];
        }

        $msgmodel = new \local_ai_programming_assistant\message_model($DB);
        $records = $msgmodel->get_messages($conversationid);

        // 将消息按 usermessage/airesponse 配对返回（兼容前端渲染逻辑）
        // 注意：对 PARAM_RAW 字段应用 fix_utf8() 预清洗，避免 Moodle 5.0 validate_param
        // 严格相等性检查因无效 UTF-8 字符抛出 invalid_parameter_exception。
        $messages = [];
        $pendinguser = '';
        foreach ($records as $msg) {
            if ($msg->role === 'user') {
                $pendinguser = fix_utf8($msg->content);
            } elseif ($msg->role === 'assistant') {
                $messages[] = [
                    'usermessage' => $pendinguser,
                    'airesponse' => fix_utf8($msg->content),
                    'timecreated' => (int)$msg->timestamp,
                ];
                $pendinguser = '';
            }
        }
        // 丢弃最后一条未配对的用户消息（AI 未回复的失败请求）：
        // 原实现会构造 airesponse='' 的半轮记录返回前端，导致两个问题：
        // 1. 前端把失败请求显示为已完成的空回复对话，造成用户困惑
        // 2. 用户继续输入新消息时，前端会再次把这条用户消息发送给 AI，
        //    而 DB 中已存在该用户消息，造成历史污染和上下文重复
        // 正确做法：丢弃半轮记录，前端从最后一条完整轮次恢复对话
        // （pendinguser 在此作用域结束自动释放）

        return ['messages' => $messages, 'conversationid' => $conversationid];
    }
}