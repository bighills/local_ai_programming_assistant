<?php
/**
 * AI 编程助手 - 请求处理工具类
 *
 * 封装参数提取和对话历史解码等通用逻辑，供外部 API 和流式控制器使用。
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */

namespace local_ai_programming_assistant;

defined('MOODLE_INTERNAL') || die();

class request_handler {

    /**
     * 构建页面上下文数组
     *
     * @param array $params 原始输入参数
     * @return array 页面上下文数组
     */
    public static function build_page_context(array $params): array {
        return [
            'questiontext' => $params['questiontext'] ?? '',
            'studentanswer' => $params['studentanswer'] ?? '',
            'testresults' => $params['testresults'] ?? '',
            'programminglanguage' => $params['programminglanguage'] ?? '',
            'actiontype' => $params['actiontype'] ?? 'free',
            'questionid' => (int)($params['questionid'] ?? 0),
            'quizid' => (int)($params['quizid'] ?? 0),
        ];
    }

    /**
     * 从输入中提取公共参数数组
     *
     * 对字符串字段应用 fix_utf8 清洗（与外部 API 的 PARAM_RAW 等效），
     * 确保流式路径的输入验证与外部 API 路径一致。
     *
     * @param array $input 解码后的 JSON 输入
     * @return array 标准化的参数数组
     */
    public static function extract_params(array $input): array {
        // history 可能在流式路径中被前端作为 JSON 数组发送，(string) 强制转换
        // 会将其变成无意义的 "Array" 字符串，必须单独处理
        $history = $input['history'] ?? '';
        if (is_array($history)) {
            $history = json_encode($history, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
            if ($history === false) {
                $history = '';
            }
        }
        return [
            'message' => fix_utf8((string)($input['message'] ?? '')),
            'history' => fix_utf8($history),
            'questiontext' => fix_utf8((string)($input['questiontext'] ?? '')),
            'studentanswer' => fix_utf8((string)($input['studentanswer'] ?? '')),
            'testresults' => fix_utf8((string)($input['testresults'] ?? '')),
            'programminglanguage' => fix_utf8((string)($input['programminglanguage'] ?? '')),
            'contextid' => (int)($input['contextid'] ?? 0),
            'actiontype' => clean_param((string)($input['actiontype'] ?? 'free'), PARAM_ALPHAEXT),
            'questionid' => (int)($input['questionid'] ?? 0),
            'usageid' => (int)($input['usageid'] ?? 0),
            'slot' => (int)($input['slot'] ?? 0),
            'quizid' => (int)($input['quizid'] ?? 0),
            'attemptid' => (int)($input['attemptid'] ?? 0),
            'pagetype' => clean_param((string)($input['pagetype'] ?? ''), PARAM_ALPHANUMEXT),
            'conversationid' => (int)($input['conversationid'] ?? 0),
        ];
    }

    /**
     * 解码 JSON 对话历史
     *
     * 统一将所有历史消息格式转换为规范格式 ['role', 'content']：
     * - 新格式：{role: 'user'|'assistant', content: '...'}
     * - 旧格式：{user: '...', message: '...'}（向后兼容）
     * - CoreAI格式：{sender: 'user'|'ai', message: '...'}
     *
     * @param string $history JSON 编码的对话历史
     * @return array 解码后的历史记录数组（统一格式）
     */
    public static function decode_history(string $history): array {
        $decoded = json_decode($history, true);
        if (!is_array($decoded)) {
            return [];
        }
        if (count($decoded) > 100) {
            $decoded = array_slice($decoded, -100);
        }
        // 旧格式历史 {user: '助手名', message: ...} 中 AI 消息的 user 字段是助手名称
        //（前端 pushHistory(config.assistantName, ...)），必须传入 assistantname，
        // 否则 normalize_message() 无法把 AI 消息从 user 中区分出来，导致角色错位。
        $assistantname = config_manager::get_config_cached('assistantname',
            get_string('assistantname', 'local_ai_programming_assistant'));
        $maxmsglen = 30000;
        $result = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $normalized = prompt_builder::normalize_message($entry, $assistantname);
            // 超长消息（如大段学生代码）前缀截断后保留，而非整体丢弃：
            // 丢弃会使该轮对话上下文凭空消失，截断则至少保留最近部分供 AI 参考。
            $content = $normalized['content'];
            if (mb_strlen($content, 'UTF-8') > $maxmsglen) {
                $content = mb_substr($content, 0, $maxmsglen, 'UTF-8');
            }
            $result[] = [
                'role' => $normalized['role'],
                'content' => $content,
            ];
        }
        return $result;
    }

}