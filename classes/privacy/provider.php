<?php
/**
 * AI 编程助手 - 隐私 API 提供者
 *
 * 实现 Moodle 隐私 API，支持用户数据导出和删除操作，
 * 确保插件符合 GDPR 等数据保护法规的要求。
 *
 * @author zfl
 * @package    local_ai_programming_assistant
 */
namespace local_ai_programming_assistant\privacy;
defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * 隐私提供者
 *
 * 处理用户对话日志的数据隐私相关操作。
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * 声明插件存储的个人数据元数据
     *
     * @param collection $collection 元数据集合
     * @return collection 更新后的元数据集合
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_ai_prog_assist_conversation',
            [
                'userid' => 'privacy:metadata:local_ai_prog_assist_conversation:userid',
                'questionid' => 'privacy:metadata:local_ai_prog_assist_conversation:questionid',
                'quizid' => 'privacy:metadata:local_ai_prog_assist_conversation:quizid',
                'actiontype' => 'privacy:metadata:local_ai_prog_assist_conversation:actiontype',
                'starttime' => 'privacy:metadata:local_ai_prog_assist_conversation:starttime',
                'endtime' => 'privacy:metadata:local_ai_prog_assist_conversation:endtime',
                'contextid' => 'privacy:metadata:local_ai_prog_assist_conversation:contextid',
                'courseid' => 'privacy:metadata:local_ai_prog_assist_conversation:courseid',
                'cmid' => 'privacy:metadata:local_ai_prog_assist_conversation:cmid',
                'pagetype' => 'privacy:metadata:local_ai_prog_assist_conversation:pagetype',
                'messagecount' => 'privacy:metadata:local_ai_prog_assist_conversation:messagecount',
                'metadata' => 'privacy:metadata:local_ai_prog_assist_conversation:metadata',
            ],
            'privacy:metadata:local_ai_prog_assist_conversation',
        );

        $collection->add_database_table(
            'local_ai_prog_assist_message',
            [
                'conversationid' => 'privacy:metadata:local_ai_prog_assist_message:conversationid',
                'role' => 'privacy:metadata:local_ai_prog_assist_message:role',
                'content' => 'privacy:metadata:local_ai_prog_assist_message:content',
                'timestamp' => 'privacy:metadata:local_ai_prog_assist_message:timestamp',
                'metadata' => 'privacy:metadata:local_ai_prog_assist_message:metadata',
            ],
            'privacy:metadata:local_ai_prog_assist_message',
        );

        return $collection;
    }

    /**
     * 获取指定用户拥有数据的上下文列表
     *
     * lokal 插件数据存储在系统上下文中，统一返回系统上下文。
     *
     * @param int $userid 用户 ID
     * @return contextlist 上下文列表
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        global $DB;
        if ($DB->record_exists('local_ai_prog_assist_conversation', ['userid' => $userid])) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * 获取指定上下文中拥有数据的用户列表
     *
     * @param userlist $userlist 用户列表对象
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }
        $sql = "SELECT DISTINCT c.userid
                  FROM {local_ai_prog_assist_conversation} c";
        $userlist->add_from_sql('userid', $sql, []);
    }

    /**
     * 导出指定上下文中的用户数据
     *
     * @param approved_contextlist $contextlist 已批准的上下文列表
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_SYSTEM) {
                continue;
            }

            self::export_conversation_data($context, $userid);
        }
    }

    /**
     * 删除指定上下文中所有用户的数据
     *
     * 使用事务包裹确保消息和会话删除的原子性，避免中途失败产生孤儿消息。
     *
     * @param \context $context 上下文对象
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        if ($context->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->delete_records('local_ai_prog_assist_message', []);
            $DB->delete_records('local_ai_prog_assist_conversation', []);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    /**
     * 删除指定上下文中指定用户的数据
     *
     * 使用事务包裹确保消息和会话删除的原子性。
     * 异常向上抛出，确保 GDPR 删除失败时系统能感知并重试，而非静默吞没。
     *
     * @param approved_contextlist $contextlist 已批准的上下文列表
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($contextlist->get_contexts() as $context) {
                if ($context->contextlevel !== CONTEXT_SYSTEM) {
                    continue;
                }
                $conversationids = $DB->get_fieldset_select(
                    'local_ai_prog_assist_conversation', 'id', 'userid = :userid', ['userid' => $userid]
                );
                if (!empty($conversationids)) {
                    $DB->delete_records_list('local_ai_prog_assist_message', 'conversationid', $conversationids);
                }
                $DB->delete_records('local_ai_prog_assist_conversation', ['userid' => $userid]);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    /**
     * 删除指定上下文中多个用户的数据
     *
     * 使用事务包裹确保消息和会话删除的原子性。
     * 异常向上抛出，确保 GDPR 批量删除失败时系统能感知并重试，而非静默吞没。
     *
     * @param approved_userlist $userlist 已批准的用户列表
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }
        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
            $conversationids = $DB->get_fieldset_select(
                'local_ai_prog_assist_conversation', 'id', "userid $insql", $inparams
            );
            if (!empty($conversationids)) {
                list($msginsql, $msginparams) = $DB->get_in_or_equal($conversationids, SQL_PARAMS_NAMED);
                $DB->delete_records_select('local_ai_prog_assist_message', "conversationid $msginsql", $msginparams);
            }
            $DB->delete_records_select('local_ai_prog_assist_conversation', "userid $insql", $inparams);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    /**
     * 导出用户的对话数据
     *
     * @param \context $context 上下文对象
     * @param int $userid 用户 ID
     */
    private static function export_conversation_data(\context $context, int $userid): void {
        global $DB;
        $conversations = $DB->get_records(
            'local_ai_prog_assist_conversation',
            ['userid' => $userid],
            'starttime ASC'
        );
        if (empty($conversations)) {
            return;
        }

        // 一次性获取所有会话的消息，避免 N+1 查询（每个会话单独查询消息表）
        $conversationids = array_keys($conversations);
        list($insql, $inparams) = $DB->get_in_or_equal($conversationids, SQL_PARAMS_NAMED, 'conv');
        $allmessages = $DB->get_records_select(
            'local_ai_prog_assist_message',
            "conversationid $insql",
            $inparams,
            'timestamp ASC'
        );

        // 按 conversationid 分组消息
        $messagesbyconv = [];
        foreach ($allmessages as $msg) {
            $messagesbyconv[(int)$msg->conversationid][] = $msg;
        }

        $basepath = [
            get_string('pluginname', 'local_ai_programming_assistant'),
            get_string('privacy:metadata:local_ai_prog_assist_conversation', 'local_ai_programming_assistant'),
        ];

        foreach ($conversations as $conv) {
            $messages = $messagesbyconv[(int)$conv->id] ?? [];
            $convdata = (object)[
                'questionid' => $conv->questionid,
                'actiontype' => $conv->actiontype,
                'starttime' => \core_privacy\local\request\transform::datetime($conv->starttime),
                'endtime' => \core_privacy\local\request\transform::datetime($conv->endtime),
                // 与 get_metadata() 声明的字段保持一致，metadata 中可能含
                // model/success/responsetime/errormessage 等 AI 调用信息
                'quizid' => $conv->quizid,
                'courseid' => $conv->courseid,
                'cmid' => $conv->cmid,
                'contextid' => $conv->contextid,
                'pagetype' => $conv->pagetype,
                'messagecount' => $conv->messagecount,
                'metadata' => $conv->metadata,
                'messages' => [],
            ];

            foreach ($messages as $msg) {
                $convdata->messages[] = (object)[
                    'role' => $msg->role,
                    'content' => $msg->content,
                    'timestamp' => \core_privacy\local\request\transform::datetime($msg->timestamp),
                    'metadata' => $msg->metadata,
                ];
            }

            // 每个会话使用独立子路径导出，避免数据覆盖并提升可读性
            $subcontext = array_merge($basepath, [$conv->id]);
            writer::with_context($context)->export_data($subcontext, $convdata);
        }
    }
}