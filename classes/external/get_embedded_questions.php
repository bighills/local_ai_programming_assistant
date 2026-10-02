<?php
/**
 * AI 编程助手 - 获取页面嵌入题列表外部服务接口
 *
 * 供前端题目选择器使用：mod/page 等无 .que DOM 结构的页面上，
 * 页面通过 tiny_embedquestion 嵌入测验题时，前端调用此接口
 * 获取嵌入题列表，让学生可以选择针对哪道嵌入题提问。
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

class get_embedded_questions extends external_api {

    /**
     * 定义 execute 方法的参数结构
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, '页面上下文 ID（模块级）', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * 定义 execute 方法的返回值结构
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'questions' => new external_multiple_structure(
                new external_single_structure([
                    'questionid' => new external_value(PARAM_INT, '题库题目 ID'),
                    'name' => new external_value(PARAM_RAW, '题目名称'),
                    'qtype' => new external_value(PARAM_ALPHANUMEXT, '题型标识（如 coderunner_fillblank）'),
                    'questiontext' => new external_value(PARAM_RAW, '题目内容（纯文本）'),
                    'studentanswer' => new external_value(PARAM_RAW, '学生最近提交的代码'),
                    'testresults' => new external_value(PARAM_RAW, '系统运行反馈与详细测试结果'),
                    'programminglanguage' => new external_value(PARAM_RAW, '编程语言标识'),
                ])
            ),
            'count' => new external_value(PARAM_INT, '嵌入题数量'),
        ]);
    }

    /**
     * 获取当前页面的嵌入题列表
     *
     * @param int $contextid 页面上下文 ID
     * @return array 包含 questions 数组和 count 的关联数组
     */
    public static function execute($contextid = 0) {
        try {
            global $USER;

            $params = self::validate_parameters(self::execute_parameters(), [
                'contextid' => $contextid,
            ]);

            $context = \local_ai_programming_assistant\helper::resolve_context_from_id($params['contextid']);
            self::validate_context($context);
            require_capability('local/ai_programming_assistant:use', $context);

            // 插件启用检查：与其他外部接口保持一致，管理员禁用插件后必须拒绝服务。
            $pluginconfig = config_manager::get_plugin_config();
            if (empty($pluginconfig->enable)) {
                return ['questions' => [], 'count' => 0];
            }

            $questions = \local_ai_programming_assistant\embedded_questions::get_embedded_questions($context);

            // 对 PARAM_RAW 字段应用 fix_utf8() 预清洗，避免 Moodle 5.0 validate_param
            // 严格相等性检查因无效 UTF-8 字符抛出 invalid_parameter_exception。
            $cleanquestions = [];
            foreach ($questions as $question) {
                $cleanquestions[] = [
                    'questionid' => (int)$question['questionid'],
                    'name' => fix_utf8((string)$question['name']),
                    'qtype' => fix_utf8((string)$question['qtype']),
                    'questiontext' => fix_utf8((string)$question['questiontext']),
                    'studentanswer' => fix_utf8((string)($question['studentanswer'] ?? '')),
                    'testresults' => fix_utf8((string)($question['testresults'] ?? '')),
                    'programminglanguage' => fix_utf8((string)($question['programminglanguage'] ?? '')),
                ];
            }

            return [
                'questions' => $cleanquestions,
                'count' => count($cleanquestions),
            ];
        } catch (\Throwable $e) {
            // 异常消息可能包含内部信息，仅记入服务端日志，对外返回空列表（功能可降级）。
            $timestamp = date('Y-m-d H:i:s');
            error_log('[ai_programming_assistant] get_embedded_questions.execute() exception at '
                . $timestamp . ': ' . $e->getMessage());
            config_manager::debug_log('get_embedded_questions.execute() caught exception: ' . $e->getMessage(), [
                'class' => get_class($e),
                'userid' => $USER->id ?? 0,
                'timestamp' => $timestamp,
            ]);
            return ['questions' => [], 'count' => 0];
        }
    }

}
