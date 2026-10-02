<?php
/**
 * AI 编程助手 - 对话消息模型
 *
 * 管理对话消息（message）的存储和查询：
 * - 创建用户/AI 消息
 * - 按会话获取历史消息
 * - 支持消息元数据（token 用量、模型信息等）
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */
namespace local_ai_programming_assistant;

defined('MOODLE_INTERNAL') || die();

class message_model {

    /** @var \moodle_database */
    private \moodle_database $db;

    public function __construct(\moodle_database $db) {
        $this->db = $db;
    }

    /**
     * 创建一条消息记录
     *
     * 角色使用规范格式 'user'（用户）或 'assistant'（AI）存储，
     * 与 OpenAI API 消息格式一致，无需读写转换。
     *
     * @param int    $conversationid 会话 ID
     * @param string $role           角色（user/assistant，兼容旧格式 student/ai）
     * @param string $content        消息内容
     * @param array  $metadata       附加元数据（token 用量、模型、后端等）
     * @return int 新消息 ID
     */
    public function create_message(int $conversationid, string $role, string $content, array $metadata = []): int {
        $record = new \stdClass();
        $record->conversationid = $conversationid;
        // 统一存储为规范格式：student→user, ai→assistant
        $record->role = prompt_builder::normalize_role($role);
        $record->content = self::sanitize_text($content);
        $record->timestamp = time();
        // JSON 编码失败时写入空串，避免将 false 直接入库导致不可预期的写入行为
        $encoded = empty($metadata) ? '' : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
        $record->metadata = ($encoded === false) ? '' : $encoded;
        return (int)$this->db->insert_record('local_ai_prog_assist_message', $record);
    }

    /**
     * 获取指定会话的所有消息（按时间正序）
     *
     * @param int $conversationid 会话 ID
     * @return array<\stdClass> 消息记录数组
     */
    public function get_messages(int $conversationid): array {
        return $this->db->get_records(
            'local_ai_prog_assist_message',
            ['conversationid' => $conversationid],
            'timestamp ASC'
        );
    }

    /**
     * 获取指定会话最近 N 条消息（用于 prompt 构建）
     *
     * 优化：直接用 ORDER BY timestamp DESC + LIMIT 在数据库层面取最近 N 条，
     * 然后在 PHP 中反转为时间正序。此前使用子查询先取全部再外层排序，
     * 当会话消息较多时会返回全量数据再截断，浪费 I/O。
     *
     * @param int $conversationid 会话 ID
     * @param int $limit          最大条数
     * @return array<\stdClass> 消息记录数组（时间正序）
     */
    public function get_recent_messages(int $conversationid, int $limit = 6): array {
        $records = $this->db->get_records(
            'local_ai_prog_assist_message',
            ['conversationid' => $conversationid],
            'timestamp DESC',
            'id, conversationid, role, content, timestamp, metadata',
            0,
            $limit
        );
        // 数据库返回 DESC（最新在前），反转为 ASC（最旧在前）供 prompt 按时间顺序构建
        return array_reverse(array_values($records), false);
    }

    /**
     * 清理文本中的 4 字节 UTF-8 字符（兼容 MySQL utf8）
     *
     * 注意：preg_replace 配合 /u 修饰符在 subject 含非法 UTF-8 字节时会返回 null。
     * 若直接将 null 赋给 $record->content，insert_record 会因 NOT NULL 约束失败，
     * 并在事务中回滚整条会话+消息写入（用户消息与 AI 回复全部丢失）。
     * 此处先用 fix_utf8() 规整非法字节，再剥离 4 字节字符；剥离仍失败时回退原文，
     * 保证日志至少能落库（由 DB 驱动层处理残余非法字节）。
     */
    private static function sanitize_text(string $text): string {
        $text = fix_utf8($text);
        $result = @preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $text);
        return $result ?? $text;
    }
}