<?php
/**
 * AI 编程助手 - 定时清理任务
 *
 * 定期清理过期的对话记录，防止数据库表无限增长。
 * 使用批量 + 事务方式删除，确保数据一致性。
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */

namespace local_ai_programming_assistant\task;

defined('MOODLE_INTERNAL') || die();

class cleanup_task extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_cleanup', 'local_ai_programming_assistant');
    }

    /**
     * 执行清理任务
     *
     * 实现说明：
     * 1. 使用复合键 (endtime, id) 的正向 keyset pagination 游标。
     *    旧实现用 `endtime <= :cutoff` 且 cutoff 取本批最后一条的 endtime，
     *    由于记录按 ASC 排序，cutoff 会越来越小，删除本批后下一批立即为空，
     *    导致每次定时任务只删 500 条最旧记录——表仍无限增长。
     *    正向游标用 `(endtime, id) > (:last_endtime, :last_id)` 跳过已处理记录，
     *    无论删除是否成功都能稳定前进，避免死循环。
     * 2. 每批删除用事务包裹：消息 → 会话，保证原子性，防止中断后产生孤儿消息。
     * 3. 单批失败时游标已前进，本批跳过留待下次定时任务重试，不进入死循环；
     *    仅统计实际提交成功的批次，避免误导性的“已删除”计数。
     * 4. 使用 delete_records_list() 参数化删除，与子表删除方式保持一致，
     *    避免 implode 拼接 SQL 的注入面（虽当前 id 来自 DB 仍属安全，但应统一风格）。
     */
    public function execute() {
        global $DB;

        $days = (int) \local_ai_programming_assistant\config_manager::get_config_cached('log_retention_days', 30);
        if ($days <= 0) {
            $days = 30;
        }

        $expiry = time() - ($days * 86400);

        mtrace("[ai_programming_assistant] Starting cleanup task, removing conversations older than {$days} days...");

        $batchsize = 500;
        $totaldeleted = 0;
        // 复合游标 (endtime, id)：首次迭代从 endtime 最小处开始
        $lastendtime = 0;
        $lastid = 0;
        // 首次迭代状态使用布尔标志而非判断 endtime>0：表中若存在 endtime=0 的异常记录
        // （如脏数据/升级残留），末条记录的 endtime 恒为 0，数值判断会使游标条件永不生效，
        // 在删除失败回滚时反复选中同一批记录导致死循环。
        $firstiteration = true;
        $progresscounter = 0;

        while (true) {
            // 正向 keyset pagination：跳过已处理记录（无论是否删除成功）
            // 首次迭代游标为空，匹配全部 endtime <= expiry 的记录
            $params = ['cutoff' => $expiry];
            $cursorcond = '';
            if (!$firstiteration) {
                $cursorcond = ' AND (endtime > :let OR (endtime = :leet AND id > :lid))';
                $params['let'] = $lastendtime;
                $params['leet'] = $lastendtime;
                $params['lid'] = $lastid;
            }
            $firstiteration = false;

            $records = $DB->get_records_sql(
                "SELECT id, endtime
                   FROM {local_ai_prog_assist_conversation}
                  WHERE endtime <= :cutoff {$cursorcond}
                  ORDER BY endtime ASC, id ASC",
                $params,
                0,
                $batchsize
            );

            if (empty($records)) {
                break;
            }

            $conversationids = array_keys($records);
            $lastrecord = end($records);
            // 游标在删除前推进，确保删除失败时本批被跳过、不会死循环
            $lastendtime = (int)$lastrecord->endtime;
            $lastid = (int)$lastrecord->id;
            $batchcount = count($conversationids);

            // 事务包裹删除：先删消息再删会话，确保原子性
            $batchsuccess = false;
            try {
                $transaction = $DB->start_delegated_transaction();

                // 1. 删除关联的所有消息（参数化，与会话删除方式一致）
                $DB->delete_records_list('local_ai_prog_assist_message', 'conversationid', $conversationids);

                // 2. 删除会话本身
                $DB->delete_records_list('local_ai_prog_assist_conversation', 'id', $conversationids);

                $transaction->allow_commit();
                $batchsuccess = true;
            } catch (\Throwable $e) {
                $transaction->rollback($e);
                mtrace("[ai_programming_assistant] ERROR: batch delete failed (will retry next run): " . $e->getMessage());
                // 游标已前进，本批跳过，继续下一批
            }

            if ($batchsuccess) {
                $totaldeleted += $batchcount;
                $progresscounter += $batchcount;
                // 每 5000 条输出一次进度，避免日志过于频繁
                if ($progresscounter >= 5000) {
                    mtrace("[ai_programming_assistant] Deleted {$totaldeleted} conversations so far...");
                    $progresscounter = 0;
                }
            }

            // 不足一批说明已是最后一页
            if ($batchcount < $batchsize) {
                break;
            }
        }

        // 删除完成后清除统计缓存（stats 缓存 TTL 300s，若不清除，
        // 已删除的过期会话最长 5 分钟仍会出现在统计页面中）
        if ($totaldeleted > 0) {
            \local_ai_programming_assistant\feedback_repository::purge_stats_cache();
        }

        mtrace("[ai_programming_assistant] Cleanup complete. Total deleted conversations: {$totaldeleted}");
    }
}