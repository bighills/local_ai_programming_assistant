<?php
/**
 * AI 编程助手 - 新建会话外部服务接口
 *
 * 用于前端点击"新对话"按钮时，在后端创建新的会话记录，
 * 确保后续消息发送到新的会话中，与之前的会话隔离。
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
use external_value;
use local_ai_programming_assistant\config_manager;

class create_new_conversation extends external_api {

    /**
     * 定义 execute 方法的参数结构
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'actiontype' => new external_value(PARAM_ALPHAEXT, '策略模式: free, question_analysis, idea_organization, code_debugging'),
            'contextid' => new external_value(PARAM_INT, '页面上下文 ID', VALUE_DEFAULT, 0),
            'quizid' => new external_value(PARAM_INT, '测验 ID', VALUE_DEFAULT, 0),
            'usageid' => new external_value(PARAM_INT, '试题用法 ID', VALUE_DEFAULT, 0),
            'slot' => new external_value(PARAM_INT, '试题插槽号', VALUE_DEFAULT, 0),
            'pagetype' => new external_value(PARAM_ALPHANUMEXT, '页面类型', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * 定义 execute 方法的返回值结构
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'conversationid' => new external_value(PARAM_INT, '新会话 ID'),
        ]);
    }

    /**
     * 创建新的对话会话
     *
     * 为当前用户在指定策略模式下创建全新的会话，
     * 返回新会话 ID，前端后续消息将使用此 ID。
     *
     * @param string $actiontype 策略模式
     * @param int $contextid 页面上下文 ID
     * @param int $quizid 测验 ID
     * @param int $usageid 试题用法 ID
     * @param int $slot 试题插槽号
     * @param string $pagetype 页面类型
     * @return array 包含 conversationid 的关联数组
     */
    public static function execute($actiontype, $contextid = 0, $quizid = 0, $usageid = 0, $slot = 0, $pagetype = '') {
        global $DB, $USER;

        try {
            $params = self::validate_parameters(self::execute_parameters(), [
                'actiontype' => $actiontype,
                'contextid' => $contextid,
                'quizid' => $quizid,
                'usageid' => $usageid,
                'slot' => $slot,
                'pagetype' => $pagetype,
            ]);
            $actiontype = $params['actiontype'];
            $contextid = $params['contextid'];
            $quizid = $params['quizid'];
            $usageid = $params['usageid'];
            $slot = $params['slot'];
            $pagetype = $params['pagetype'];

            $context = \local_ai_programming_assistant\helper::resolve_context_from_id($contextid);
            self::validate_context($context);
            require_capability('local/ai_programming_assistant:use', $context);

            // 插件启用检查（防御纵深）：与 send_message / stream_message 保持一致。
            // 禁用时返回 conversationid=0，前端据此判断会话创建失败。
            $pluginconfig = \local_ai_programming_assistant\config_manager::get_plugin_config();
            if (empty($pluginconfig->enable)) {
                return ['conversationid' => 0];
            }

            $userid = (int)$USER->id;

            $questionid = 0;
            if ($usageid > 0 && $slot > 0) {
                $resolved = \local_ai_programming_assistant\feedback_repository::resolve_usageid_static($usageid, $slot);
                if ($resolved > 0) {
                    $questionid = $resolved;
                }
            }

            $courseinfo = \local_ai_programming_assistant\helper::resolve_course_and_cm_from_context($context);
            $courseid = $courseinfo['courseid'] ?? 0;
            $cmid = $courseinfo['cmid'] ?? 0;

            $convmodel = new \local_ai_programming_assistant\conversation_model($DB);
            $conversationid = $convmodel->create_conversation(
                $userid,
                $questionid,
                $actiontype,
                $quizid,
                $contextid,
                $courseid,
                $cmid,
                $pagetype
            );

            // 新会话创建后清除统计缓存，确保会话列表等统计页面及时显示最新会话。
            // 与 feedback_repository::log_to_conversation() 的写入路径保持一致的缓存失效策略。
            \local_ai_programming_assistant\feedback_repository::purge_stats_cache();

            return ['conversationid' => $conversationid];

        } catch (\Throwable $e) {
            $timestamp = date('Y-m-d H:i:s');
            error_log('[ai_programming_assistant] create_new_conversation.execute() exception at ' . $timestamp . ': ' . $e->getMessage());
            config_manager::debug_log('create_new_conversation.execute() caught exception: ' . $e->getMessage(), [
                'class' => get_class($e),
                'userid' => $USER->id ?? 0,
                'timestamp' => $timestamp,
            ]);
            return ['conversationid' => 0];
        }
    }
}