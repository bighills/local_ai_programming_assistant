<?php
/**
 * AI 编程助手 - 对话会话模型
 *
 * 管理对话会话（conversation）的生命周期：
 * - 创建新会话
 * - 查询会话信息
 * - 更新会话时间和消息计数
 * - 按条件查询最近会话
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */
namespace local_ai_programming_assistant;

defined('MOODLE_INTERNAL') || die();

class conversation_model {

    /** @var \moodle_database */
    private \moodle_database $db;

    public function __construct(\moodle_database $db) {
        $this->db = $db;
    }

    /**
     * 创建新的对话会话
     *
     * @param int    $userid      用户 ID
     * @param int    $questionid  试题 ID（自由模式传 0）
     * @param string $actiontype  操作类型
     * @param int    $quizid      测验 ID
     * @param int    $contextid   上下文 ID
     * @param int    $courseid    课程 ID
     * @param int    $cmid        课程模块 ID
     * @param string $pagetype    页面类型
     * @return int 新会话 ID
     */
    public function create_conversation(
        int $userid,
        int $questionid,
        string $actiontype,
        int $quizid = 0,
        int $contextid = 0,
        int $courseid = 0,
        int $cmid = 0,
        string $pagetype = ''
    ): int {
        $record = new \stdClass();
        $record->userid = $userid;
        $record->questionid = $questionid;
        $record->actiontype = $actiontype ?: 'free';
        $record->quizid = $quizid;
        $record->contextid = $contextid;
        $record->courseid = $courseid;
        $record->cmid = $cmid;
        $record->pagetype = $pagetype;
        $record->starttime = time();
        $record->endtime = time();
        $record->messagecount = 0;
        $record->metadata = '';
        return (int)$this->db->insert_record('local_ai_prog_assist_conversation', $record);
    }

    /**
     * 获取会话信息
     *
     * @param int $conversationid 会话 ID
     * @return \stdClass|null
     */
    public function get_conversation(int $conversationid): ?\stdClass {
        return $this->db->get_record('local_ai_prog_assist_conversation', ['id' => $conversationid]) ?: null;
    }

    /**
     * 批量更新会话结束时间和消息计数（减少多次单独 UPDATE 的数据库往返）
     *
     * @param int $conversationid 会话 ID
     * @param int $messagecount   本次新增的消息数量
     */
    public function touch_conversation_batch(int $conversationid, int $messagecount): void {
        if ($messagecount <= 0) {
            return;
        }
        $this->db->execute(
            "UPDATE {local_ai_prog_assist_conversation}
                SET endtime = :endtime, messagecount = messagecount + :msgcount
              WHERE id = :id",
            ['endtime' => time(), 'msgcount' => $messagecount, 'id' => $conversationid]
        );
    }

    /**
     * 查找用户最近的活跃会话
     *
     * 在指定时间窗口内（默认 30 分钟），查找匹配条件的最近会话，
     * 用于判断是否复用现有会话还是创建新会话。
     *
     * @param int    $userid      用户 ID
     * @param int    $questionid  试题 ID
     * @param string $actiontype  操作类型
     * @param int    $quizid      测验 ID
     * @param int    $contextid   上下文 ID
     * @param int    $timeout     会话超时秒数（默认 1800 = 30 分钟）
     * @return \stdClass|null 匹配的最近会话记录，无则返回 null
     */
    public function find_recent_conversation(
        int $userid,
        int $questionid,
        string $actiontype,
        int $quizid = 0,
        int $contextid = 0,
        int $timeout = 1800
    ): ?\stdClass {
        $params = [
            'userid' => $userid,
            'actiontype' => $actiontype ?: 'free',
            'cutoff' => time() - $timeout,
        ];

        $conditions = 'userid = :userid AND actiontype = :actiontype AND endtime > :cutoff';

        if ($actiontype === 'free' && $questionid <= 0) {
            // 自由模式无试题：按 contextid 匹配。显式约束 contextid（含站点级 0），
            // 避免 contextid=0 时查询无任何上下文隔离，误复用其它页面/测验的会话
            $conditions .= ' AND contextid = :contextid';
            $params['contextid'] = $contextid;
        } else {
            // 有试题 ID：按 questionid 匹配
            if ($questionid > 0) {
                $conditions .= ' AND questionid = :questionid';
                $params['questionid'] = $questionid;
            } else {
                // 策略模式（非 free）但未解析到题目：显式排除所有带题号的会话，
                // 避免无约束匹配到同用户最近任意题目的会话，导致跨题目上下文串台。
                $conditions .= ' AND questionid = 0';
            }
            if ($quizid > 0) {
                $conditions .= ' AND quizid = :quizid';
                $params['quizid'] = $quizid;
            }
        }

        // 使用 get_records_sql + limit 1：同一 (userid, questionid/contextid, actiontype)
        // 在窗口内可能存在多条会话，get_record_sql 遇到多行会触发
        // "found more than one record" 调试警告（虽仍返回首行）。此处明确限定一行，
        // 语义一致（取最近一条）且消除日志噪音。
        $records = $this->db->get_records_sql(
            "SELECT id, userid, questionid, actiontype, quizid, contextid, courseid, cmid,
                    pagetype, starttime, endtime, messagecount, metadata
               FROM {local_ai_prog_assist_conversation}
              WHERE {$conditions}
              ORDER BY endtime DESC",
            $params,
            0,
            1 // 由 Moodle 抽象层按数据库驱动追加可移植的 LIMIT，避免内联 LIMIT 在 SQL Server/Oracle 上报错
        );
        return $records ? reset($records) : null;
    }
}