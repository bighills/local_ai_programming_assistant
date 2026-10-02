<?php
/**
 * AI 编程助手 - 统计查询仓库
 *
 * 封装所有统计相关的数据库查询逻辑，包括整体统计、试题统计、学生统计等。
 * 数据来源：local_ai_prog_assist_conversation + local_ai_prog_assist_message 表。
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */

namespace local_ai_programming_assistant;

defined('MOODLE_INTERNAL') || die();

class stats_repository {

    use traits\base_stats_repository;

    /** @var array 题目排序字段白名单 */
    private const QUESTION_SORT_FIELDS = [
        'questionid' => 'questionid',
        'questionname' => 'questionname',
        'total_requests' => 'total_requests',
        'last_timecreated' => 'last_timecreated',
    ];

    /** @var array 学生排序字段白名单 */
    private const STUDENT_SORT_FIELDS = [
        'fullname' => 'u.firstname',
        'total_requests' => 'total_requests',
        'last_timecreated' => 'last_timecreated',
        'unique_questions' => 'unique_questions',
    ];

    /** @var free_log_stats_repository|null 自由模式统计仓库（懒加载） */
    private ?free_log_stats_repository $freelogrepo = null;

    public function __construct() {
        global $DB;
        $this->db = $DB;
    }

    /**
     * 获取自由模式统计仓库实例
     */
    private function get_free_log_repo(): free_log_stats_repository {
        if ($this->freelogrepo === null) {
            $this->freelogrepo = new free_log_stats_repository();
        }
        return $this->freelogrepo;
    }

    public function get_statistics(?int $starttime = null, ?int $endtime = null, ?int $quizid = null, ?int $courseid = null): array {
        $cachekey = $this->cache_key('get_statistics', ['s' => $starttime, 'e' => $endtime, 'q' => $quizid, 'c' => $courseid]);

        return $this->cached($cachekey, function() use ($starttime, $endtime, $quizid, $courseid) {
            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $quizwhere = '';
            if ($quizid !== null && $quizid > 0) {
                $quizwhere = ' AND c.quizid = :quizid';
                $params['quizid'] = $quizid;
            }
            $coursewhere = $this->build_course_filter($courseid, $params, 'g_');
            $where = '1=1 AND c.questionid > 0' . $timewhere . $quizwhere . $coursewhere;

            // CROSS JOIN 的 d 子查询需要独立参数名，使用 build_time_where 的 suffix 参数
            // 代替手写 where2，消除重复的 if/else 构建代码
            list($timewhere2, $params2) = $this->build_time_where($starttime, $endtime, 'c', 'starttime', '_d');
            $quizwhere2 = '';
            if ($quizid !== null && $quizid > 0) {
                $quizwhere2 = ' AND c.quizid = :quizid_d';
                $params2['quizid_d'] = $quizid;
            }
            $coursewhere2 = $this->build_course_filter($courseid, $params2, '_cd');
            $where2 = '1=1 AND c.questionid > 0' . $timewhere2 . $quizwhere2 . $coursewhere2;
            $params = array_merge($params, $params2);

            $rs = $this->db->get_recordset_sql(
                "SELECT COALESCE(g.model, '') as model, g.mode, g.cnt, g.successful, g.avg_responsetime,
                        d.unique_users, d.unique_questions
                 FROM (
                     SELECT JSON_VALUE(c.metadata, '$.model') as model, c.actiontype as mode,
                            COUNT(DISTINCT c.id) as cnt,
                            {$this->conv_meta_sum_success()} as successful,
                            {$this->conv_meta_avg_responsetime()} as avg_responsetime
                     FROM {local_ai_prog_assist_conversation} c
                     WHERE {$where}
                     GROUP BY JSON_VALUE(c.metadata, '$.model'), c.actiontype
                 ) g
                 CROSS JOIN (
                     SELECT COUNT(DISTINCT c.userid) as unique_users,
                            COUNT(DISTINCT CASE WHEN c.questionid > 0 THEN c.questionid ELSE NULL END) as unique_questions
                     FROM {local_ai_prog_assist_conversation} c
                     WHERE {$where2}
                 ) d",
                $params
            );

            $total = 0; $successful = 0; $totalrt = 0.0; $rtcount = 0;
            $uniqueusers = 0; $uniquequestions = 0;
            $modelcounts = []; $modecounts = [];
            $statsinitialized = false;

            try {
                foreach ($rs as $row) {
                    $cnt = (int)$row->cnt;
                    $succ = (int)$row->successful;
                    $total += $cnt;
                    $successful += $succ;
                    if ($row->avg_responsetime !== null && $cnt > 0) {
                        $totalrt += (float)$row->avg_responsetime * $cnt;
                        $rtcount += $cnt;
                    }
                    $model = $row->model;
                    if (!empty($model)) {
                        $modelcounts[$model] = ($modelcounts[$model] ?? 0) + $cnt;
                    }
                    $mode = $row->mode;
                    if (!empty(trim((string)$mode))) {
                        $modecounts[$mode] = ($modecounts[$mode] ?? 0) + $cnt;
                    }
                    if (!$statsinitialized) {
                        $uniqueusers = (int)$row->unique_users;
                        $uniquequestions = (int)$row->unique_questions;
                        $statsinitialized = true;
                    }
                }
            } finally {
                $rs->close();
            }

            $failed = $total - $successful;
            $successrate = $total > 0 ? round(($successful / $total) * 100, 2) : 0;
            $avgresponsetime = $rtcount > 0 ? round($totalrt / $rtcount, 2) : 0;

            arsort($modelcounts);
            $modelusagearray = [];
            foreach ($modelcounts as $key => $val) {
                $modelusagearray[] = ['key' => $key, 'value' => $val];
            }

            arsort($modecounts);
            $modeusagearray = [];
            foreach ($modecounts as $key => $val) {
                $modeusagearray[] = ['key' => $key, 'value' => $val, 'modelabel' => \local_ai_programming_assistant\helper::get_mode_label($key)];
            }

            return [
                'total_requests' => $total,
                'successful_requests' => $successful,
                'failed_requests' => $failed,
                'success_rate' => $successrate,
                'avg_responsetime' => $avgresponsetime,
                'unique_users' => $uniqueusers,
                'unique_questions' => $uniquequestions,
                'model_usage' => $modelusagearray,
                'mode_usage' => $modeusagearray,
            ];
        });
    }

    /**
     * 获取总揽统计（合并试题模式 + 自由模式，不按 questionid 过滤）
     *
     * 与 get_statistics() 的区别：
     * - 移除 `c.questionid > 0` 过滤，覆盖全部会话
     * - 额外产出 quiz_mode_count / free_mode_count 拆分
     * - 额外产出 unique_pages（自由模式涉及的 DISTINCT contextid 数）和 total_conversations
     *
     * @param int|null $starttime 起始时间戳
     * @param int|null $endtime 结束时间戳
     * @param int|null $courseid 课程 ID（>0 时仅统计该课程下 quiz 的会话；自由模式因无 quizid 会被排除）
     * @return array
     */
    public function get_overview_statistics(?int $starttime = null, ?int $endtime = null, ?int $courseid = null): array {
        $cachekey = $this->cache_key('get_overview_statistics', ['s' => $starttime, 'e' => $endtime, 'c' => $courseid]);

        return $this->cached($cachekey, function() use ($starttime, $endtime, $courseid) {
            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $coursewhere = '';
            if ($courseid !== null && $courseid > 0) {
                // 课程筛选：仅保留该课程下 quiz 的会话。自由模式（quizid=0）会被排除。
                $coursewhere = ' AND c.quizid IN (SELECT q.id FROM {quiz} q WHERE q.course = :courseid)';
                $params['courseid'] = $courseid;
            }
            $where = '1=1' . $timewhere . $coursewhere;

            // CROSS JOIN 的 d 子查询需要独立参数名
            list($timewhere2, $params2) = $this->build_time_where($starttime, $endtime, 'c', 'starttime', '_d');
            $coursewhere2 = '';
            if ($courseid !== null && $courseid > 0) {
                $coursewhere2 = ' AND c.quizid IN (SELECT q.id FROM {quiz} q WHERE q.course = :courseid_d)';
                $params2['courseid_d'] = $courseid;
            }
            $where2 = '1=1' . $timewhere2 . $coursewhere2;
            $params = array_merge($params, $params2);

            $rs = $this->db->get_recordset_sql(
                "SELECT COALESCE(g.model, '') as model, g.mode, g.cnt, g.successful, g.avg_responsetime,
                        g.quiz_mode_count, g.free_mode_count,
                        d.unique_users, d.unique_questions, d.unique_pages, d.total_conversations
                 FROM (
                     SELECT JSON_VALUE(c.metadata, '$.model') as model, c.actiontype as mode,
                            COUNT(DISTINCT c.id) as cnt,
                            {$this->conv_meta_sum_success()} as successful,
                            {$this->conv_meta_avg_responsetime()} as avg_responsetime,
                            SUM(CASE WHEN c.questionid > 0 THEN 1 ELSE 0 END) as quiz_mode_count,
                            SUM(CASE WHEN c.questionid = 0 THEN 1 ELSE 0 END) as free_mode_count
                     FROM {local_ai_prog_assist_conversation} c
                     WHERE {$where}
                     GROUP BY JSON_VALUE(c.metadata, '$.model'), c.actiontype
                 ) g
                 CROSS JOIN (
                     SELECT COUNT(DISTINCT c.userid) as unique_users,
                            COUNT(DISTINCT CASE WHEN c.questionid > 0 THEN c.questionid ELSE NULL END) as unique_questions,
                            COUNT(DISTINCT CASE WHEN c.questionid = 0 THEN c.contextid ELSE NULL END) as unique_pages,
                            COUNT(DISTINCT c.id) as total_conversations
                     FROM {local_ai_prog_assist_conversation} c
                     WHERE {$where2}
                 ) d",
                $params
            );

            $total = 0; $successful = 0; $totalrt = 0.0; $rtcount = 0;
            $uniqueusers = 0; $uniquequestions = 0; $uniquepages = 0; $totalconversations = 0;
            $quizmodecount = 0; $freemodecount = 0;
            $modelcounts = []; $modecounts = [];
            $statsinitialized = false;

            try {
                foreach ($rs as $row) {
                    $cnt = (int)$row->cnt;
                    $succ = (int)$row->successful;
                    $total += $cnt;
                    $successful += $succ;
                    if ($row->avg_responsetime !== null && $cnt > 0) {
                        $totalrt += (float)$row->avg_responsetime * $cnt;
                        $rtcount += $cnt;
                    }
                    $quizmodecount += (int)$row->quiz_mode_count;
                    $freemodecount += (int)$row->free_mode_count;
                    $model = $row->model;
                    if (!empty($model)) {
                        $modelcounts[$model] = ($modelcounts[$model] ?? 0) + $cnt;
                    }
                    $mode = $row->mode;
                    if (!empty(trim((string)$mode))) {
                        $modecounts[$mode] = ($modecounts[$mode] ?? 0) + $cnt;
                    }
                    if (!$statsinitialized) {
                        $uniqueusers = (int)$row->unique_users;
                        $uniquequestions = (int)$row->unique_questions;
                        $uniquepages = (int)$row->unique_pages;
                        $totalconversations = (int)$row->total_conversations;
                        $statsinitialized = true;
                    }
                }
            } finally {
                $rs->close();
            }

            $failed = $total - $successful;
            $successrate = $total > 0 ? round(($successful / $total) * 100, 2) : 0;
            $avgresponsetime = $rtcount > 0 ? round($totalrt / $rtcount, 2) : 0;

            arsort($modelcounts);
            $modelusagearray = [];
            foreach ($modelcounts as $key => $val) {
                $modelusagearray[] = ['key' => $key, 'value' => $val, 'percent' => $total > 0 ? round($val / $total * 100, 1) : 0];
            }

            arsort($modecounts);
            $modeusagearray = [];
            foreach ($modecounts as $key => $val) {
                $modeusagearray[] = [
                    'key' => $key,
                    'value' => $val,
                    'percent' => $total > 0 ? round($val / $total * 100, 1) : 0,
                    'modelabel' => \local_ai_programming_assistant\helper::get_mode_label($key),
                ];
            }

            return [
                'total_requests' => $total,
                'successful_requests' => $successful,
                'failed_requests' => $failed,
                'success_rate' => $successrate,
                'avg_responsetime' => $avgresponsetime,
                'unique_users' => $uniqueusers,
                'unique_questions' => $uniquequestions,
                'unique_pages' => $uniquepages,
                'total_conversations' => $totalconversations,
                'quiz_mode_count' => $quizmodecount,
                'free_mode_count' => $freemodecount,
                'model_usage' => $modelusagearray,
                'mode_usage' => $modeusagearray,
            ];
        });
    }

    /**
     * 获取每日趋势统计（合并试题模式 + 自由模式，不按 questionid 过滤）
     *
     * 与 get_free_log_daily_stats() 的区别：移除 `c.questionid = 0` 过滤，覆盖全部会话。
     * 用于总揽页 CSS 柱状图渲染。
     *
     * @param int $starttime 起始时间戳
     * @param int $endtime 结束时间戳
     * @param int|null $courseid 课程 ID（>0 时仅统计该课程下 quiz 的会话）
     * @return array 每日数据数组，每项含 date/total/successful/failed/avg_responsetime/success_rate
     */
    public function get_daily_stats(int $starttime, int $endtime, ?int $courseid = null): array {
        $cachekey = $this->cache_key('get_daily_stats', ['s' => $starttime, 'e' => $endtime, 'c' => $courseid]);
        return $this->cached($cachekey, function() use ($starttime, $endtime, $courseid) {
            $day = 86400;
            $params = ['starttime' => $starttime, 'endtime' => $endtime, 'day' => $day];
            $coursewhere = '';
            if ($courseid !== null && $courseid > 0) {
                $coursewhere = ' AND c.quizid IN (SELECT q.id FROM {quiz} q WHERE q.course = :courseid)';
                $params['courseid'] = $courseid;
            }
            $rs = $this->db->get_recordset_sql(
                "SELECT FLOOR(c.starttime / :day) as daynum,
                        COUNT(DISTINCT c.id) as total,
                        {$this->conv_meta_sum_success()} as successful,
                        {$this->conv_meta_avg_responsetime()} as avg_responsetime
                 FROM {local_ai_prog_assist_conversation} c
                 WHERE c.starttime >= :starttime AND c.starttime <= :endtime{$coursewhere}
                 GROUP BY daynum ORDER BY daynum ASC",
                $params
            );

            $result = $this->collect_recordset($rs, function ($record) use ($day) {
                $date = date('Y-m-d', $record->daynum * $day);
                $total = (int)$record->total;
                $successful = (int)$record->successful;
                return [
                    'date' => $date,
                    'total' => $total,
                    'successful' => $successful,
                    'failed' => $total - $successful,
                    'avg_responsetime' => $record->avg_responsetime !== null ? round((float)$record->avg_responsetime, 2) : 0,
                    'success_rate' => $total > 0
                        ? round(($successful / $total) * 100, 2) : 0,
                ];
            });

            return $result;
        });
    }

    public function get_top_questions(int $limit = 10, ?int $starttime = null, ?int $endtime = null,
        int $offset = 0, string $sort = 'total_requests', string $order = 'DESC',
        ?int $courseid = null): array {
        $cachekey = $this->cache_key('get_top_questions', [
            'l' => $limit, 's' => $starttime, 'e' => $endtime, 'o' => $offset, 'st' => $sort, 'od' => $order, 'cid' => $courseid,
        ]);
        return $this->cached($cachekey, function() use ($limit, $starttime, $endtime, $offset, $sort, $order, $courseid) {
            $limit = $this->safe_limit($limit);

            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $coursewhere = $this->build_course_filter($courseid, $params, 'cq_');
            $where = '1=1' . $timewhere . $coursewhere;

            $sortfield = self::QUESTION_SORT_FIELDS[$sort] ?? 'total_requests';
            $sortdir = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

            $sql = "SELECT c.questionid as questionid, q.name as questionname,
                            COUNT(DISTINCT c.id) as total_requests,
                            {$this->conv_meta_sum_success()} as successful_requests,
                            {$this->conv_meta_sum_failed()} as failed_requests,
                            {$this->conv_meta_avg_responsetime()} as avg_responsetime,
                            MAX(c.starttime) as last_timecreated,
                            MAX(c.quizid) as quizid
                     FROM {local_ai_prog_assist_conversation} c
                     LEFT JOIN {question} q ON q.id = c.questionid
                     WHERE {$where} AND c.questionid > 0
                     GROUP BY c.questionid, q.name ORDER BY {$sortfield} {$sortdir}";

            $rs = $this->db->get_recordset_sql($sql, $params, $offset, $limit);

            $result = $this->collect_recordset($rs, function ($record) {
                return [
                    'questionid' => $record->questionid,
                    'questionname' => is_string($record->questionname) ? $record->questionname : ('Question #' . $record->questionid),
                    'quizid' => (int)($record->quizid ?? 0),
                    'total_requests' => $record->total_requests,
                    'successful_requests' => $record->successful_requests,
                    'failed_requests' => $record->failed_requests,
                    'success_rate' => $record->total_requests > 0 ? round(($record->successful_requests / $record->total_requests) * 100, 2) : 0,
                    'avg_responsetime' => $record->avg_responsetime !== null ? round((float)$record->avg_responsetime, 2) : 0,
                    'last_timecreated' => (int)$record->last_timecreated,
                    'last_timecreated_str' => userdate($record->last_timecreated, '%Y-%m-%d %H:%M'),
                ];
            });

            return $result;
        });
    }

    /**
     * 统计热门题目的总数
     */
    public function count_top_questions(?int $starttime = null, ?int $endtime = null, ?int $courseid = null): int {
        $cachekey = $this->cache_key('count_top_questions', ['s' => $starttime, 'e' => $endtime, 'c' => $courseid]);
        return $this->cached($cachekey, function() use ($starttime, $endtime, $courseid) {
            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $coursewhere = $this->build_course_filter($courseid, $params, 'ctq_');
            $where = '1=1' . $timewhere . $coursewhere;

            // 注意：count 查询与数据查询分离是 Moodle DB API 的设计限制
            // Moodle 不支持 SQL_CALC_FOUND_ROWS，因此需要独立的 COUNT 查询
            return (int)$this->db->get_field_sql(
                "SELECT COUNT(DISTINCT c.questionid)
                 FROM {local_ai_prog_assist_conversation} c
                 WHERE {$where} AND c.questionid > 0",
                $params
            );
        });
    }

    public function get_student_statistics(int $limit = 20, ?int $starttime = null, ?int $endtime = null,
        string $search = '', int $offset = 0, string $sort = 'total_requests', string $order = 'DESC',
        ?int $courseid = null): array {
        $cachekey = $this->cache_key('get_student_statistics', [
            'l' => $limit, 's' => $starttime, 'e' => $endtime, 'q' => $search, 'o' => $offset, 'st' => $sort, 'od' => $order, 'cid' => $courseid,
        ]);
        return $this->cached($cachekey, function() use ($limit, $starttime, $endtime, $search, $offset, $sort, $order, $courseid) {
            $limit = $this->safe_limit($limit);

            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $searchwhere = $this->build_user_search_where($search, $params, 'search');
            $coursewhere = $this->build_course_filter($courseid, $params, 'sc_');

            $where = '1=1' . $timewhere . $searchwhere . $coursewhere . ' AND c.questionid > 0';

            $sortfield = self::STUDENT_SORT_FIELDS[$sort] ?? 'total_requests';
            $sortdir = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

            $rs = $this->db->get_recordset_sql(
                "SELECT c.userid, " . self::$USER_FIELDS . ",
                        COUNT(DISTINCT c.id) as total_requests,
                        {$this->conv_meta_sum_success()} as successful_requests,
                        {$this->conv_meta_sum_failed()} as failed_requests,
                        {$this->conv_meta_avg_responsetime()} as avg_responsetime,
                        COUNT(DISTINCT CASE WHEN c.questionid > 0 THEN c.questionid ELSE NULL END) as unique_questions,
                        COUNT(DISTINCT c.actiontype) as unique_modes,
                        MAX(c.starttime) as last_timecreated
                 FROM {local_ai_prog_assist_conversation} c
                 JOIN {user} u ON u.id = c.userid
                 WHERE {$where}
                 GROUP BY " . self::$USER_GROUP_BY . "
                 ORDER BY {$sortfield} {$sortdir}",
                $params, $offset, $limit
            );

            $result = $this->collect_recordset($rs, function ($record) {
                return [
                    'userid' => $record->userid,
                    'username' => $record->username,
                    'fullname' => fullname($record),
                    'total_requests' => $record->total_requests,
                    'successful_requests' => $record->successful_requests,
                    'failed_requests' => $record->failed_requests,
                    'success_rate' => $record->total_requests > 0 ? round(($record->successful_requests / $record->total_requests) * 100, 2) : 0,
                    'avg_responsetime' => $record->avg_responsetime !== null ? round((float)$record->avg_responsetime, 2) : 0,
                    'unique_questions' => $record->unique_questions,
                    'unique_modes' => $record->unique_modes,
                    'last_timecreated' => (int)$record->last_timecreated,
                    'last_timecreated_str' => userdate($record->last_timecreated, '%Y-%m-%d %H:%M'),
                ];
            });

            return $result;
        });
    }

    /**
     * 统计学生总数
     */
    public function count_student_statistics(?int $starttime = null, ?int $endtime = null, string $search = '', ?int $courseid = null): int {
        $cachekey = $this->cache_key('count_student_statistics', ['s' => $starttime, 'e' => $endtime, 'q' => $search, 'c' => $courseid]);
        return $this->cached($cachekey, function() use ($starttime, $endtime, $search, $courseid) {
            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');

            // 无搜索条件时跳过 JOIN {user}，直接 COUNT(DISTINCT userid) 走索引
            $searchwhere = '';
            $userjoin = '';
            if ($search !== '') {
                $userjoin = 'JOIN {user} u ON u.id = c.userid';
                $searchwhere = $this->build_user_search_where($search, $params, 'search');
            }
            $coursewhere = $this->build_course_filter($courseid, $params, 'cst_');

            return (int)$this->db->get_field_sql(
                "SELECT COUNT(DISTINCT c.userid)
                 FROM {local_ai_prog_assist_conversation} c
                 {$userjoin}
                 WHERE c.questionid > 0{$timewhere}{$coursewhere}{$searchwhere}",
                $params
            );
        });
    }

    public function get_quiz_question_stats(int $quizid, ?int $starttime = null, ?int $endtime = null,
        int $limit = 5000, ?array $userids = null, int $offset = 0,
        string $sort = 'questionid', string $order = 'ASC'): array {
        $cachekey = $this->cache_key('get_quiz_question_stats', [
            'q' => $quizid, 's' => $starttime, 'e' => $endtime, 'l' => $limit,
            'u' => $userids, 'o' => $offset, 'st' => $sort, 'od' => $order,
        ]);
        return $this->cached($cachekey, function() use ($quizid, $starttime, $endtime, $limit, $userids, $offset, $sort, $order) {
            $limit = $this->safe_limit($limit);

            list($timewhere, $timeparams) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $params = array_merge(['quizid' => $quizid], $timeparams);

            $userfilter = $this->build_user_filter($userids, $params, 'gu_');

            $sortfield = self::QUESTION_SORT_FIELDS[$sort] ?? 'questionid';
            $sortdir = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

            // 从 quiz_slots 出发 LEFT JOIN 日志聚合数据，确保显示测验内所有试题（含无AI使用记录的）
            // 版本解析：当 qr.version IS NULL（"始终最新"）时，仅匹配最新非草稿版本，
            // 避免同一题目的所有版本都产生行（导致重复）。与 Moodle 核心 qbank_helper 模式一致。
            // 聚合维度：按 questionbankentryid 而非 questionid 分组，确保同一题库条目下
            // 所有版本（含旧版）的 AI 使用记录都被正确聚合到当前版本行上。
            $sql = "SELECT q.id AS questionid, q.name AS questionname,
                           COALESCE(agg.total_requests, 0) AS total_requests,
                           COALESCE(agg.successful_requests, 0) AS successful_requests,
                           COALESCE(agg.failed_requests, 0) AS failed_requests,
                           COALESCE(agg.avg_responsetime, 0) AS avg_responsetime,
                           COALESCE(agg.unique_users, 0) AS unique_users,
                           COALESCE(agg.last_timecreated, 0) AS last_timecreated,
                           COALESCE(agg.mode_question_analysis_count, 0) AS mode_question_analysis_count,
                           COALESCE(agg.mode_idea_organization_count, 0) AS mode_idea_organization_count,
                           COALESCE(agg.mode_code_debugging_count, 0) AS mode_code_debugging_count,
                           COALESCE(agg.mode_free_count, 0) AS mode_free_count
                    FROM {quiz_slots} qs
                    JOIN {question_references} qr ON qr.itemid = qs.id
                        AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
                    JOIN {question_versions} qv ON qv.questionbankentryid = qr.questionbankentryid
                        AND qv.version = COALESCE(qr.version,
                            (SELECT MAX(v.version) FROM {question_versions} v
                             WHERE v.questionbankentryid = qr.questionbankentryid AND v.status <> 'draft'),
                            (SELECT MAX(v.version) FROM {question_versions} v
                             WHERE v.questionbankentryid = qr.questionbankentryid))
                    JOIN {question} q ON q.id = qv.questionid
                    LEFT JOIN (
                        SELECT qv2.questionbankentryid,
                               COUNT(DISTINCT c.id) AS total_requests,
                               {$this->conv_meta_sum_success()} AS successful_requests,
                               {$this->conv_meta_sum_failed()} AS failed_requests,
                               {$this->conv_meta_avg_responsetime()} AS avg_responsetime,
                               COUNT(DISTINCT c.userid) AS unique_users,
                               MAX(c.starttime) AS last_timecreated,
                               SUM(CASE WHEN c.actiontype = 'question_analysis' THEN 1 ELSE 0 END) AS mode_question_analysis_count,
                               SUM(CASE WHEN c.actiontype = 'idea_organization' THEN 1 ELSE 0 END) AS mode_idea_organization_count,
                               SUM(CASE WHEN c.actiontype = 'code_debugging' THEN 1 ELSE 0 END) AS mode_code_debugging_count,
                               SUM(CASE WHEN c.actiontype = 'free' THEN 1 ELSE 0 END) AS mode_free_count
                        FROM {local_ai_prog_assist_conversation} c
                        JOIN {question_versions} qv2 ON qv2.questionid = c.questionid
                        WHERE c.quizid = :quizid2 AND c.questionid > 0{$timewhere}{$userfilter}
                        GROUP BY qv2.questionbankentryid
                    ) agg ON agg.questionbankentryid = qv.questionbankentryid
                    WHERE qs.quizid = :quizid
                    ORDER BY {$sortfield} {$sortdir}";

            // 子查询需要独立的参数名 :quizid2，因为 Moodle 的参数化查询不允许内外层使用相同命名参数
            $params['quizid2'] = $quizid;

            $rs = $this->db->get_recordset_sql($sql, $params, $offset, $limit);

            $result = $this->collect_recordset($rs, function ($record) {
                return [
                    'questionid' => $record->questionid,
                    'questionname' => is_string($record->questionname) ? $record->questionname : ('Question #' . $record->questionid),
                    'total_requests' => $record->total_requests,
                    'successful_requests' => $record->successful_requests,
                    'failed_requests' => $record->failed_requests,
                    'success_rate' => $record->total_requests > 0 ? round(($record->successful_requests / $record->total_requests) * 100, 2) : 0,
                    'avg_responsetime' => $record->avg_responsetime !== null ? round((float)$record->avg_responsetime, 2) : 0,
                    'unique_users' => $record->unique_users,
                    'last_timecreated' => (int)$record->last_timecreated,
                    'last_timecreated_str' => $record->last_timecreated > 0 ? userdate($record->last_timecreated, '%Y-%m-%d %H:%M') : '-',
                    'mode_question_analysis_count' => (int)($record->mode_question_analysis_count ?? 0),
                    'mode_idea_organization_count' => (int)($record->mode_idea_organization_count ?? 0),
                    'mode_code_debugging_count' => (int)($record->mode_code_debugging_count ?? 0),
                    'mode_free_count' => (int)($record->mode_free_count ?? 0),
                ];
            });

            return $result;
        });
    }

    /**
     * 统计测验中的题目总数（不受时间/用户过滤影响，与主查询 LEFT JOIN 逻辑一致）
     */
    public function count_quiz_question_stats(int $quizid): int {
        $cachekey = $this->cache_key('count_quiz_question_stats', ['q' => $quizid]);
        return $this->cached($cachekey, function() use ($quizid) {
            // 注意：count 查询与数据查询分离是 Moodle DB API 的设计限制
            // Moodle 不支持 SQL_CALC_FOUND_ROWS，因此需要独立的 COUNT 查询
            return (int)$this->db->get_field_sql(
                "SELECT COUNT(DISTINCT q.id)
                 FROM {quiz_slots} qs
                 JOIN {question_references} qr ON qr.itemid = qs.id
                     AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
                 JOIN {question_versions} qv ON qv.questionbankentryid = qr.questionbankentryid
                     AND qv.version = COALESCE(qr.version,
                         (SELECT MAX(v.version) FROM {question_versions} v
                          WHERE v.questionbankentryid = qr.questionbankentryid AND v.status <> 'draft'),
                         (SELECT MAX(v.version) FROM {question_versions} v
                          WHERE v.questionbankentryid = qr.questionbankentryid))
                 JOIN {question} q ON q.id = qv.questionid
                 WHERE qs.quizid = :quizid",
                ['quizid' => $quizid]
            );
        });
    }

    public function get_quiz_student_stats(int $quizid, ?int $starttime = null, ?int $endtime = null,
        int $limit = 5000, ?array $userids = null, string $search = '',
        int $offset = 0, string $sort = 'total_requests', string $order = 'DESC'): array {
        $cachekey = $this->cache_key('get_quiz_student_stats', [
            'q' => $quizid, 's' => $starttime, 'e' => $endtime, 'l' => $limit,
            'u' => $userids, 'se' => $search, 'o' => $offset, 'st' => $sort, 'od' => $order,
        ]);
        return $this->cached($cachekey, function() use ($quizid, $starttime, $endtime, $limit, $userids, $search, $offset, $sort, $order) {
            $limit = $this->safe_limit($limit);

            list($timewhere, $timeparams) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $params = array_merge(['quizid' => $quizid], $timeparams);

            $userfilter = $this->build_user_filter($userids, $params, 'qu_');
            $searchwhere = $this->build_user_search_where($search, $params, 'qse');

            $sortfield = self::STUDENT_SORT_FIELDS[$sort] ?? 'total_requests';
            $sortdir = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

            $rs = $this->db->get_recordset_sql(
                "SELECT c.userid, " . self::$USER_FIELDS . ",
                        COUNT(DISTINCT c.id) as total_requests,
                        {$this->conv_meta_sum_success()} as successful_requests,
                        {$this->conv_meta_sum_failed()} as failed_requests,
                        {$this->conv_meta_avg_responsetime()} as avg_responsetime,
                        COUNT(DISTINCT CASE WHEN c.questionid > 0 THEN c.questionid ELSE NULL END) as unique_questions,
                        MAX(c.starttime) as last_timecreated
                 FROM {local_ai_prog_assist_conversation} c
                 JOIN {user} u ON u.id = c.userid
                 WHERE c.quizid = :quizid AND c.questionid > 0{$timewhere}{$userfilter}{$searchwhere}
                 GROUP BY " . self::$USER_GROUP_BY . "
                 ORDER BY {$sortfield} {$sortdir}",
                $params, $offset, $limit
            );

            $result = $this->collect_recordset($rs, function ($record) {
                return [
                    'userid' => $record->userid,
                    'username' => $record->username,
                    'fullname' => fullname($record),
                    'total_requests' => $record->total_requests,
                    'successful_requests' => $record->successful_requests,
                    'failed_requests' => $record->failed_requests,
                    'success_rate' => $record->total_requests > 0 ? round(($record->successful_requests / $record->total_requests) * 100, 2) : 0,
                    'avg_responsetime' => $record->avg_responsetime !== null ? round((float)$record->avg_responsetime, 2) : 0,
                    'unique_questions' => $record->unique_questions,
                    'last_timecreated' => (int)$record->last_timecreated,
                    'last_timecreated_str' => userdate($record->last_timecreated, '%Y-%m-%d %H:%M'),
                ];
            });

            return $result;
        });
    }

    /**
     * 统计测验中的学生总数
     */
    public function count_quiz_student_stats(int $quizid, ?int $starttime = null, ?int $endtime = null, ?array $userids = null, string $search = ''): int {
        $cachekey = $this->cache_key('count_quiz_student_stats', [
            'q' => $quizid, 's' => $starttime, 'e' => $endtime, 'u' => $userids, 'se' => $search,
        ]);
        return $this->cached($cachekey, function() use ($quizid, $starttime, $endtime, $userids, $search) {
            list($timewhere, $timeparams) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $params = array_merge(['quizid' => $quizid], $timeparams);

            $userfilter = $this->build_user_filter($userids, $params, 'cu_');

            // 无搜索条件且无用户ID过滤时跳过 JOIN {user}，直接 COUNT(DISTINCT userid) 走索引
            $searchwhere = '';
            $userjoin = '';
            if ($search !== '') {
                $userjoin = 'JOIN {user} u ON u.id = c.userid';
                $searchwhere = $this->build_user_search_where($search, $params, 'cse');
            }

            return (int)$this->db->get_field_sql(
                "SELECT COUNT(DISTINCT c.userid)
                 FROM {local_ai_prog_assist_conversation} c
                 {$userjoin}
                 WHERE c.quizid = :quizid AND c.questionid > 0{$timewhere}{$userfilter}{$searchwhere}",
                $params
            );
        });
    }

    public function get_quizzes_with_usage(): array {
        $key = $this->cache_key('get_quizzes_with_usage', []);
        return $this->cached($key, function() {
            $rs = $this->db->get_recordset_sql(
                "SELECT q.id AS quizid, q.name AS quiz_name, q.course AS courseid, c2.fullname AS coursename
                 FROM {quiz} q
                 JOIN {course} c2 ON c2.id = q.course
                 WHERE EXISTS (
                     SELECT 1 FROM {local_ai_prog_assist_conversation} conv
                     WHERE conv.quizid = q.id AND conv.quizid > 0
                 )
                 ORDER BY c2.fullname, q.name",
                [], 0, 200
            );

            $result = $this->collect_recordset($rs, function ($r) {
                $item = new \stdClass();
                $item->quizid = (int)$r->quizid;
                $item->quiz_name = $r->quiz_name;
                $item->courseid = (int)$r->courseid;
                $item->coursename = $r->coursename;
                return $item;
            });

            return $result;
        });
    }

    public function get_quiz_info(int $quizid): ?\stdClass {
        $cachekey = $this->cache_key('get_quiz_info', ['id' => $quizid]);
        return $this->cached($cachekey, function() use ($quizid) {
            $record = $this->db->get_record('quiz', ['id' => $quizid], 'id, name, course');
            return $record ?: null;
        });
    }

    public function get_question_info(int $questionid): ?\stdClass {
        $cachekey = $this->cache_key('get_question_info', ['id' => $questionid]);
        return $this->cached($cachekey, function() use ($questionid) {
            $record = $this->db->get_record('question', ['id' => $questionid], 'id, name');
            return $record ?: null;
        });
    }

    public function get_quiz_question_followups(int $quizid, ?int $starttime = null, ?int $endtime = null, int $limit = 10, int $offset = 0, ?array $userids = null): array {
        $cachekey = $this->cache_key('get_quiz_question_followups', [
            'q' => $quizid, 's' => $starttime, 'e' => $endtime, 'l' => $limit, 'o' => $offset, 'u' => $userids,
        ]);
        return $this->cached($cachekey, function() use ($quizid, $starttime, $endtime, $limit, $offset, $userids) {
            return $this->get_quiz_followups($quizid, $starttime, $endtime, $limit, $offset, $userids, 'question');
        });
    }

    public function get_quiz_student_followups(int $quizid, ?int $starttime = null, ?int $endtime = null, int $limit = 10, int $offset = 0, ?array $userids = null): array {
        $cachekey = $this->cache_key('get_quiz_student_followups', [
            'q' => $quizid, 's' => $starttime, 'e' => $endtime, 'l' => $limit, 'o' => $offset, 'u' => $userids,
        ]);
        return $this->cached($cachekey, function() use ($quizid, $starttime, $endtime, $limit, $offset, $userids) {
            return $this->get_quiz_followups($quizid, $starttime, $endtime, $limit, $offset, $userids, 'student');
        });
    }

    /**
     * 统计测验中有追问记录的题目数
     */
    public function count_quiz_question_followups(int $quizid, ?int $starttime = null, ?int $endtime = null, ?array $userids = null): int {
        $cachekey = $this->cache_key('count_quiz_question_followups', [
            'q' => $quizid, 's' => $starttime, 'e' => $endtime, 'u' => $userids,
        ]);
        return $this->cached($cachekey, function() use ($quizid, $starttime, $endtime, $userids) {
            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $params['quizid'] = $quizid;

            $userfilter = $this->build_user_filter($userids, $params, 'cqf_');

            return (int)$this->db->get_field_sql(
                "SELECT COUNT(DISTINCT c.questionid)
                 FROM {local_ai_prog_assist_conversation} c
                 WHERE c.quizid = :quizid{$timewhere}{$userfilter} AND c.questionid > 0",
                $params
            );
        });
    }

    /**
     * 统计测验中有追问记录的学生数
     */
    public function count_quiz_student_followups(int $quizid, ?int $starttime = null, ?int $endtime = null, ?array $userids = null): int {
        $cachekey = $this->cache_key('count_quiz_student_followups', [
            'q' => $quizid, 's' => $starttime, 'e' => $endtime, 'u' => $userids,
        ]);
        return $this->cached($cachekey, function() use ($quizid, $starttime, $endtime, $userids) {
            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $params['quizid'] = $quizid;

            $userfilter = $this->build_user_filter($userids, $params, 'csf_');

            return (int)$this->db->get_field_sql(
                "SELECT COUNT(DISTINCT c.userid)
                 FROM {local_ai_prog_assist_conversation} c
                 WHERE c.quizid = :quizid{$timewhere}{$userfilter} AND c.questionid > 0",
                $params
            );
        });
    }

    /**
     * 获取测验追问记录的通用方法
     *
     * 支持按试题分组（question）和按学生分组（student）两种模式。
     */
    private function get_quiz_followups(int $quizid, ?int $starttime = null, ?int $endtime = null, int $limit = 10, int $offset = 0, ?array $userids = null, string $groupby = 'question'): array {
        $limit = $this->safe_limit($limit);

        list($timewhere, $timeparams) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
        $params = array_merge(['quizid' => $quizid], $timeparams);

        $userfilter = $this->build_user_filter($userids, $params, $groupby === 'question' ? 'qf_' : 'sf_');

        // 先按目标维度（questionid / userid）分页，再取该维度下全部组合行。
        // 避免 SQL 层按 (userid, questionid, actiontype) 组合行分页后，PHP 分组
        // 使每页条目数与分页总数（按 questionid / userid 去重）不一致导致翻页错位。
        $dimfield = $groupby === 'question' ? 'c.questionid' : 'c.userid';
        $dimids = $this->db->get_records_sql(
            "SELECT {$dimfield} AS dimid, MAX(c.starttime) AS last_timecreated
             FROM {local_ai_prog_assist_conversation} c
             WHERE c.quizid = :quizid{$timewhere}{$userfilter} AND c.questionid > 0
             GROUP BY {$dimfield}
             ORDER BY {$dimfield} ASC",
            $params, $offset, $limit
        );
        if (empty($dimids)) {
            return [];
        }
        $dimvalues = array_map('intval', array_keys($dimids));

        list($inSQL, $inParams) = $this->db->get_in_or_equal($dimvalues, SQL_PARAMS_NAMED,
            $groupby === 'question' ? 'qfid' : 'sfid');
        $params = array_merge($params, $inParams);

        $sql = "SELECT c.userid, c.questionid, c.actiontype as mode,
                        COUNT(DISTINCT c.id) as entry_count,
                        MAX(c.starttime) as last_timecreated,
                        " . self::$USER_FIELDS . ",
                        q.name as questionname
                 FROM {local_ai_prog_assist_conversation} c
                 JOIN {user} u ON u.id = c.userid
                 LEFT JOIN {question} q ON q.id = c.questionid
                 WHERE c.quizid = :quizid{$timewhere}{$userfilter} AND c.questionid > 0 AND {$dimfield} {$inSQL}
                 GROUP BY c.userid, c.questionid, c.actiontype, " . self::$USER_GROUP_BY . ", q.name
                 ORDER BY {$dimfield} ASC, last_timecreated DESC";

        $recordset = $this->db->get_recordset_sql($sql, $params);

        $records = $this->collect_recordset($recordset);

        if (empty($records)) {
            return [];
        }

        if ($groupby === 'question') {
            return $this->group_followups_by_question_optimized($records, $quizid);
        } else {
            return $this->group_followups_by_student_optimized($records, $quizid);
        }
    }

    /**
     * 按试题分组整理追问记录（优化版 - 使用 SQL 聚合结果）
     */
    private function group_followups_by_question_optimized(array $records, int $quizid): array {
        $grouped = [];
        foreach ($records as $record) {
            $qid = (int)$record->questionid;
            $uid = (int)$record->userid;
            $fullname = fullname($record);
            $mode = $record->mode ?: 'free';
            $timecreated = (int)$record->last_timecreated;
            $questionname = is_string($record->questionname) ? $record->questionname : ('Question #' . $qid);

            if (!isset($grouped[$qid])) {
                $grouped[$qid] = [
                    'questionid' => $qid,
                    'questionname' => $questionname,
                    'student_count' => 0,
                    'students' => [],
                ];
            }

            if (!isset($grouped[$qid]['students'][$uid])) {
                $grouped[$qid]['students'][$uid] = [
                    'userid' => $uid, 'username' => $record->username,
                    'fullname' => $fullname, 'entry_count' => 0,
                    'modes' => [],
                ];
                $grouped[$qid]['student_count']++;
            }

            $grouped[$qid]['students'][$uid]['entry_count'] += (int)$record->entry_count;

            $grouped[$qid]['students'][$uid]['modes'][] = [
                'actiontype' => $mode,
                'entry_count' => (int)$record->entry_count,
                'last_timecreated' => $timecreated,
                'last_timecreated_str' => userdate($timecreated, '%Y-%m-%d %H:%M'),
                'mode_label' => \local_ai_programming_assistant\helper::get_mode_label($mode),
                'quizid' => $quizid,
                'questionid' => $qid,
                'userid' => $uid,
                'fullname' => $fullname,
                'username' => $record->username,
                'questionname' => $questionname,
            ];
        }

        $result = [];
        foreach ($grouped as $questionGroup) {
            foreach ($questionGroup['students'] as &$student) {
                $student['mode_count'] = count($student['modes']);
            }
            unset($student);
            $questionGroup['students'] = array_values($questionGroup['students']);
            $result[] = $questionGroup;
        }

        return $result;
    }

    /**
     * 按学生分组整理追问记录（优化版 - 使用 SQL 聚合结果）
     */
    private function group_followups_by_student_optimized(array $records, int $quizid): array {
        $grouped = [];
        foreach ($records as $record) {
            $uid = (int)$record->userid;
            $qid = (int)$record->questionid;
            $fullname = fullname($record);
            $mode = $record->mode ?: 'free';
            $timecreated = (int)$record->last_timecreated;
            $questionname = is_string($record->questionname) ? $record->questionname : ('Question #' . $qid);

            if (!isset($grouped[$uid])) {
                $grouped[$uid] = [
                    'userid' => $uid, 'username' => $record->username,
                    'fullname' => $fullname, 'question_count' => 0,
                    'questions' => [],
                ];
            }

            if (!isset($grouped[$uid]['questions'][$qid])) {
                $grouped[$uid]['questions'][$qid] = [
                    'questionid' => $qid,
                    'questionname' => $questionname,
                    'entry_count' => 0, 'modes' => [],
                ];
                $grouped[$uid]['question_count']++;
            }

            $grouped[$uid]['questions'][$qid]['entry_count'] += (int)$record->entry_count;

            $grouped[$uid]['questions'][$qid]['modes'][] = [
                'actiontype' => $mode,
                'entry_count' => (int)$record->entry_count,
                'last_timecreated' => $timecreated,
                'last_timecreated_str' => userdate($timecreated, '%Y-%m-%d %H:%M'),
                'mode_label' => \local_ai_programming_assistant\helper::get_mode_label($mode),
                'quizid' => $quizid,
                'questionid' => $qid,
                'userid' => $uid,
                'questionname' => $questionname,
            ];
        }

        $result = [];
        foreach ($grouped as $studentGroup) {
            foreach ($studentGroup['questions'] as &$question) {
                $question['mode_count'] = count($question['modes']);
                foreach ($question['modes'] as $idx => &$mode) {
                    $mode['is_first'] = ($idx === 0);
                }
                unset($mode);
            }
            unset($question);
            $studentGroup['questions'] = array_values($studentGroup['questions']);
            $result[] = $studentGroup;
        }

        return $result;
    }

    public function get_question_student_usage(int $quizid, int $questionid, ?int $starttime = null, ?int $endtime = null, int $limit = 5000, ?array $userids = null, int $offset = 0): array {
        $cachekey = $this->cache_key('get_question_student_usage', [
            'q' => $quizid, 'qi' => $questionid, 's' => $starttime,
            'e' => $endtime, 'l' => $limit, 'u' => $userids, 'o' => $offset,
        ]);
        return $this->cached($cachekey, function() use ($quizid, $questionid, $starttime, $endtime, $limit, $userids, $offset) {
            $limit = $this->safe_limit($limit);

            list($timewhere, $timeparams) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $params = array_merge(['quizid' => $quizid, 'questionid' => $questionid], $timeparams);

            $userfilter = $this->build_user_filter($userids, $params, 'qu_');

            $rs = $this->db->get_recordset_sql(
                "SELECT c.userid, " . self::$USER_FIELDS . ",
                        COUNT(DISTINCT c.id) as total_requests,
                        {$this->conv_meta_sum_success()} as successful_requests,
                        {$this->conv_meta_avg_responsetime()} as avg_responsetime
                 FROM {local_ai_prog_assist_conversation} c
                 JOIN {user} u ON u.id = c.userid
                 WHERE c.quizid = :quizid AND c.questionid = :questionid{$timewhere}{$userfilter}
                 GROUP BY " . self::$USER_GROUP_BY . "
                 ORDER BY total_requests DESC",
                $params, $offset, $limit
            );

            $result = $this->collect_recordset($rs, function ($record) {
                $row = [
                    'userid' => $record->userid, 'username' => $record->username,
                    'fullname' => fullname($record),
                    'total_requests' => $record->total_requests,
                    'successful_requests' => $record->successful_requests,
                ];
                return array_merge($row, $this->extract_common_stats($record));
            });

            return $result;
        });
    }

    public function count_question_students(int $quizid, int $questionid, ?int $starttime = null, ?int $endtime = null, ?array $userids = null): int {
        $cachekey = $this->cache_key('count_question_students', [
            'q' => $quizid, 'qi' => $questionid, 's' => $starttime, 'e' => $endtime, 'u' => $userids,
        ]);
        return $this->cached($cachekey, function() use ($quizid, $questionid, $starttime, $endtime, $userids) {
            list($timewhere, $timeparams) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $params = array_merge(['quizid' => $quizid, 'questionid' => $questionid], $timeparams);
            $userfilter = $this->build_user_filter($userids, $params, 'cq_');
            return (int)$this->db->get_field_sql(
                "SELECT COUNT(DISTINCT c.userid)
                 FROM {local_ai_prog_assist_conversation} c
                 WHERE c.quizid = :quizid AND c.questionid = :questionid{$timewhere}{$userfilter}",
                $params
            );
        });
    }

    public function get_question_followups(int $quizid, int $questionid, ?int $starttime = null, ?int $endtime = null, int $limit = 5000, ?array $userids = null, int $offset = 0, ?string $actiontype = null): array {
        $cachekey = $this->cache_key('get_question_followups', [
            'q' => $quizid, 'qi' => $questionid, 's' => $starttime, 'e' => $endtime, 'l' => $limit, 'u' => $userids, 'o' => $offset, 'at' => $actiontype,
        ]);
        return $this->cached($cachekey, function() use ($quizid, $questionid, $starttime, $endtime, $limit, $userids, $offset, $actiontype) {
            $limit = $this->safe_limit($limit);

            list($timewhere, $timeparams) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $params = array_merge(['quizid' => $quizid, 'questionid' => $questionid], $timeparams);

            $userfilter = $this->build_user_filter($userids, $params, 'qu_');

            $actiontypefilter = '';
            if (!empty($actiontype)) {
                $actiontypefilter = ' AND c.actiontype = :actiontype';
                $params['actiontype'] = $actiontype;
            }

            // 先按学生维度分页取 userid 列表，再取这些学生的全部组合行，
            // 避免 SQL 层按 (userid, actiontype) 组合行分页后与 count（按 userid 去重）
            // 粒度不一致导致分页总数与每页条目错位。
            $dimids = $this->db->get_records_sql(
                "SELECT c.userid AS dimid, MAX(c.starttime) AS last_timecreated
                 FROM {local_ai_prog_assist_conversation} c
                 WHERE c.quizid = :quizid AND c.questionid = :questionid{$timewhere}{$actiontypefilter}{$userfilter}
                 GROUP BY c.userid
                 ORDER BY last_timecreated DESC",
                $params, $offset, $limit
            );
            if (empty($dimids)) {
                return [];
            }
            $dimvalues = array_map('intval', array_keys($dimids));

            list($inSQL, $inParams) = $this->db->get_in_or_equal($dimvalues, SQL_PARAMS_NAMED, 'quid');
            $params = array_merge($params, $inParams);

            $sql = "SELECT c.userid, " . self::$USER_FIELDS . ",
                            c.actiontype as mode,
                            COUNT(DISTINCT c.id) as entry_count,
                            MAX(c.starttime) as last_timecreated
                     FROM {local_ai_prog_assist_conversation} c
                     JOIN {user} u ON u.id = c.userid
                     WHERE c.quizid = :quizid AND c.questionid = :questionid{$timewhere}{$actiontypefilter}{$userfilter} AND c.userid {$inSQL}
                     GROUP BY c.userid, c.actiontype, " . self::$USER_GROUP_BY . "
                     ORDER BY last_timecreated DESC";

            $recordset = $this->db->get_recordset_sql($sql, $params);

            $grouped = [];
            try {
                foreach ($recordset as $record) {
                    $uid = (int)$record->userid;
                    $fullname = fullname($record);
                    $mode = $record->mode ?: 'free';
                    $timecreated = (int)$record->last_timecreated;

                    if (!isset($grouped[$uid])) {
                        $grouped[$uid] = [
                            'userid' => $uid, 'username' => $record->username,
                            'fullname' => $fullname, 'entry_count' => 0,
                            'modes' => [],
                        ];
                    }

                    $grouped[$uid]['entry_count'] += (int)$record->entry_count;

                    $grouped[$uid]['modes'][] = [
                        'actiontype' => $mode,
                        'entry_count' => (int)$record->entry_count,
                        'last_timecreated' => $timecreated,
                        'last_timecreated_str' => userdate($timecreated, '%Y-%m-%d %H:%M'),
                        'mode_label' => \local_ai_programming_assistant\helper::get_mode_label($mode),
                        'quizid' => $quizid,
                        'questionid' => $questionid,
                        'userid' => $uid,
                    ];
                }
            } finally {
                $recordset->close();
            }

            $result = [];
            foreach ($grouped as $student) {
                $student['mode_count'] = count($student['modes']);
                usort($student['modes'], function($a, $b) {
                    return $b['last_timecreated'] - $a['last_timecreated'];
                });
                $student['max_mode_time'] = !empty($student['modes']) ? $student['modes'][0]['last_timecreated'] : 0;
                $result[] = $student;
            }

            // 使用预计算的 max_mode_time 排序，合并原两次独立排序为一次
            usort($result, function($a, $b) {
                return $b['max_mode_time'] - $a['max_mode_time'];
            });

            return $result;
        });
    }

    public function count_question_followups(int $quizid, int $questionid, ?int $starttime = null, ?int $endtime = null, ?array $userids = null, ?string $actiontype = null): int {
        $cachekey = $this->cache_key('count_question_followups', [
            'q' => $quizid, 'qi' => $questionid, 's' => $starttime, 'e' => $endtime, 'u' => $userids, 'at' => $actiontype,
        ]);
        return $this->cached($cachekey, function() use ($quizid, $questionid, $starttime, $endtime, $userids, $actiontype) {
            list($timewhere, $timeparams) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $params = array_merge(['quizid' => $quizid, 'questionid' => $questionid], $timeparams);

            $actiontypefilter = '';
            if (!empty($actiontype)) {
                $actiontypefilter = ' AND c.actiontype = :actiontype';
                $params['actiontype'] = $actiontype;
            }

            $userfilter = $this->build_user_filter($userids, $params, 'cqf_');
            return (int)$this->db->get_field_sql(
                "SELECT COUNT(DISTINCT c.userid)
                 FROM {local_ai_prog_assist_conversation} c
                 WHERE c.quizid = :quizid AND c.questionid = :questionid{$timewhere}{$actiontypefilter}{$userfilter}",
                $params
            );
        });
    }

    public function get_question_student_feedback_details(int $quizid, int $questionid, int $userid, ?int $starttime = null, ?int $endtime = null, int $limit = 5000, string $actiontype = ''): array {
        $cachekey = $this->cache_key('get_question_student_feedback_details', [
            'q' => $quizid, 'qi' => $questionid, 'u' => $userid, 's' => $starttime, 'e' => $endtime, 'l' => $limit, 'at' => $actiontype,
        ]);
        return $this->cached($cachekey, function() use ($quizid, $questionid, $userid, $starttime, $endtime, $limit, $actiontype) {
            $limit = $this->safe_limit($limit);

            list($timewhere, $timeparams) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $params = array_merge(['userid' => $userid, 'questionid' => $questionid, 'quizid' => $quizid], $timeparams);

            $modewhere = '';
            if ($actiontype !== '') {
                $modewhere = ' AND c.actiontype = :actiontype';
                $params['actiontype'] = $actiontype;
            }

            // 派生表内重复外部会话过滤条件（c2 别名 + _wm 参数后缀），
            // 将 LEAD 窗口函数限定在目标会话范围内，避免全表扫描所有用户消息。
            list($timewhere2, $params2) = $this->build_time_where($starttime, $endtime, 'c2', 'starttime', '_wm');
            $params2 = array_merge(['userid_wm' => $userid, 'questionid_wm' => $questionid, 'quizid_wm' => $quizid], $params2);
            $modewhere2 = '';
            if ($actiontype !== '') {
                $modewhere2 = ' AND c2.actiontype = :actiontype_wm';
                $params2['actiontype_wm'] = $actiontype;
            }
            $params = array_merge($params, $params2);

            $rs = $this->db->get_recordset_sql(
                "SELECT c.id as conversation_id, c.userid, c.questionid, c.actiontype as mode,
                        c.starttime as conversation_starttime, c.endtime as conversation_endtime,
                        m.id as message_id, m.content as airesponse, m.metadata, m.timestamp as timecreated,
                        {$this->meta_extract('model')} as model,
                        {$this->meta_extract('success')} as success_val,
                        {$this->meta_extract('responsetime')} as responsetime_val,
                        ms.content as student_message,
                        " . self::$USER_FIELDS . ",
                        q.name as questionname
                 FROM {local_ai_prog_assist_conversation} c
                 LEFT JOIN {local_ai_prog_assist_message} m ON m.conversationid = c.id AND m.role = 'assistant'
                 LEFT JOIN (
                     -- 预计算每条用户消息在会话内的下一条用户消息时间戳，
                     -- 用范围比较（um_ts <= 回复时间 AND next_um_ts > 回复时间）
                     -- 代替原实现的逐行 MAX() 关联子查询，消除 N+1 查询。
                     SELECT um.conversationid, um.content, um.timestamp AS um_ts,
                            LEAD(um.timestamp) OVER (PARTITION BY um.conversationid ORDER BY um.timestamp ASC) AS next_um_ts
                     FROM {local_ai_prog_assist_message} um
                     JOIN {local_ai_prog_assist_conversation} c2 ON c2.id = um.conversationid
                     WHERE um.role = 'user' AND c2.quizid = :quizid_wm AND c2.userid = :userid_wm AND c2.questionid = :questionid_wm{$timewhere2}{$modewhere2}
                 ) ms ON ms.conversationid = c.id
                     AND ms.um_ts <= COALESCE(m.timestamp, c.endtime)
                     AND (ms.next_um_ts IS NULL OR ms.next_um_ts > COALESCE(m.timestamp, c.endtime))
                 JOIN {user} u ON u.id = c.userid
                 LEFT JOIN {question} q ON q.id = c.questionid
                 WHERE c.quizid = :quizid AND c.userid = :userid AND c.questionid = :questionid{$timewhere}{$modewhere}
                 ORDER BY COALESCE(m.timestamp, c.starttime) ASC, COALESCE(m.id, 0) ASC",
                $params, 0, $limit
            );

            $conversations_ids = [];
            $result = [];
            $cachedfullname = null;
            $cachedquestionname = null;
            try {
                foreach ($rs as $record) {
                    if ($cachedfullname === null) {
                        $cachedfullname = fullname($record);
                        $cachedquestionname = is_string($record->questionname) ? $record->questionname : ('Question #' . $record->questionid);
                    }
                    $conversations_ids[] = (int)$record->conversation_id;
                    // 学生源代码按纯文本展示：不得经过 render_ai_response 的 Markdown 转换，
                    // 否则 # 注释、* 列表等代码字符会被渲染成 HTML 标签，再经模板转义后以
                    // 字面量形式显示，破坏源码可读性（模板中已用 {{sourcecode}} 转义输出）。
                    $studentanswer = $record->student_message ?? '';
                    $timestamp = $record->timecreated ?? $record->conversation_starttime;
                    $result[] = [
                        'id' => $record->message_id, 'userid' => $record->userid,
                        'username' => $record->username, 'fullname' => $cachedfullname,
                        'questionid' => $record->questionid,
                        'questionname' => $cachedquestionname,
                        'sourcecode' => $studentanswer,
                        'studentmessage' => $record->student_message ?? '',
                        'model' => $record->model ?? '', 'mode' => $record->mode ?? '',
                        'mode_label' => \local_ai_programming_assistant\helper::get_mode_label($record->mode ?? ''),
                        'feedback' => \local_ai_programming_assistant\helper::render_ai_response($record->airesponse ?? ''),
                        'timecreated' => $timestamp,
                        'timecreated_str' => userdate($timestamp, '%Y-%m-%d %H:%M'),
                        'success' => in_array(($record->success_val ?? ''), ['true', '1'], true),
                        'responsetime' => (int)($record->responsetime_val ?? 0),
                        'conversation_id' => (int)$record->conversation_id,
                        'has_conversations' => false,
                        'conversations' => [],
                    ];
                }
            } finally {
                $rs->close();
            }

            if (!empty($conversations_ids)) {
                $this->fill_conversation_history($result, $conversations_ids);
            }

            return $result;
        });
    }

    private function fill_conversation_history(array &$feedback_list, array $conversation_ids): void {
        // 显式空数组检查：防止 get_in_or_equal 在不同 DB 驱动下行为不一致导致 coding_exception
        $conversation_ids = array_values(array_filter(array_map('intval', $conversation_ids), function($v) { return $v > 0; }));
        if (empty($conversation_ids)) {
            return;
        }
        list($idlist, $idparams) = $this->db->get_in_or_equal($conversation_ids, SQL_PARAMS_NAMED);
        $rs = $this->db->get_recordset_sql(
            "SELECT conversationid, id, role, content, timestamp
             FROM {local_ai_prog_assist_message}
             WHERE conversationid {$idlist}
             ORDER BY conversationid ASC, timestamp ASC, id ASC",
            $idparams
        );

        $conversation_messages = [];
        $this->collect_recordset($rs, function ($record) use (&$conversation_messages) {
            $cid = (int)$record->conversationid;
            if (!isset($conversation_messages[$cid])) {
                $conversation_messages[$cid] = [];
            }
            $role = $record->role ?? 'user';
            $content = $record->content ?? '';
            if ($role === 'assistant') {
                $content = \local_ai_programming_assistant\helper::render_ai_response($content);
            }
            $conversation_messages[$cid][] = [
                'id' => (int)$record->id,
                'role' => $role,
                'content' => $content,
                'timestamp' => $record->timestamp,
                'timecreated_str' => userdate($record->timestamp, '%Y-%m-%d %H:%M'),
                'is_user' => ($role === 'user'),
                'is_assistant' => ($role === 'assistant'),
            ];
        });

        foreach ($feedback_list as &$item) {
            $cid = $item['conversation_id'] ?? 0;
            if (isset($conversation_messages[$cid]) && count($conversation_messages[$cid]) > 1) {
                $item['has_conversations'] = true;
                $item['conversations'] = $conversation_messages[$cid];
            }
        }
    }

    // ===== 自由模式（无题号）统计（委托给 free_log_stats_repository） =====

    public function get_free_log_statistics(?int $starttime = null, ?int $endtime = null): array {
        return $this->get_free_log_repo()->get_free_log_statistics($starttime, $endtime);
    }

    public function get_free_log_student_stats(?int $starttime = null, ?int $endtime = null,
        int $limit = 20, string $search = '', int $offset = 0, string $sort = 'total_requests',
        string $order = 'DESC'): array {
        return $this->get_free_log_repo()->get_free_log_student_stats($starttime, $endtime, $limit, $search, $offset, $sort, $order);
    }

    public function count_free_log_student_stats(?int $starttime = null, ?int $endtime = null, string $search = ''): int {
        return $this->get_free_log_repo()->count_free_log_student_stats($starttime, $endtime, $search);
    }

    public function get_free_log_chat_detail(int $userid, ?int $starttime = null, ?int $endtime = null, int $limit = 100, int $offset = 0, ?int $contextid = null): array {
        return $this->get_free_log_repo()->get_free_log_chat_detail($userid, $starttime, $endtime, $limit, $offset, $contextid);
    }

    public function count_free_log_chat(int $userid, ?int $starttime = null, ?int $endtime = null, ?int $contextid = null): int {
        return $this->get_free_log_repo()->count_free_log_chat($userid, $starttime, $endtime, $contextid);
    }

    public function get_recent_free_chats_grouped(?int $starttime = null, ?int $endtime = null,
        int $limit = 100, int $offset = 0, string $search = ''): array {
        return $this->get_free_log_repo()->get_recent_free_chats_grouped($starttime, $endtime, $limit, $offset, $search);
    }

    public function count_recent_free_chats_grouped(?int $starttime = null, ?int $endtime = null,
        string $search = ''): int {
        return $this->get_free_log_repo()->count_recent_free_chats_grouped($starttime, $endtime, $search);
    }

    /**
     * 获取所有学生按试题+用户+策略模式分组的最近 AI 使用对话记录
     *
     * 按 (questionid, userid, actiontype) 分组，用于展示最近 AI 使用对话记录的一级页面。
     * 支持学生姓名搜索、时间范围和课程/测验过滤，按最近交互时间倒序排列。
     *
     * @param int|null $starttime 起始时间戳
     * @param int|null $endtime 结束时间戳
     * @param int $limit 每页记录数
     * @param int $offset 偏移量
     * @param string $search 学生姓名/用户名搜索关键字
     * @param int|null $courseid 课程ID过滤
     * @param int|null $quizid 测验ID过滤
     * @return array 分组记录数组
     */
    public function get_recent_quiz_chats_grouped(?int $starttime = null, ?int $endtime = null,
        int $limit = 100, int $offset = 0, string $search = '',
        ?int $courseid = null, ?int $quizid = null): array {
        $cachekey = $this->cache_key('get_recent_quiz_chats_grouped', [
            's' => $starttime, 'e' => $endtime, 'l' => $limit, 'o' => $offset,
            'q' => $search, 'c' => $courseid, 'z' => $quizid,
        ]);
        return $this->cached($cachekey, function() use ($starttime, $endtime, $limit, $offset, $search, $courseid, $quizid) {
            $limit = $this->safe_limit($limit);
            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $searchwhere = $this->build_user_search_where($search, $params, 'rqg');

            $quizwhere = '';
            if ($quizid !== null && $quizid > 0) {
                $quizwhere = ' AND c.quizid = :quizid';
                $params['quizid'] = $quizid;
            }

            $coursewhere = '';
            if ($courseid !== null && $courseid > 0) {
                $coursewhere = ' AND qz.course = :courseid';
                $params['courseid'] = $courseid;
            }

            $rs = $this->db->get_recordset_sql(
                "SELECT c.questionid, c.quizid, c.userid, c.actiontype,
                        q.name as questionname,
                        qz.name as quizname,
                        qz.course as courseid,
                        co.fullname as coursename,
                        " . self::$USER_FIELDS . ",
                        COUNT(DISTINCT c.id) as interaction_count,
                        MAX(c.starttime) as last_interaction
                 FROM {local_ai_prog_assist_conversation} c
                 JOIN {user} u ON u.id = c.userid
                 LEFT JOIN {question} q ON q.id = c.questionid
                 LEFT JOIN {quiz} qz ON qz.id = c.quizid
                 LEFT JOIN {course} co ON co.id = qz.course
                 WHERE c.questionid > 0 AND c.quizid > 0{$timewhere}{$searchwhere}{$quizwhere}{$coursewhere}
                 GROUP BY c.questionid, c.quizid, c.userid, c.actiontype,
                          q.name, qz.name, qz.course, co.fullname, " . self::$USER_GROUP_BY . "
                 ORDER BY last_interaction DESC",
                $params, $offset, $limit
            );

            $result = $this->collect_recordset($rs, function ($record) {
                $actiontype = $record->actiontype ?? 'free';
                return [
                    'questionid' => (int)$record->questionid,
                    'quizid' => (int)$record->quizid,
                    'userid' => (int)$record->userid,
                    'actiontype' => $actiontype,
                    'actiontype_label' => \local_ai_programming_assistant\helper::get_mode_label($actiontype),
                    'questionname' => is_string($record->questionname) ? $record->questionname : ('Question #' . $record->questionid),
                    'quizname' => $record->quizname ?? '',
                    'courseid' => (int)$record->courseid,
                    'coursename' => $record->coursename ?? '',
                    'username' => $record->username,
                    'fullname' => fullname($record),
                    'interaction_count' => (int)$record->interaction_count,
                    'last_interaction' => (int)$record->last_interaction,
                    'last_interaction_str' => userdate((int)$record->last_interaction, '%Y-%m-%d %H:%M'),
                ];
            });

            return $result;
        });
    }

    /**
     * 统计所有学生按试题+用户+策略模式分组的最近 AI 使用对话记录组数
     *
     * @param int|null $starttime 起始时间戳
     * @param int|null $endtime 结束时间戳
     * @param string $search 学生姓名/用户名搜索关键字
     * @param int|null $courseid 课程ID过滤
     * @param int|null $quizid 测验ID过滤
     * @return int 分组总数
     */
    public function count_recent_quiz_chats_grouped(?int $starttime = null, ?int $endtime = null,
        string $search = '', ?int $courseid = null, ?int $quizid = null): int {
        $cachekey = $this->cache_key('count_recent_quiz_chats_grouped', [
            's' => $starttime, 'e' => $endtime, 'q' => $search, 'c' => $courseid, 'z' => $quizid,
        ]);
        return $this->cached($cachekey, function() use ($starttime, $endtime, $search, $courseid, $quizid) {
            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');

            // 无搜索条件时跳过 JOIN {user}，减少不必要的表关联开销
            $searchwhere = '';
            $userjoin = '';
            if ($search !== '') {
                $userjoin = 'JOIN {user} u ON u.id = c.userid';
                $searchwhere = $this->build_user_search_where($search, $params, 'rqg');
            }

            $quizwhere = '';
            if ($quizid !== null && $quizid > 0) {
                $quizwhere = ' AND c.quizid = :quizid';
                $params['quizid'] = $quizid;
            }

            $coursejoin = '';
            $coursewhere = '';
            if ($courseid !== null && $courseid > 0) {
                $coursejoin = ' JOIN {quiz} qz ON qz.id = c.quizid';
                $coursewhere = ' AND qz.course = :courseid';
                $params['courseid'] = $courseid;
            }

            // 使用子查询 GROUP BY 替代 COUNT(DISTINCT CONCAT(...))，
            // 让数据库利用复合索引，避免全表扫描和内存字符串拼接
            return (int)$this->db->get_field_sql(
                "SELECT COUNT(*) FROM (
                    SELECT 1
                    FROM {local_ai_prog_assist_conversation} c
                    {$userjoin}
                    {$coursejoin}
                    WHERE c.questionid > 0 AND c.quizid > 0{$timewhere}{$searchwhere}{$quizwhere}{$coursewhere}
                    GROUP BY c.questionid, c.quizid, c.userid, c.actiontype
                ) sq",
                $params
            );
        });
    }

    // ===== 学生题目列表（中间页） =====

    public function get_student_questions(int $userid, ?int $starttime = null, ?int $endtime = null,
        ?int $quizid = null, int $limit = 10, int $offset = 0): array {
        $cachekey = $this->cache_key('get_student_questions', [
            'u' => $userid, 's' => $starttime, 'e' => $endtime, 'q' => $quizid, 'l' => $limit, 'o' => $offset,
        ]);
        return $this->cached($cachekey, function() use ($userid, $starttime, $endtime, $quizid, $limit, $offset) {
            $success_expr = $this->conv_meta_sum_success();

            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $params['userid'] = $userid;

            $quizwhere = '';
            if ($quizid !== null && $quizid > 0) {
                $quizwhere = ' AND c.quizid = :quizid';
                $params['quizid'] = $quizid;
            }

            $rs = $this->db->get_recordset_sql(
                "SELECT c.questionid, c.quizid,
                        q.name as questionname,
                        COUNT(DISTINCT c.id) as interaction_count,
                        MAX(c.starttime) as last_interaction,
                        {$success_expr} as successful_count
                 FROM {local_ai_prog_assist_conversation} c
                 LEFT JOIN {question} q ON q.id = c.questionid
                 WHERE c.userid = :userid AND c.questionid > 0{$timewhere}{$quizwhere}
                 GROUP BY c.questionid, c.quizid, q.name
                 ORDER BY last_interaction DESC",
                $params, $offset, $limit
            );

            $result = $this->collect_recordset($rs, function ($record) {
                return [
                    'questionid' => (int)$record->questionid,
                    'quizid' => (int)$record->quizid,
                    'questionname' => is_string($record->questionname) ? $record->questionname : ('Question #' . $record->questionid),
                    'interaction_count' => (int)$record->interaction_count,
                    'successful_count' => (int)$record->successful_count,
                    'last_interaction' => $record->last_interaction,
                    'last_interaction_str' => userdate($record->last_interaction, '%Y-%m-%d %H:%M'),
                ];
            });

            return $result;
        });
    }

    /**
     * 统计某学生使用过的题目总数
     */
    public function count_student_questions(int $userid, ?int $starttime = null, ?int $endtime = null, ?int $quizid = null): int {
        $cachekey = $this->cache_key('count_student_questions', [
            'u' => $userid, 's' => $starttime, 'e' => $endtime, 'q' => $quizid,
        ]);
        return $this->cached($cachekey, function() use ($userid, $starttime, $endtime, $quizid) {
            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $params['userid'] = $userid;

            $quizwhere = '';
            if ($quizid !== null && $quizid > 0) {
                $quizwhere = ' AND c.quizid = :quizid';
                $params['quizid'] = $quizid;
            }

            // 使用子查询 GROUP BY 替代 COUNT(DISTINCT col1, col2) 多列语法
            // 后者仅 MySQL/MariaDB 支持，PostgreSQL/Oracle/SQL Server 不兼容
            return (int)$this->db->get_field_sql(
                "SELECT COUNT(*) FROM (
                    SELECT 1 FROM {local_ai_prog_assist_conversation} c
                    WHERE c.userid = :userid{$timewhere}{$quizwhere} AND c.questionid > 0
                    GROUP BY c.questionid, c.quizid
                ) sq",
                $params
            );
        });
    }

    // ===== 自由模式学生页面列表（中间页） =====

    public function get_student_free_pages(int $userid, ?int $starttime = null, ?int $endtime = null,
        int $limit = 10, int $offset = 0): array {
        return $this->get_free_log_repo()->get_student_free_pages($userid, $starttime, $endtime, $limit, $offset);
    }

    public function count_student_free_pages(int $userid, ?int $starttime = null, ?int $endtime = null): int {
        return $this->get_free_log_repo()->count_student_free_pages($userid, $starttime, $endtime);
    }

    public function get_student_free_pages_total_interactions(int $userid, ?int $starttime = null, ?int $endtime = null): int {
        return $this->get_free_log_repo()->get_student_free_pages_total_interactions($userid, $starttime, $endtime);
    }

    // ===== 有题号主日志聊天详情 =====

    public function get_chat_detail(int $userid, ?int $starttime = null, ?int $endtime = null, ?int $quizid = null, int $limit = 100, int $offset = 0): array {
        $cachekey = $this->cache_key('get_chat_detail', ['u' => $userid, 's' => $starttime, 'e' => $endtime, 'q' => $quizid, 'l' => $limit, 'o' => $offset]);
        return $this->cached($cachekey, function() use ($userid, $starttime, $endtime, $quizid, $limit, $offset) {
            $limit = $this->safe_limit($limit);

            $model_expr = $this->meta_extract('model');
            $success_expr_raw = $this->meta_extract('success');
            $rt_expr_raw = $this->meta_extract('responsetime');
            $backend_expr = $this->meta_extract('ai_backend');
            $error_expr = $this->meta_extract('errormessage');

            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $params = array_merge(['userid' => $userid], $params);

            $quizwhere = '';
            if ($quizid !== null && $quizid > 0) {
                $quizwhere = ' AND c.quizid = :quizid';
                $params['quizid'] = $quizid;
            }

            // 派生表内重复外部会话过滤条件（c2 别名 + _wm 参数后缀），
            // 将 LEAD 窗口函数限定在目标会话范围内，避免全表扫描所有用户消息。
            list($timewhere2, $params2) = $this->build_time_where($starttime, $endtime, 'c2', 'starttime', '_wm');
            $params2 = array_merge(['userid_wm' => $userid], $params2);
            $quizwhere2 = '';
            if ($quizid !== null && $quizid > 0) {
                $quizwhere2 = ' AND c2.quizid = :quizid_wm';
                $params2['quizid_wm'] = $quizid;
            }
            $params = array_merge($params, $params2);

            $rs = $this->db->get_recordset_sql(
                "SELECT c.id as conversation_id, c.userid, c.questionid, c.quizid,
                        c.actiontype,
                        m.id as message_id, m.content as airesponse, m.metadata, m.timestamp as timecreated,
                        {$model_expr} as model,
                        {$backend_expr} as ai_backend,
                        {$success_expr_raw} as success_val,
                        {$rt_expr_raw} as responsetime_val,
                        {$error_expr} as errormessage,
                        ms.content as student_message,
                        " . self::$USER_FIELDS . ",
                        q.name as questionname
                 FROM {local_ai_prog_assist_conversation} c
                 JOIN {local_ai_prog_assist_message} m ON m.conversationid = c.id AND m.role = 'assistant'
                 LEFT JOIN (
                     SELECT um.conversationid, um.content, um.timestamp AS um_ts,
                            LEAD(um.timestamp) OVER (PARTITION BY um.conversationid ORDER BY um.timestamp ASC) AS next_um_ts
                     FROM {local_ai_prog_assist_message} um
                     JOIN {local_ai_prog_assist_conversation} c2 ON c2.id = um.conversationid
                     WHERE um.role = 'user' AND c2.userid = :userid_wm{$timewhere2}{$quizwhere2}
                 ) ms ON ms.conversationid = c.id
                     AND ms.um_ts <= COALESCE(m.timestamp, c.endtime)
                     AND (ms.next_um_ts IS NULL OR ms.next_um_ts > COALESCE(m.timestamp, c.endtime))
                 JOIN {user} u ON u.id = c.userid
                 LEFT JOIN {question} q ON q.id = c.questionid
                 WHERE c.userid = :userid{$timewhere}{$quizwhere}
                 ORDER BY m.timestamp DESC",
                $params, $offset, $limit
            );

            $result = $this->collect_recordset($rs, function ($record) {
                $mode = $record->actiontype ?? '';
                $studentanswer = $record->student_message ?? '';
                return [
                    'id' => $record->message_id,
                    'userid' => $record->userid,
                    'username' => $record->username,
                    'fullname' => fullname($record),
                    'usermessage' => $studentanswer,
                    'airesponse' => \local_ai_programming_assistant\helper::render_ai_response($record->airesponse ?? ''),
                    'studentanswer' => $studentanswer,
                    'model' => $record->model ?? '',
                    'ai_backend' => $record->ai_backend ?? '',
                    'success' => in_array(($record->success_val ?? ''), ['true', '1'], true),
                    'responsetime' => (int)($record->responsetime_val ?? 0),
                    'errormessage' => $record->errormessage ?? '',
                    'timecreated' => $record->timecreated,
                    'timecreated_str' => userdate($record->timecreated, '%Y-%m-%d %H:%M'),
                    'questionid' => (int)$record->questionid,
                    'questionname' => is_string($record->questionname) ? $record->questionname : ('Question #' . $record->questionid),
                    'quizid' => (int)$record->quizid,
                    'attemptid' => 0,
                    'mode' => $mode,
                    'modelabel' => \local_ai_programming_assistant\helper::get_mode_label($mode),
                ];
            });

            return $result;
        });
    }

    public function count_chat(int $userid, ?int $starttime = null, ?int $endtime = null, ?int $quizid = null): int {
        $cachekey = $this->cache_key('count_chat', ['u' => $userid, 's' => $starttime, 'e' => $endtime, 'q' => $quizid]);
        return $this->cached($cachekey, function() use ($userid, $starttime, $endtime, $quizid) {
            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $params = array_merge(['userid' => $userid], $params);

            $quizwhere = '';
            if ($quizid !== null && $quizid > 0) {
                $quizwhere = ' AND c.quizid = :quizid';
                $params['quizid'] = $quizid;
            }

            return (int)$this->db->get_field_sql(
                "SELECT COUNT(*)
                 FROM {local_ai_prog_assist_conversation} c
                 JOIN {local_ai_prog_assist_message} m ON m.conversationid = c.id AND m.role = 'assistant'
                 WHERE c.userid = :userid{$timewhere}{$quizwhere}",
                $params
            );
        });
    }

    public function get_free_log_daily_stats(int $starttime, int $endtime): array {
        return $this->get_free_log_repo()->get_free_log_daily_stats($starttime, $endtime);
    }

    // ===== 学生提问记录（学生向 AI 提问的原始问题列表） =====

    /**
     * 构建学生提问记录的过滤 SQL 片段和参数
     *
     * @param int|null $questionid 题目 ID（>0 时仅统计该题）
     * @param int|null $userid 学生 ID（>0 时仅统计该学生）
     * @param string|null $actiontype 策略模式（question_analysis/idea_organization/code_debugging/free）
     * @param int|null $quizid 测验 ID（>0 时仅统计该测验）
     * @param int|null $courseid 课程 ID（>0 时仅统计该课程）
     * @param array $params 参数引用（会被合并）
     * @param string $prefix SQL 参数命名前缀
     * @return string 过滤条件 SQL 片段
     */
    private function build_asked_questions_filters(?int $questionid, ?int $userid, ?string $actiontype,
        ?int $quizid, ?int $courseid, array &$params, string $prefix = 'aq_'): string {
        $filters = '';
        if ($questionid !== null && $questionid > 0) {
            $filters .= ' AND c.questionid = :' . $prefix . 'questionid';
            $params[$prefix . 'questionid'] = $questionid;
        }
        if ($userid !== null && $userid > 0) {
            $filters .= ' AND c.userid = :' . $prefix . 'userid';
            $params[$prefix . 'userid'] = $userid;
        }
        if ($actiontype !== null && $actiontype !== '') {
            $filters .= ' AND c.actiontype = :' . $prefix . 'actiontype';
            $params[$prefix . 'actiontype'] = $actiontype;
        }
        if ($quizid !== null && $quizid > 0) {
            $filters .= ' AND c.quizid = :' . $prefix . 'quizid';
            $params[$prefix . 'quizid'] = $quizid;
        }
        if ($courseid !== null && $courseid > 0) {
            $filters .= ' AND c.courseid = :' . $prefix . 'courseid';
            $params[$prefix . 'courseid'] = $courseid;
        }
        return $filters;
    }

    /**
     * 获取学生提问记录列表
     *
     * 数据源：local_ai_prog_assist_message（role='user'，即学生提交的问题原文）
     * 关联会话表获取题目、学生、策略模式、测验、课程等信息。
     * 支持按题目、学生、策略模式、测验、课程、时间范围及学生姓名搜索过滤，
     * 覆盖四类视图：
     *  1) 某道题学生提问的问题（questionid）
     *  2) 某位同学问了哪些问题（userid）
     *  3) 某位同学对某道题提了哪些问题（userid + questionid）
     *  4) 某位同学对某道题某种策略模式下提问的问题（userid + questionid + actiontype）
     *
     * @param int|null $questionid 题目 ID（>0 时仅统计该题）
     * @param int|null $userid 学生 ID（>0 时仅统计该学生）
     * @param string|null $actiontype 策略模式（question_analysis/idea_organization/code_debugging/free）
     * @param int|null $quizid 测验 ID（>0 时仅统计该测验）
     * @param int|null $courseid 课程 ID（>0 时仅统计该课程）
     * @param int|null $starttime 起始时间戳（按消息时间过滤）
     * @param int|null $endtime 结束时间戳
     * @param string $search 学生姓名/用户名搜索关键字
     * @param int $limit 每页条数
     * @param int $offset 偏移量
     * @return array 提问记录数组
     */
    public function get_asked_questions(?int $questionid = null, ?int $userid = null, ?string $actiontype = null,
        ?int $quizid = null, ?int $courseid = null, ?int $starttime = null, ?int $endtime = null,
        string $search = '', int $limit = 20, int $offset = 0): array {
        $cachekey = $this->cache_key('get_asked_questions', [
            'qi' => $questionid, 'u' => $userid, 'at' => $actiontype, 'z' => $quizid, 'ci' => $courseid,
            's' => $starttime, 'e' => $endtime, 'se' => $search, 'l' => $limit, 'o' => $offset,
        ]);
        return $this->cached($cachekey, function() use ($questionid, $userid, $actiontype, $quizid, $courseid,
            $starttime, $endtime, $search, $limit, $offset) {
            $limit = $this->safe_limit($limit);

            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'm', 'timestamp');
            $filters = $this->build_asked_questions_filters($questionid, $userid, $actiontype, $quizid, $courseid, $params);
            $filters .= $this->build_user_search_where($search, $params, 'aqse');

            $rs = $this->db->get_recordset_sql(
                "SELECT m.id as message_id, m.content as question, m.timestamp as timecreated,
                        c.id as conversation_id, c.userid, c.questionid, c.quizid, c.actiontype as mode,
                        c.courseid, c.contextid, c.pagetype,
                        " . self::$USER_FIELDS . ",
                        q.name as questionname, qz.name as quizname, co.fullname as coursename
                 FROM {local_ai_prog_assist_message} m
                 JOIN {local_ai_prog_assist_conversation} c ON c.id = m.conversationid
                 JOIN {user} u ON u.id = c.userid
                 LEFT JOIN {question} q ON q.id = c.questionid
                 LEFT JOIN {quiz} qz ON qz.id = c.quizid
                 LEFT JOIN {course} co ON co.id = c.courseid
                 WHERE m.role = 'user'{$timewhere}{$filters}
                 ORDER BY m.timestamp DESC, m.id DESC",
                $params, $offset, $limit
            );

            $result = $this->collect_recordset($rs, function ($record) {
                $qid = (int)$record->questionid;
                $mode = $record->mode ?: 'free';
                $fulltext = (string)($record->question ?? '');
                // 自由模式（questionid=0）无题目名，回退显示页面上下文名称
                if ($qid > 0) {
                    $questionname = is_string($record->questionname) ? $record->questionname : ('Question #' . $qid);
                } else {
                    $questionname = \local_ai_programming_assistant\helper::resolve_context_name(
                        (int)$record->contextid, $record->pagetype ?? ''
                    );
                }
                return [
                    'message_id' => (int)$record->message_id,
                    'conversation_id' => (int)$record->conversation_id,
                    'userid' => (int)$record->userid,
                    'username' => $record->username,
                    'fullname' => fullname($record),
                    'questionid' => $qid,
                    'questionname' => $questionname,
                    'isfree' => $qid <= 0,
                    'quizid' => (int)$record->quizid,
                    'quizname' => $record->quizname ?? '',
                    'courseid' => (int)$record->courseid,
                    'coursename' => $record->coursename ?? '',
                    'mode' => $mode,
                    'mode_label' => \local_ai_programming_assistant\helper::get_mode_label($mode),
                    'question' => $fulltext,
                    'question_short' => mb_strlen($fulltext, 'UTF-8') > 300
                        ? mb_substr($fulltext, 0, 300, 'UTF-8') . '…' : $fulltext,
                    'timecreated' => (int)$record->timecreated,
                    'timecreated_str' => userdate($record->timecreated, '%Y-%m-%d %H:%M'),
                ];
            });

            return $result;
        });
    }

    /**
     * 统计学生提问记录总数
     *
     * 过滤条件与 get_asked_questions() 保持一致（按消息时间过滤）。
     *
     * @param int|null $questionid 题目 ID（>0 时仅统计该题）
     * @param int|null $userid 学生 ID（>0 时仅统计该学生）
     * @param string|null $actiontype 策略模式
     * @param int|null $quizid 测验 ID（>0 时仅统计该测验）
     * @param int|null $courseid 课程 ID（>0 时仅统计该课程）
     * @param int|null $starttime 起始时间戳（按消息时间过滤）
     * @param int|null $endtime 结束时间戳
     * @param string $search 学生姓名/用户名搜索关键字
     * @return int 提问记录总数
     */
    public function count_asked_questions(?int $questionid = null, ?int $userid = null, ?string $actiontype = null,
        ?int $quizid = null, ?int $courseid = null, ?int $starttime = null, ?int $endtime = null,
        string $search = ''): int {
        $cachekey = $this->cache_key('count_asked_questions', [
            'qi' => $questionid, 'u' => $userid, 'at' => $actiontype, 'z' => $quizid, 'ci' => $courseid,
            's' => $starttime, 'e' => $endtime, 'se' => $search,
        ]);
        return $this->cached($cachekey, function() use ($questionid, $userid, $actiontype, $quizid, $courseid,
            $starttime, $endtime, $search) {
            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'm', 'timestamp');
            $filters = $this->build_asked_questions_filters($questionid, $userid, $actiontype, $quizid, $courseid, $params);

            // 无搜索条件时跳过 JOIN {user}，减少不必要的表关联开销
            $searchwhere = '';
            $userjoin = '';
            if ($search !== '') {
                $userjoin = 'JOIN {user} u ON u.id = c.userid';
                $searchwhere = $this->build_user_search_where($search, $params, 'aqse');
            }

            return (int)$this->db->get_field_sql(
                "SELECT COUNT(*)
                 FROM {local_ai_prog_assist_message} m
                 JOIN {local_ai_prog_assist_conversation} c ON c.id = m.conversationid
                 {$userjoin}
                 WHERE m.role = 'user'{$timewhere}{$filters}{$searchwhere}",
                $params
            );
        });
    }

    // ===== 会话列表（一级页面） =====

    /**
     * 获取分页的会话列表，按时间从近到远排列
     *
     * @param int|null $starttime 起始时间戳
     * @param int|null $endtime 结束时间戳
     * @param int $limit 每页记录数
     * @param int $offset 偏移量
     * @return array 会话列表（含学生姓名、消息数、模式、上下文等）
     */
    public function get_conversations_list(?int $starttime = null, ?int $endtime = null,
        int $limit = 20, int $offset = 0): array {
        $cachekey = $this->cache_key('get_conversations_list', [
            's' => $starttime, 'e' => $endtime, 'l' => $limit, 'o' => $offset,
        ]);
        return $this->cached($cachekey, function() use ($starttime, $endtime, $limit, $offset) {
            $limit = $this->safe_limit($limit);
            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');

            $rs = $this->db->get_recordset_sql(
                "SELECT c.id, c.userid, c.questionid, c.quizid, c.actiontype,
                        c.contextid, c.courseid, c.cmid, c.pagetype,
                        c.starttime, c.endtime, c.messagecount,
                        co.fullname as coursename,
                        " . self::$USER_FIELDS . "
                 FROM {local_ai_prog_assist_conversation} c
                 JOIN {user} u ON u.id = c.userid
                 LEFT JOIN {course} co ON co.id = c.courseid
                 WHERE 1=1{$timewhere}
                 ORDER BY c.starttime DESC",
                $params, $offset, $limit
            );

            $result = $this->collect_recordset($rs, function ($record) {
                $actiontype = $record->actiontype ?? 'free';
                return [
                    'id' => (int)$record->id,
                    'userid' => (int)$record->userid,
                    'studentname' => fullname($record),
                    'username' => $record->username,
                    'questionid' => (int)$record->questionid,
                    'quizid' => (int)$record->quizid,
                    'actiontype' => $actiontype,
                    'actiontype_label' => \local_ai_programming_assistant\helper::get_mode_label($actiontype),
                    'contextid' => (int)$record->contextid,
                    'courseid' => (int)$record->courseid,
                    'cmid' => (int)$record->cmid,
                    'pagetype' => $record->pagetype ?? '',
                    'pagetype_label' => \local_ai_programming_assistant\helper::format_pagetype($record->pagetype ?? ''),
                    'coursename' => $record->coursename ?? '',
                    'starttime' => (int)$record->starttime,
                    'starttime_str' => userdate((int)$record->starttime, '%Y-%m-%d %H:%M'),
                    'endtime' => (int)$record->endtime,
                    'endtime_str' => userdate((int)$record->endtime, '%Y-%m-%d %H:%M'),
                    'messagecount' => (int)$record->messagecount,
                ];
            });

            // 解析上下文名称和链接
            foreach ($result as &$row) {
                $ctxinfo = \local_ai_programming_assistant\helper::resolve_context_info($row['contextid'], $row['pagetype']);
                $row['context_name'] = $ctxinfo['name'];
                $row['context_url'] = $ctxinfo['url'] ? $ctxinfo['url']->out() : '';
            }
            unset($row);

            return $result;
        });
    }

    /**
     * 统计会话总数
     *
     * @param int|null $starttime 起始时间戳
     * @param int|null $endtime 结束时间戳
     * @return int 会话总数
     */
    public function count_conversations(?int $starttime = null, ?int $endtime = null): int {
        $cachekey = $this->cache_key('count_conversations', ['s' => $starttime, 'e' => $endtime]);
        return $this->cached($cachekey, function() use ($starttime, $endtime) {
            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            return (int)$this->db->get_field_sql(
                "SELECT COUNT(*) FROM {local_ai_prog_assist_conversation} c WHERE 1=1{$timewhere}",
                $params
            );
        });
    }

    /**
     * 获取测验下的会话列表（支持按试题、学生、模式筛选）
     *
     * @param int $quizid 测验 ID
     * @param int|null $starttime 起始时间戳
     * @param int|null $endtime 结束时间戳
     * @param int|null $questionid 试题 ID（可选）
     * @param int|null $userid 学生 ID（可选）
     * @param string|null $actiontype 分析模式（可选）
     * @param array|null $userids 分组用户 ID 列表（可选）
     * @param int $limit 每页记录数
     * @param int $offset 偏移量
     * @return array 会话列表
     */
    public function get_quiz_conversations(int $quizid, ?int $starttime = null, ?int $endtime = null,
        ?int $questionid = null, ?int $userid = null, ?string $actiontype = null,
        ?array $userids = null, int $limit = 20, int $offset = 0): array {
        $cachekey = $this->cache_key('get_quiz_conversations', [
            'q' => $quizid, 's' => $starttime, 'e' => $endtime,
            'qi' => $questionid, 'u' => $userid, 'a' => $actiontype,
            'uids' => $userids, 'l' => $limit, 'o' => $offset,
        ]);
        return $this->cached($cachekey, function() use ($quizid, $starttime, $endtime, $questionid, $userid, $actiontype, $userids, $limit, $offset) {
            $limit = $this->safe_limit($limit);
            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $params['quizid'] = $quizid;

            $extrafilter = '';
            if ($questionid !== null && $questionid > 0) {
                $extrafilter .= ' AND c.questionid = :questionid';
                $params['questionid'] = $questionid;
            }
            if ($userid !== null && $userid > 0) {
                $extrafilter .= ' AND c.userid = :userid';
                $params['userid'] = $userid;
            }
            if ($actiontype !== null && $actiontype !== '') {
                $extrafilter .= ' AND c.actiontype = :actiontype';
                $params['actiontype'] = $actiontype;
            }

            $userfilter = $this->build_user_filter($userids, $params, 'qcv_');

            $rs = $this->db->get_recordset_sql(
                "SELECT c.id, c.userid, c.questionid, c.actiontype,
                        c.starttime, c.endtime, c.messagecount,
                        " . self::$USER_FIELDS . ",
                        q.name as questionname
                 FROM {local_ai_prog_assist_conversation} c
                 JOIN {user} u ON u.id = c.userid
                 LEFT JOIN {question} q ON q.id = c.questionid
                 WHERE c.quizid = :quizid{$timewhere}{$extrafilter}{$userfilter}
                 ORDER BY c.starttime DESC",
                $params, $offset, $limit
            );

            $result = $this->collect_recordset($rs, function ($record) {
                $actiontypeval = $record->actiontype ?: 'free';
                return [
                    'id' => (int)$record->id,
                    'userid' => (int)$record->userid,
                    'studentname' => fullname($record),
                    'username' => $record->username,
                    'questionid' => (int)$record->questionid,
                    'questionname' => $record->questionname ?: ('Question #' . $record->questionid),
                    'actiontype' => $actiontypeval,
                    'actiontype_label' => \local_ai_programming_assistant\helper::get_mode_label($actiontypeval),
                    'messagecount' => (int)$record->messagecount,
                    'starttime' => (int)$record->starttime,
                    'starttime_str' => userdate((int)$record->starttime, '%Y-%m-%d %H:%M'),
                    'endtime' => (int)$record->endtime,
                    'endtime_str' => userdate((int)$record->endtime, '%Y-%m-%d %H:%M'),
                ];
            });

            return $result;
        });
    }

    /**
     * 统计测验下的会话数（支持按试题、学生、模式筛选）
     */
    public function count_quiz_conversations(int $quizid, ?int $starttime = null, ?int $endtime = null,
        ?int $questionid = null, ?int $userid = null, ?string $actiontype = null,
        ?array $userids = null): int {
        $cachekey = $this->cache_key('count_quiz_conversations', [
            'q' => $quizid, 's' => $starttime, 'e' => $endtime,
            'qi' => $questionid, 'u' => $userid, 'a' => $actiontype,
            'uids' => $userids,
        ]);
        return $this->cached($cachekey, function() use ($quizid, $starttime, $endtime, $questionid, $userid, $actiontype, $userids) {
            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $params['quizid'] = $quizid;

            $extrafilter = '';
            if ($questionid !== null && $questionid > 0) {
                $extrafilter .= ' AND c.questionid = :questionid';
                $params['questionid'] = $questionid;
            }
            if ($userid !== null && $userid > 0) {
                $extrafilter .= ' AND c.userid = :userid';
                $params['userid'] = $userid;
            }
            if ($actiontype !== null && $actiontype !== '') {
                $extrafilter .= ' AND c.actiontype = :actiontype';
                $params['actiontype'] = $actiontype;
            }

            $userfilter = $this->build_user_filter($userids, $params, 'qcc_');

            return (int)$this->db->get_field_sql(
                "SELECT COUNT(*) FROM {local_ai_prog_assist_conversation} c
                 WHERE c.quizid = :quizid{$timewhere}{$extrafilter}{$userfilter}",
                $params
            );
        });
    }

    /**
     * 获取指定会话信息
     *
     * @param int $conversationid 会话 ID
     * @return array|null 会话信息（含学生姓名）
     */
    public function get_conversation(int $conversationid): ?array {
        $cachekey = $this->cache_key('get_conversation', ['id' => $conversationid]);
        return $this->cached($cachekey, function() use ($conversationid) {
            $record = $this->db->get_record_sql(
                "SELECT c.id, c.userid, c.questionid, c.quizid, c.actiontype,
                        c.contextid, c.courseid, c.cmid, c.pagetype,
                        c.starttime, c.endtime, c.messagecount,
                        " . self::$USER_FIELDS . ",
                        co.fullname as coursename
                 FROM {local_ai_prog_assist_conversation} c
                 JOIN {user} u ON u.id = c.userid
                 LEFT JOIN {course} co ON co.id = c.courseid
                 WHERE c.id = :id",
                ['id' => $conversationid]
            );

            if (!$record) {
                return null;
            }

            $actiontype = $record->actiontype ?? 'free';
            return [
                'id' => (int)$record->id,
                'userid' => (int)$record->userid,
                'studentname' => fullname($record),
                'username' => $record->username,
                'questionid' => (int)$record->questionid,
                'quizid' => (int)$record->quizid,
                'actiontype' => $actiontype,
                'actiontype_label' => \local_ai_programming_assistant\helper::get_mode_label($actiontype),
                'contextid' => (int)$record->contextid,
                'courseid' => (int)$record->courseid,
                'cmid' => (int)$record->cmid,
                'pagetype' => $record->pagetype ?? '',
                'pagetype_label' => \local_ai_programming_assistant\helper::format_pagetype($record->pagetype ?? ''),
                'coursename' => $record->coursename ?? '',
                'starttime' => (int)$record->starttime,
                'starttime_str' => userdate((int)$record->starttime, '%Y-%m-%d %H:%M'),
                'endtime' => (int)$record->endtime,
                'endtime_str' => userdate((int)$record->endtime, '%Y-%m-%d %H:%M'),
                'messagecount' => (int)$record->messagecount,
                'context_name' => \local_ai_programming_assistant\helper::resolve_context_name((int)$record->contextid, $record->pagetype ?? ''),
            ];
        });
    }

    /**
     * 获取全局会话列表（支持按试题、学生、测验筛选，无筛选时等同于 get_conversations_list）
     *
     * @param int|null $questionid 试题 ID（可选）
     * @param int|null $userid 学生 ID（可选）
     * @param int|null $quizid 测验 ID（可选）
     * @param int|null $starttime 起始时间戳
     * @param int|null $endtime 结束时间戳
     * @param int $limit 每页记录数
     * @param int $offset 偏移量
     * @param string $search 学生姓名搜索关键字
     * @param string $order 排序方向：ASC（从早到晚）或 DESC（从晚到早，默认）
     * @return array 会话列表
     */
    public function get_filtered_conversations(?int $questionid = null, ?int $userid = null, ?int $quizid = null,
        ?string $actiontype = null, ?int $contextid = null, ?int $starttime = null, ?int $endtime = null, int $limit = 20, int $offset = 0, string $search = '', string $order = 'DESC', ?int $courseid = null): array {
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        $cachekey = $this->cache_key('get_filtered_conversations', [
            'qi' => $questionid, 'u' => $userid, 'q' => $quizid, 'a' => $actiontype, 'ctx' => $contextid,
            's' => $starttime, 'e' => $endtime, 'l' => $limit, 'o' => $offset, 'se' => $search, 'ord' => $order,
            'ci' => $courseid,
        ]);
        return $this->cached($cachekey, function() use ($questionid, $userid, $quizid, $actiontype, $contextid, $starttime, $endtime, $limit, $offset, $search, $order, $courseid) {
            $limit = $this->safe_limit($limit);
            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');

            $extrafilter = '';
            if ($questionid !== null && $questionid > 0) {
                $extrafilter .= ' AND c.questionid = :questionid';
                $params['questionid'] = $questionid;
            }
            if ($userid !== null && $userid > 0) {
                $extrafilter .= ' AND c.userid = :userid';
                $params['userid'] = $userid;
            }
            if ($quizid !== null && $quizid > 0) {
                $extrafilter .= ' AND c.quizid = :quizid';
                $params['quizid'] = $quizid;
            }
            if ($actiontype !== null && $actiontype !== '') {
                $extrafilter .= ' AND c.actiontype = :actiontype';
                $params['actiontype'] = $actiontype;
            }
            if ($contextid !== null && $contextid > 0) {
                $extrafilter .= ' AND c.contextid = :contextid';
                $params['contextid'] = $contextid;
            }
            if ($courseid !== null && $courseid > 0) {
                // 利用 conversation.courseid 索引过滤课程范围，与 stats 页课程筛选口径一致
                $extrafilter .= ' AND c.courseid = :courseid';
                $params['courseid'] = $courseid;
            }
            $extrafilter .= $this->build_user_search_where($search, $params, 'cls');

            $rs = $this->db->get_recordset_sql(
                "SELECT c.id, c.userid, c.questionid, c.quizid, c.actiontype,
                        c.contextid, c.courseid, c.cmid, c.pagetype,
                        c.starttime, c.endtime, c.messagecount,
                        co.fullname as coursename,
                        " . self::$USER_FIELDS . ",
                        q.name as questionname
                 FROM {local_ai_prog_assist_conversation} c
                 JOIN {user} u ON u.id = c.userid
                 LEFT JOIN {course} co ON co.id = c.courseid
                 LEFT JOIN {question} q ON q.id = c.questionid
                 WHERE 1=1{$timewhere}{$extrafilter}
                 ORDER BY c.starttime {$order}, c.id {$order}",
                $params, $offset, $limit
            );

            $result = $this->collect_recordset($rs, function ($record) {
                $actiontype = $record->actiontype ?? 'free';
                return [
                    'id' => (int)$record->id,
                    'userid' => (int)$record->userid,
                    'studentname' => fullname($record),
                    'username' => $record->username,
                    'questionid' => (int)$record->questionid,
                    'questionname' => is_string($record->questionname) ? $record->questionname : ('Question #' . $record->questionid),
                    'quizid' => (int)$record->quizid,
                    'actiontype' => $actiontype,
                    'actiontype_label' => \local_ai_programming_assistant\helper::get_mode_label($actiontype),
                    'contextid' => (int)$record->contextid,
                    'courseid' => (int)$record->courseid,
                    'cmid' => (int)$record->cmid,
                    'pagetype' => $record->pagetype ?? '',
                    'pagetype_label' => \local_ai_programming_assistant\helper::format_pagetype($record->pagetype ?? ''),
                    'coursename' => $record->coursename ?? '',
                    'starttime' => (int)$record->starttime,
                    'starttime_str' => userdate((int)$record->starttime, '%Y-%m-%d %H:%M'),
                    'endtime' => (int)$record->endtime,
                    'endtime_str' => userdate((int)$record->endtime, '%Y-%m-%d %H:%M'),
                    'messagecount' => (int)$record->messagecount,
                ];
            });

            // 解析上下文名称和链接（使用缓存避免重复查询）
            foreach ($result as &$row) {
                $ctxinfo = \local_ai_programming_assistant\helper::resolve_context_info($row['contextid'], $row['pagetype']);
                $row['context_name'] = $ctxinfo['name'];
                $row['context_url'] = $ctxinfo['url'] ? $ctxinfo['url']->out() : '';
            }
            unset($row);

            return $result;
        });
    }

    /**
     * 统计全局会话数（支持按试题、学生、测验筛选）
     */
    public function count_filtered_conversations(?int $questionid = null, ?int $userid = null, ?int $quizid = null,
        ?string $actiontype = null, ?int $contextid = null, ?int $starttime = null, ?int $endtime = null, string $search = '', ?int $courseid = null): int {
        $cachekey = $this->cache_key('count_filtered_conversations', [
            'qi' => $questionid, 'u' => $userid, 'q' => $quizid, 'a' => $actiontype, 'ctx' => $contextid,
            's' => $starttime, 'e' => $endtime, 'se' => $search, 'ci' => $courseid,
        ]);
        return $this->cached($cachekey, function() use ($questionid, $userid, $quizid, $actiontype, $contextid, $starttime, $endtime, $search, $courseid) {
            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');

            $extrafilter = '';
            if ($questionid !== null && $questionid > 0) {
                $extrafilter .= ' AND c.questionid = :questionid';
                $params['questionid'] = $questionid;
            }
            if ($userid !== null && $userid > 0) {
                $extrafilter .= ' AND c.userid = :userid';
                $params['userid'] = $userid;
            }
            if ($quizid !== null && $quizid > 0) {
                $extrafilter .= ' AND c.quizid = :quizid';
                $params['quizid'] = $quizid;
            }
            if ($actiontype !== null && $actiontype !== '') {
                $extrafilter .= ' AND c.actiontype = :actiontype';
                $params['actiontype'] = $actiontype;
            }
            if ($contextid !== null && $contextid > 0) {
                $extrafilter .= ' AND c.contextid = :contextid';
                $params['contextid'] = $contextid;
            }
            if ($courseid !== null && $courseid > 0) {
                $extrafilter .= ' AND c.courseid = :courseid';
                $params['courseid'] = $courseid;
            }

            // 搜索需要 JOIN user 表
            $userjoin = '';
            if ($search !== '') {
                $userjoin = 'JOIN {user} u ON u.id = c.userid';
                $extrafilter .= $this->build_user_search_where($search, $params, 'ccs');
            }

            return (int)$this->db->get_field_sql(
                "SELECT COUNT(*) FROM {local_ai_prog_assist_conversation} c
                 {$userjoin}
                 WHERE 1=1{$timewhere}{$extrafilter}",
                $params
            );
        });
    }

    /**
     * 获取指定会话的消息列表（按时间从早到晚排列）
     *
     * @param int $conversationid 会话 ID
     * @return array 消息列表
     */
    public function get_conversation_messages(int $conversationid): array {
        $cachekey = $this->cache_key('get_conversation_messages', ['id' => $conversationid]);
        return $this->cached($cachekey, function() use ($conversationid) {
            $rs = $this->db->get_recordset_sql(
                "SELECT id, conversationid, role, content, timestamp, metadata
                 FROM {local_ai_prog_assist_message}
                 WHERE conversationid = :conversationid
                 ORDER BY timestamp ASC, id ASC",
                ['conversationid' => $conversationid]
            );

            $result = $this->collect_recordset($rs, function ($record) {
                $role = $record->role ?? 'user';
                return [
                    'id' => (int)$record->id,
                    'conversationid' => (int)$record->conversationid,
                    'role' => $role,
                    'content' => $record->content,
                    'is_user' => ($role === 'user'),
                    'is_assistant' => ($role === 'assistant'),
                    'timestamp' => (int)$record->timestamp,
                    'timecreated_str' => userdate((int)$record->timestamp, '%Y-%m-%d %H:%M'),
                ];
            });

            return $result;
        });
    }

    /**
     * 批量删除会话及其消息
     *
     * 先删除子表（消息）记录，再删除会话记录，最后清除统计缓存。
     *
     * @param array $conversationids 会话 ID 数组
     * @return int 实际删除的会话数
     */
    public function delete_conversations(array $conversationids): int {
        if (empty($conversationids)) {
            return 0;
        }
        $ids = [];
        foreach ($conversationids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if (empty($ids)) {
            return 0;
        }

        // 事务保证消息（子表）和会话删除的原子性，防止产生孤立记录
        $transaction = $this->db->start_delegated_transaction();
        try {
            $this->db->delete_records_list('local_ai_prog_assist_message', 'conversationid', $ids);
            $this->db->delete_records_list('local_ai_prog_assist_conversation', 'id', $ids);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }

        // 清除统计缓存（仅在事务提交成功后执行）
        $this->purge_stats_cache();

        return count($ids);
    }

    /**
     * 按题目 ID 批量删除会话及其消息
     *
     * 可选过滤：quizid / courseid / 时间范围，避免删除统计页面范围之外的会话。
     *
     * @param array $questionids 题目 ID 数组
     * @param int|null $quizid 仅删除指定测验下的会话
     * @param int|null $courseid 仅删除指定课程下的会话
     * @param int|null $starttime 仅删除该时间戳之后的会话
     * @param int|null $endtime 仅删除该时间戳之前的会话
     * @return int 删除的会话数
     */
    public function delete_conversations_by_questionids(array $questionids, ?int $quizid = null,
        ?int $courseid = null, ?int $starttime = null, ?int $endtime = null): int {
        if (empty($questionids)) {
            return 0;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $questionids), function($id) { return $id > 0; })));
        if (empty($ids)) {
            return 0;
        }

        list($insql, $inparams) = $this->db->get_in_or_equal($ids);
        $select = "questionid {$insql}";
        if ($quizid > 0) {
            $select .= " AND quizid = :delquizid";
            $inparams['delquizid'] = $quizid;
        }
        if ($courseid > 0) {
            $select .= " AND courseid = :delcourseid";
            $inparams['delcourseid'] = $courseid;
        }
        if ($starttime > 0) {
            $select .= " AND starttime >= :delstarttime";
            $inparams['delstarttime'] = $starttime;
        }
        if ($endtime > 0) {
            $select .= " AND starttime <= :delendtime";
            $inparams['delendtime'] = $endtime;
        }
        $conversationids = $this->db->get_fieldset_select(
            'local_ai_prog_assist_conversation', 'id', $select, $inparams
        );

        if (empty($conversationids)) {
            return 0;
        }

        return $this->delete_conversations($conversationids);
    }

    /**
     * 按用户 ID 批量删除会话及其消息
     *
     * 可选过滤：quizid / courseid / 时间范围，避免删除统计页面范围之外的会话。
     *
     * @param array $userids 用户 ID 数组
     * @param bool $freeonly 是否仅删除自由模式（questionid = 0）的会话
     * @param int|null $quizid 仅删除指定测验下的会话
     * @param int|null $courseid 仅删除指定课程下的会话
     * @param int|null $starttime 仅删除该时间戳之后的会话
     * @param int|null $endtime 仅删除该时间戳之前的会话
     * @return int 删除的会话数
     */
    public function delete_conversations_by_userids(array $userids, bool $freeonly = false, ?int $quizid = null,
        ?int $courseid = null, ?int $starttime = null, ?int $endtime = null): int {
        if (empty($userids)) {
            return 0;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $userids), function($id) { return $id > 0; })));
        if (empty($ids)) {
            return 0;
        }

        list($insql, $inparams) = $this->db->get_in_or_equal($ids);
        $select = "userid {$insql}";
        if ($freeonly) {
            $select .= " AND questionid = 0";
        }
        if ($quizid > 0) {
            $select .= " AND quizid = :delquizid";
            $inparams['delquizid'] = $quizid;
        }
        if ($courseid > 0) {
            $select .= " AND courseid = :delcourseid";
            $inparams['delcourseid'] = $courseid;
        }
        if ($starttime > 0) {
            $select .= " AND starttime >= :delstarttime";
            $inparams['delstarttime'] = $starttime;
        }
        if ($endtime > 0) {
            $select .= " AND starttime <= :delendtime";
            $inparams['delendtime'] = $endtime;
        }
        $conversationids = $this->db->get_fieldset_select(
            'local_ai_prog_assist_conversation', 'id', $select, $inparams
        );

        if (empty($conversationids)) {
            return 0;
        }

        return $this->delete_conversations($conversationids);
    }
}