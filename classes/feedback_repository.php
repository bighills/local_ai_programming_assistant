<?php
/**
 * AI 编程助手 - 反馈记录仓库
 *
 * 处理 AI 反馈日志的数据库操作，包括创建日志、查询最新记录、获取统计数据等。
 *
 * 所有对话记录（自由模式与试题模式）统一写入 conversation + message 两张表，
 * 由 log_to_conversation() 作为唯一写入入口，确保事务原子性与 IDOR 防护。
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */

namespace local_ai_programming_assistant;

defined('MOODLE_INTERNAL') || die();

class feedback_repository {

    /** @var array<string, int> 请求级缓存："{usageid}:{slot}" → questionid 映射 */
    private static array $usageidCache = [];

    /**
     * 重置所有静态缓存（用于 PHP-FPM 常驻进程或单元测试）
     */
    public static function reset(): void {
        self::$usageidCache = [];
    }

    /**
     * 公共静态包装：解析试题用法 ID 为真实的试题 ID（供外部类直接调用）
     *
     * @param int $usageid question_attempt.questionusageid
     * @param int $slot 试题插槽号
     * @return int 解析到的 question.id，失败返回 0
     */
    public static function resolve_usageid_static(int $usageid, int $slot = 0): int {
        global $DB;
        if ($usageid <= 0) {
            return 0;
        }
        $cacheKey = $usageid . ':' . $slot;
        if (isset(self::$usageidCache[$cacheKey])) {
            return self::$usageidCache[$cacheKey];
        }
        if ($slot > 0) {
            $qid = (int)$DB->get_field('question_attempts', 'questionid',
                ['questionusageid' => $usageid, 'slot' => $slot]);
        } else {
            $qid = (int)$DB->get_field('question_attempts', 'questionid',
                ['questionusageid' => $usageid], IGNORE_MULTIPLE);
        }
        self::$usageidCache[$cacheKey] = $qid;
        return $qid;
    }

    /**
     * 条件日志记录便捷方法
     *
     * 封装日志开关判断与 try-catch，供 manager 在阻塞与流式两条路径中统一调用，
     * 消除两处重复的条件分支与异常吞咽逻辑。
     *
     * - loggingenabled = true → 写入 conversation + message 表（事务保证原子性）
     * - loggingenabled = false → 直接返回当前 conversationid，不写库
     * - 写入异常时仅记 debugging 日志，不向上抛出，避免日志失败影响主流程
     *
     * @param bool      $loggingenabled 日志是否启用
     * @param log_entry $entry          日志数据传输对象
     * @return int 写入成功返回会话 ID；未启用日志或写入失败返回 $entry->conversationid
     */
    public static function try_log(
        bool $loggingenabled,
        log_entry $entry
    ): int {
        if (!$loggingenabled) {
            return (int)$entry->conversationid;
        }

        try {
            return self::log_to_conversation($entry);
        } catch (\Throwable $e) {
            debugging('[ai_programming_assistant] Failed to log: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return (int)$entry->conversationid;
        }
    }

    /**
     * 写入新模型的对话消息表（conversation + message）
     *
     * 当 log_entry 中已有 conversationid 时，写入已有会话；
     * 当 conversationid 为 0 时，始终创建全新会话（支持同试题/同用户/同策略多会话）。
     *
     * @param log_entry $entry 日志数据传输对象
     * @return int 会话 ID
     */
    public static function log_to_conversation(log_entry $entry): int {
        global $DB;

        $convmodel = new conversation_model($DB);
        $msgmodel = new message_model($DB);

        // 解析 questionid（只读操作，无需包含在事务中）
        // 仅当调用方未提供 questionid（<= 0）时才从 question_attempts 表解析，
        // 避免已知 questionid 时产生不必要的 DB 查询
        if ($entry->questionid <= 0) {
            $resolved = self::resolve_usageid_static($entry->usageid, $entry->slot);
            if ($resolved > 0) {
                $entry->questionid = $resolved;
            }
        }

        // 事务保证会话创建、消息写入、元数据更新和计数更新的原子性，
        // 防止部分写入失败导致数据不一致（如：有用户消息但无 AI 回复的会话，
        // 或会话已创建但 metadata 缺失导致统计查询异常）
        $transaction = $DB->start_delegated_transaction();
        try {
            // 创建或使用会话：
            // - conversationid > 0: 写入指定会话（继续对话）——需先验证会话归属
            // - conversationid <= 0: 始终创建新会话（支持多会话场景）
            $conversationid = $entry->conversationid;
            $isnewconversation = false;
            if ($conversationid <= 0) {
                $conversationid = $convmodel->create_conversation(
                    $entry->userid,
                    $entry->questionid,
                    $entry->actiontype,
                    $entry->quizid,
                    $entry->contextid,
                    $entry->courseid,
                    $entry->cmid,
                    $entry->pagetype
                );
                $isnewconversation = true;
            } else {
                // 安全检查：验证会话属于当前用户，防止 IDOR 越权写入他人会话
                $existing = $convmodel->get_conversation($conversationid);
                if ($existing === null || ($entry->userid > 0 && (int)$existing->userid !== $entry->userid)) {
                    // 会话不存在或不属于当前用户：创建新会话而非写入他人会话
                    $conversationid = $convmodel->create_conversation(
                        $entry->userid,
                        $entry->questionid,
                        $entry->actiontype,
                        $entry->quizid,
                        $entry->contextid,
                        $entry->courseid,
                        $entry->cmid,
                        $entry->pagetype
                    );
                    $isnewconversation = true;
                }
            }

            // 写入用户消息（规范角色 'user'，与 DB 存储格式一致，无需转换）
            $msgcount = 0;
            if (!empty(trim($entry->usermessage))) {
                $msgmodel->create_message($conversationid, 'user', $entry->usermessage);
                $msgcount++;
            }

            // 写入 AI 消息（规范角色 'assistant'，与 DB 存储格式一致，无需转换）
            if (!empty(trim($entry->airesponse))) {
                $metadata = [];
                if (!empty($entry->model)) {
                    $metadata['model'] = $entry->model;
                }
                if (!empty($entry->ai_backend)) {
                    $metadata['ai_backend'] = $entry->ai_backend;
                }
                if ($entry->responsetime > 0) {
                    $metadata['responsetime'] = $entry->responsetime;
                }
                $metadata['success'] = $entry->success;
                if (!empty($entry->errormessage)) {
                    $metadata['errormessage'] = $entry->errormessage;
                }
                $msgmodel->create_message($conversationid, 'assistant', $entry->airesponse, $metadata);
                $msgcount++;

                // 将 AI 响应的元数据写入 conversation.metadata，
                // 供 stats_repository 和 free_log_stats_repository 中的统计查询使用
                // （统计查询通过 JSON_VALUE(c.metadata, '$.key') 读取，需要 c.metadata 实际有值）
                // 注意：仅在首次创建新会话时写入，跟进消息不覆盖，确保统计指标反映首次(代表性)AI调用
                // 追答场景的完整元数据仍在 message.metadata 中可查
                if ($isnewconversation) {
                    // 与 message_model::create_message() 保持一致的编码标志：
                    // errormessage 等字段可能含非法 UTF-8 字节，缺少 JSON_INVALID_UTF8_IGNORE 时
                    // json_encode 返回 false 导致会话 metadata 静默跳过，统计口径与消息表不一致。
                    $conversationmeta = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
                    if ($conversationmeta !== false) {
                        $DB->execute(
                            "UPDATE {local_ai_prog_assist_conversation}
                                SET metadata = :metadata
                              WHERE id = :id",
                            ['metadata' => $conversationmeta, 'id' => $conversationid]
                        );
                    }
                }
            }

            // 单次 UPDATE 批量更新会话时间和消息计数（原先分两次调用 touch_conversation）
            if ($msgcount > 0) {
                $convmodel->touch_conversation_batch($conversationid, $msgcount);
            }

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }

        // 写入成功后清除跨请求统计缓存，确保统计页面立即反映最新对话数据。
        // stats 缓存为应用级缓存（TTL 300s），若不清除，新会话/新消息最长
        // 5 分钟不出现在会话列表、学生提问记录、课程统计等页面中。
        self::purge_stats_cache();

        return $conversationid;
    }

    /**
     * 清除统计查询缓存（会话/消息数据变更后调用）
     *
     * 与 stats_repository::purge_stats_cache() 使用同一个缓存定义
     * （local_ai_programming_assistant / stats），保证删除与写入路径
     * 的缓存失效策略一致。
     */
    public static function purge_stats_cache(): void {
        \cache::make('local_ai_programming_assistant', 'stats')->purge();
    }
}
