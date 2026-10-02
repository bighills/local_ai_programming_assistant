<?php
/**
 * AI 编程助手 - 自由模式统计查询仓库
 *
 * 封装所有自由模式（无题号）统计查询逻辑，
 * 数据来源：local_ai_prog_assist_conversation + local_ai_prog_assist_message 表。
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */

namespace local_ai_programming_assistant;

defined('MOODLE_INTERNAL') || die();

class free_log_stats_repository {

    use traits\base_stats_repository;

    /** @var array 可排序字段白名单（字段名 => SQL 表达式） */
    private const SORT_FIELDS = [
        'fullname' => 'u.firstname',
        'unique_pages' => 'unique_pages',
        'total_requests' => 'total_requests',
        'last_timecreated' => 'last_timecreated',
    ];

    public function __construct() {
        global $DB;
        $this->db = $DB;
    }

    /**
     * 获取自由模式（无题号）AI 反馈的整体统计
     */
    public function get_free_log_statistics(?int $starttime = null, ?int $endtime = null): array {
        $cachekey = $this->cache_key('get_free_log_statistics', ['s' => $starttime, 'e' => $endtime]);
        return $this->cached($cachekey, function() use ($starttime, $endtime) {
            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $where = '1=1' . $timewhere . ' AND c.questionid = 0';

            // CROSS JOIN 的 d 子查询需要独立参数名，使用 suffix 参数
            // 代替手写 where2，消除重复的 if/else 构建代码
            list($timewhere2, $params2) = $this->build_time_where($starttime, $endtime, 'c', 'starttime', '_d');
            $where2 = '1=1' . $timewhere2 . ' AND c.questionid = 0';
            $params = array_merge($params, $params2);

            $rs = $this->db->get_recordset_sql(
                "SELECT COALESCE(g.model, '') as model, g.pagetype, g.cnt, g.successful, g.avg_responsetime,
                        d.unique_users, d.unique_pages
                 FROM (
                     SELECT JSON_VALUE(c.metadata, '$.model') as model, c.pagetype,
                            COUNT(DISTINCT c.id) as cnt,
                            {$this->conv_meta_sum_success()} as successful,
                            {$this->conv_meta_avg_responsetime()} as avg_responsetime
                     FROM {local_ai_prog_assist_conversation} c
                     WHERE {$where}
                     GROUP BY JSON_VALUE(c.metadata, '$.model'), c.pagetype
                 ) g
                 CROSS JOIN (
                     SELECT COUNT(DISTINCT c.userid) as unique_users,
                            COUNT(DISTINCT c.contextid) as unique_pages
                     FROM {local_ai_prog_assist_conversation} c
                     WHERE {$where2}
                 ) d",
                $params
            );

            $total = 0; $successful = 0; $totalrt = 0.0; $rtcount = 0;
            $uniqueusers = 0; $uniquepages = 0;
            $modelcounts = []; $pagetypecounts = [];
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
                    $pagetype = $row->pagetype;
                    if (!empty($pagetype)) {
                        $pagetypecounts[$pagetype] = ($pagetypecounts[$pagetype] ?? 0) + $cnt;
                    }
                    if (!$statsinitialized) {
                        $uniqueusers = (int)$row->unique_users;
                        $uniquepages = (int)$row->unique_pages;
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

            arsort($pagetypecounts);
            $pagetypeusagearray = [];
            foreach ($pagetypecounts as $key => $val) {
                $pagetypeusagearray[] = ['key' => $key, 'value' => $val];
            }

            return [
                'total_requests' => $total,
                'successful_requests' => $successful,
                'failed_requests' => $failed,
                'success_rate' => $successrate,
                'avg_responsetime' => $avgresponsetime,
                'unique_users' => $uniqueusers,
                'unique_pages' => $uniquepages,
                'model_usage' => $modelusagearray,
                'pagetype_usage' => $pagetypeusagearray,
            ];
        });
    }

    /**
     * 获取自由模式（无题号）AI 反馈的学生统计（支持分页和排序）
     */
    public function get_free_log_student_stats(?int $starttime = null, ?int $endtime = null,
        int $limit = 20, string $search = '', int $offset = 0, string $sort = 'total_requests',
        string $order = 'DESC'): array {

        $cachekey = $this->cache_key('get_free_log_student_stats', [
            's' => $starttime, 'e' => $endtime, 'l' => $limit,
            'q' => $search, 'o' => $offset, 'st' => $sort, 'od' => $order,
        ]);
        return $this->cached($cachekey, function() use ($starttime, $endtime, $limit, $search, $offset, $sort, $order) {
            $limit = $this->safe_limit($limit);

            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $searchwhere = $this->build_user_search_where($search, $params, 'u');

            $sortfield = self::SORT_FIELDS[$sort] ?? 'total_requests';
            $sortdir = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

            $sql = "SELECT c.userid, " . self::$USER_FIELDS . ",
                        COUNT(DISTINCT c.id) as total_requests,
                        {$this->conv_meta_sum_success()} as successful_requests,
                        {$this->conv_meta_sum_failed()} as failed_requests,
                        {$this->conv_meta_avg_responsetime()} as avg_responsetime,
                        COUNT(DISTINCT c.contextid) as unique_pages,
                        MAX(c.starttime) as last_timecreated
                 FROM {local_ai_prog_assist_conversation} c
                 JOIN {user} u ON u.id = c.userid
                 WHERE c.questionid = 0{$timewhere}{$searchwhere}
                 GROUP BY " . self::$USER_GROUP_BY . "
                 ORDER BY {$sortfield} {$sortdir}";

            $recordset = $this->db->get_recordset_sql($sql, $params, $offset, $limit);

            $result = $this->collect_recordset($recordset, function ($record) {
                return [
                    'userid' => $record->userid,
                    'username' => $record->username,
                    'fullname' => fullname($record),
                    'total_requests' => $record->total_requests,
                    'successful_requests' => $record->successful_requests,
                    'failed_requests' => $record->failed_requests,
                    'success_rate' => $record->total_requests > 0 ? round(($record->successful_requests / $record->total_requests) * 100, 2) : 0,
                    'avg_responsetime' => $record->avg_responsetime !== null ? round((float)$record->avg_responsetime, 2) : 0,
                    'unique_pages' => (int)$record->unique_pages,
                    'last_timecreated' => (int)$record->last_timecreated,
                    'last_timecreated_str' => userdate($record->last_timecreated, '%Y-%m-%d %H:%M'),
                ];
            });

            return $result;
        });
    }

    /**
     * 统计自由模式（无题号）AI 反馈的学生总数
     */
    public function count_free_log_student_stats(?int $starttime = null, ?int $endtime = null, string $search = ''): int {
        $cachekey = $this->cache_key('count_free_log_student_stats', ['s' => $starttime, 'e' => $endtime, 'q' => $search]);
        return $this->cached($cachekey, function() use ($starttime, $endtime, $search) {
            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');

            // 无搜索条件时跳过 JOIN {user}，直接 COUNT(DISTINCT userid) 走索引
            $searchwhere = '';
            $userjoin = '';
            if ($search !== '') {
                $userjoin = 'JOIN {user} u ON u.id = c.userid';
                $searchwhere = $this->build_user_search_where($search, $params, 'u');
            }

            return (int)$this->db->get_field_sql(
                "SELECT COUNT(DISTINCT c.userid)
                 FROM {local_ai_prog_assist_conversation} c
                 {$userjoin}
                 WHERE c.questionid = 0{$timewhere}{$searchwhere}",
                $params
            );
        });
    }

    /**
     * 获取所有学生按页面+用户分组的最近自由对话记录
     *
     * 按 (contextid, userid) 分组，用于展示最近对话记录的一级页面。
     * 支持学生姓名搜索和时间范围过滤，按最近交互时间倒序排列。
     */
    public function get_recent_free_chats_grouped(?int $starttime = null, ?int $endtime = null,
        int $limit = 100, int $offset = 0, string $search = ''): array {
        $cachekey = $this->cache_key('get_recent_free_chats_grouped', [
            's' => $starttime, 'e' => $endtime, 'l' => $limit, 'o' => $offset, 'q' => $search,
        ]);
        return $this->cached($cachekey, function() use ($starttime, $endtime, $limit, $offset, $search) {
            $limit = $this->safe_limit($limit);

            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $searchwhere = $this->build_user_search_where($search, $params, 'rcg');

            $rs = $this->db->get_recordset_sql(
                "SELECT c.contextid, c.courseid, c.cmid, c.pagetype, c.userid,
                        co.fullname as coursename,
                        md.name as modname,
                        " . self::$USER_FIELDS . ",
                        COUNT(DISTINCT c.id) as interaction_count,
                        MAX(c.starttime) as last_interaction,
                        {$this->conv_meta_sum_success()} as successful_count
                 FROM {local_ai_prog_assist_conversation} c
                 JOIN {user} u ON u.id = c.userid
                 LEFT JOIN {course} co ON co.id = c.courseid
                 LEFT JOIN {course_modules} cm ON cm.id = c.cmid
                 LEFT JOIN {modules} md ON md.id = cm.module
                 WHERE c.questionid = 0{$timewhere}{$searchwhere}
                 GROUP BY c.contextid, c.courseid, c.cmid, c.pagetype, c.userid,
                          co.fullname, md.name, " . self::$USER_GROUP_BY . "
                 ORDER BY last_interaction DESC",
                $params, $offset, $limit
            );

            $result = $this->collect_recordset($rs, function ($record) {
                return [
                    'contextid' => (int)$record->contextid,
                    'courseid' => (int)$record->courseid,
                    'cmid' => (int)$record->cmid,
                    'pagetype' => $record->pagetype ?? 'unknown',
                    'userid' => (int)$record->userid,
                    'username' => $record->username,
                    'fullname' => fullname($record),
                    'coursename' => $record->coursename ?? '',
                    'modname' => $record->modname ?? '',
                    'interaction_count' => (int)$record->interaction_count,
                    'successful_count' => (int)$record->successful_count,
                    'last_interaction' => (int)$record->last_interaction,
                    'last_interaction_str' => userdate((int)$record->last_interaction, '%Y-%m-%d %H:%M'),
                ];
            });

            return $result;
        });
    }

    /**
     * 统计所有学生按页面+用户分组的最近自由对话记录组数
     */
    public function count_recent_free_chats_grouped(?int $starttime = null, ?int $endtime = null,
        string $search = ''): int {
        $cachekey = $this->cache_key('count_recent_free_chats_grouped', [
            's' => $starttime, 'e' => $endtime, 'q' => $search,
        ]);
        return $this->cached($cachekey, function() use ($starttime, $endtime, $search) {
            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');

            $searchwhere = '';
            $userjoin = '';
            if ($search !== '') {
                $userjoin = 'JOIN {user} u ON u.id = c.userid';
                $searchwhere = $this->build_user_search_where($search, $params, 'rcg');
            }

            return (int)$this->db->get_field_sql(
                "SELECT COUNT(*) FROM (
                    SELECT 1
                    FROM {local_ai_prog_assist_conversation} c
                    {$userjoin}
                    WHERE c.questionid = 0{$timewhere}{$searchwhere}
                    GROUP BY c.contextid, c.courseid, c.cmid, c.pagetype, c.userid
                ) sq",
                $params
            );
        });
    }

    /**
     * 获取自由模式（无题号）AI 反馈的聊天详情
     */
    public function get_free_log_chat_detail(int $userid, ?int $starttime = null, ?int $endtime = null, int $limit = 100, int $offset = 0, ?int $contextid = null): array {
        $cachekey = $this->cache_key('get_free_log_chat_detail', ['u' => $userid, 's' => $starttime, 'e' => $endtime, 'l' => $limit, 'o' => $offset, 'c' => $contextid]);
        return $this->cached($cachekey, function() use ($userid, $starttime, $endtime, $limit, $offset, $contextid) {
            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $params = array_merge(['userid' => $userid], $params);

            $contextwhere = '';
            if ($contextid !== null && $contextid > 0) {
                $contextwhere = ' AND c.contextid = :contextid';
                $params['contextid'] = $contextid;
            }

            // 派生表内重复外部会话过滤条件（c2 别名 + _wm 参数后缀），
            // 将 LEAD 窗口函数限定在目标会话范围内，避免全表扫描所有用户消息。
            list($timewhere2, $params2) = $this->build_time_where($starttime, $endtime, 'c2', 'starttime', '_wm');
            $params2 = array_merge(['userid_wm' => $userid], $params2);
            $contextwhere2 = '';
            if ($contextid !== null && $contextid > 0) {
                $contextwhere2 = ' AND c2.contextid = :contextid_wm';
                $params2['contextid_wm'] = $contextid;
            }
            $params = array_merge($params, $params2);

            $rs = $this->db->get_recordset_sql(
                "SELECT c.id as conversation_id, c.userid, c.questionid, c.contextid,
                        c.courseid, c.cmid, c.pagetype, c.actiontype,
                        m.id as message_id, m.content as airesponse, m.metadata, m.timestamp as timecreated,
                        {$this->meta_extract('model')} as model,
                        {$this->meta_extract('ai_backend')} as ai_backend,
                        {$this->meta_extract('success')} as success_val,
                        {$this->meta_extract('responsetime')} as responsetime_val,
                        {$this->meta_extract('errormessage')} as errormessage,
                        ms.content as student_message,
                        " . self::$USER_FIELDS . "
                 FROM {local_ai_prog_assist_conversation} c
                 JOIN {local_ai_prog_assist_message} m ON m.conversationid = c.id AND m.role = 'assistant'
                 LEFT JOIN (
                     SELECT um.conversationid, um.content, um.timestamp AS um_ts,
                            LEAD(um.timestamp) OVER (PARTITION BY um.conversationid ORDER BY um.timestamp ASC) AS next_um_ts
                     FROM {local_ai_prog_assist_message} um
                     JOIN {local_ai_prog_assist_conversation} c2 ON c2.id = um.conversationid
                     WHERE um.role = 'user' AND c2.userid = :userid_wm AND c2.questionid = 0{$timewhere2}{$contextwhere2}
                 ) ms ON ms.conversationid = c.id
                     AND ms.um_ts <= COALESCE(m.timestamp, c.endtime)
                     AND (ms.next_um_ts IS NULL OR ms.next_um_ts > COALESCE(m.timestamp, c.endtime))
                 JOIN {user} u ON u.id = c.userid
                 WHERE c.userid = :userid AND c.questionid = 0{$timewhere}{$contextwhere}
                 ORDER BY COALESCE(m.timestamp, c.starttime) ASC, COALESCE(m.id, 0) ASC",
                $params, $offset, $limit
            );

            $result = $this->collect_recordset($rs, function ($record) {
                $studentanswer = $record->student_message ?? '';
                return [
                    'id' => $record->message_id,
                    'userid' => $record->userid,
                    'username' => $record->username,
                    'fullname' => fullname($record),
                    'usermessage' => $studentanswer,
                    'airesponse' => helper::render_ai_response($record->airesponse ?? ''),
                    'studentanswer' => $studentanswer,
                    'model' => $record->model ?? '',
                    'ai_backend' => $record->ai_backend ?? '',
                    'success' => in_array(($record->success_val ?? ''), ['true', '1'], true),
                    'responsetime' => (int)($record->responsetime_val ?? 0),
                    'errormessage' => $record->errormessage ?? '',
                    'timecreated' => $record->timecreated,
                    'timecreated_str' => userdate($record->timecreated, '%Y-%m-%d %H:%M'),
                    'questionid' => (int)$record->questionid,
                    'contextid' => (int)$record->contextid,
                    'courseid' => (int)$record->courseid,
                    'cmid' => (int)$record->cmid,
                    'pagetype' => $record->pagetype ?? '',
                ];
            });

            return $result;
        });
    }

    /**
     * 统计自由模式（无题号）AI 反馈某学生的总记录数
     */
    public function count_free_log_chat(int $userid, ?int $starttime = null, ?int $endtime = null, ?int $contextid = null): int {
        $cachekey = $this->cache_key('count_free_log_chat', ['u' => $userid, 's' => $starttime, 'e' => $endtime, 'c' => $contextid]);
        return $this->cached($cachekey, function() use ($userid, $starttime, $endtime, $contextid) {
            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $params = array_merge(['userid' => $userid], $params);

            $contextwhere = '';
            if ($contextid !== null && $contextid > 0) {
                $contextwhere = ' AND c.contextid = :contextid';
                $params['contextid'] = $contextid;
            }

            return (int)$this->db->get_field_sql(
                "SELECT COUNT(*)
                 FROM {local_ai_prog_assist_conversation} c
                 JOIN {local_ai_prog_assist_message} m ON m.conversationid = c.id AND m.role = 'assistant'
                 WHERE c.userid = :userid AND c.questionid = 0{$timewhere}{$contextwhere}",
                $params
            );
        });
    }

    /**
     * 获取某学生在自由模式下使用过的页面列表（支持分页）
     */
    public function get_student_free_pages(int $userid, ?int $starttime = null, ?int $endtime = null,
        int $limit = 10, int $offset = 0): array {
        $cachekey = $this->cache_key('get_student_free_pages', [
            'u' => $userid, 's' => $starttime, 'e' => $endtime, 'l' => $limit, 'o' => $offset,
        ]);
        return $this->cached($cachekey, function() use ($userid, $starttime, $endtime, $limit, $offset) {
            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $params['userid'] = $userid;

            $rs = $this->db->get_recordset_sql(
                "SELECT c.contextid, c.courseid, c.cmid, c.pagetype,
                        co.fullname as coursename,
                        md.name as modname,
                        COUNT(DISTINCT c.id) as interaction_count,
                        MAX(c.starttime) as last_interaction,
                        {$this->conv_meta_sum_success()} as successful_count
                 FROM {local_ai_prog_assist_conversation} c
                 LEFT JOIN {course} co ON co.id = c.courseid
                 LEFT JOIN {course_modules} cm ON cm.id = c.cmid
                 LEFT JOIN {modules} md ON md.id = cm.module
                 WHERE c.userid = :userid AND c.questionid = 0{$timewhere}
                 GROUP BY c.contextid, c.courseid, c.cmid, c.pagetype, co.fullname, md.name
                 ORDER BY last_interaction DESC",
                $params, $offset, $limit
            );

            $result = $this->collect_recordset($rs, function ($record) {
                return [
                    'contextid' => (int)$record->contextid,
                    'courseid' => (int)$record->courseid,
                    'cmid' => (int)$record->cmid,
                    'pagetype' => $record->pagetype ?? 'unknown',
                    'coursename' => $record->coursename ?? '',
                    'modname' => $record->modname ?? '',
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
     * 统计某学生在自由模式下使用过的页面总数
     */
    public function count_student_free_pages(int $userid, ?int $starttime = null, ?int $endtime = null): int {
        $cachekey = $this->cache_key('count_student_free_pages', ['u' => $userid, 's' => $starttime, 'e' => $endtime]);
        return $this->cached($cachekey, function() use ($userid, $starttime, $endtime) {
            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $params['userid'] = $userid;

            return (int)$this->db->get_field_sql(
                "SELECT COUNT(DISTINCT c.contextid)
                 FROM {local_ai_prog_assist_conversation} c
                 WHERE c.userid = :userid AND c.questionid = 0{$timewhere}",
                $params
            );
        });
    }

    /**
     * 统计某学生在自由模式下全部页面的总交互次数
     *
     * 与 count_student_free_pages() 的差异：该方法是所有页面的会话总数
     * （COUNT(DISTINCT c.id)），而非页面数。页面列表徽章与 CSV 合计行
     * 必须使用全量值，不能对当前分页结果 array_sum（分页会导致合计偏小）。
     */
    public function get_student_free_pages_total_interactions(int $userid, ?int $starttime = null, ?int $endtime = null): int {
        $cachekey = $this->cache_key('get_student_free_pages_total_interactions', [
            'u' => $userid, 's' => $starttime, 'e' => $endtime,
        ]);
        return $this->cached($cachekey, function() use ($userid, $starttime, $endtime) {
            list($timewhere, $params) = $this->build_time_where($starttime, $endtime, 'c', 'starttime');
            $params['userid'] = $userid;

            return (int)$this->db->get_field_sql(
                "SELECT COUNT(DISTINCT c.id)
                 FROM {local_ai_prog_assist_conversation} c
                 WHERE c.userid = :userid AND c.questionid = 0{$timewhere}",
                $params
            );
        });
    }

    /**
     * 获取自由模式（无题号）AI 反馈的日统计
     */
    public function get_free_log_daily_stats(int $starttime, int $endtime): array {
        $cachekey = $this->cache_key('get_free_log_daily_stats', ['s' => $starttime, 'e' => $endtime]);
        return $this->cached($cachekey, function() use ($starttime, $endtime) {
            $day = 86400;
            $rs = $this->db->get_recordset_sql(
                "SELECT FLOOR(c.starttime / :day) as daynum,
                        COUNT(DISTINCT c.id) as total,
                        {$this->conv_meta_sum_success()} as successful,
                        {$this->conv_meta_avg_responsetime()} as avg_responsetime
                 FROM {local_ai_prog_assist_conversation} c
                 WHERE c.starttime >= :starttime AND c.starttime <= :endtime AND c.questionid = 0
                 GROUP BY daynum ORDER BY daynum ASC",
                ['starttime' => $starttime, 'endtime' => $endtime, 'day' => $day]
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
}