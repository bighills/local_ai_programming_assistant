<?php
/**
 * AI 编程助手 - 统计仓库公共 trait
 *
 * 提取 stats_repository 和 free_log_stats_repository 的公共基础设施代码。
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */

namespace local_ai_programming_assistant\traits;

defined('MOODLE_INTERNAL') || die();

trait base_stats_repository {

    /** @var int 查询结果上限 */
    protected static int $MAX_RESULTS = 5000;

    /** @var string SQL 中选择用户姓名字段的片段，含完整 GROUP BY 所需字段 */
    protected static string $USER_FIELDS = 'u.username, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename';

    /** @var string SQL 中 GROUP BY 用户字段的片段（使用 conversation 表别名 c） */
    protected static string $USER_GROUP_BY = 'c.userid, u.username, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename';

    /** @var \moodle_database */
    private \moodle_database $db;

    /** @var array 请求级查询缓存 */
    private array $querycache = [];

    /** @var int 请求级缓存最大条目数 */
    private static int $MAX_CACHE_ENTRIES = 100;

    /** @var \cache|null Moodle stats 缓存 */
    private ?\cache $statscache = null;

    /**
     * 获取 Moodle stats 缓存实例（跨请求持久缓存）
     */
    private function get_stats_cache(): \cache {
        if ($this->statscache === null) {
            $this->statscache = \cache::make('local_ai_programming_assistant', 'stats');
        }
        return $this->statscache;
    }

    /**
     * 清除统计缓存（数据变更后调用，如删除会话）
     */
    public function purge_stats_cache(): void {
        $this->get_stats_cache()->purge();
        $this->querycache = [];
    }

    /**
     * 生成缓存键（优化版）
     */
    private function cache_key(string $method, array $params = []): string {
        ksort($params);
        $paramstr = '';
        foreach ($params as $k => $v) {
            if (is_array($v)) {
                $paramstr .= $k . '=' . md5(json_encode($v, JSON_UNESCAPED_UNICODE)) . '&';
            } else {
                $paramstr .= $k . '=' . (string)$v . '&';
            }
        }
        return $method . '_' . md5($paramstr);
    }

    /**
     * 请求级缓存加载器（优化版 - 带大小限制和跨请求缓存支持）
     */
    private function cached(string $key, callable $loader, int $ttl = 300) {
        if (isset($this->querycache[$key])) {
            $entry = $this->querycache[$key];
            if (time() - $entry['time'] < $ttl) {
                return $entry['data'];
            }
            unset($this->querycache[$key]);
        }

        $statscache = $this->get_stats_cache();
        $persistkey = 'query_' . $key;
        $cached = $statscache->get($persistkey);
        if ($cached !== false) {
            $this->querycache[$key] = ['data' => $cached, 'time' => time()];
            return $cached;
        }

        if (count($this->querycache) >= self::$MAX_CACHE_ENTRIES) {
            array_shift($this->querycache);
        }
        $data = $loader();
        $this->querycache[$key] = ['data' => $data, 'time' => time()];
        // 注意：Moodle cache::set() 第三参数是 $strict(bool)，非 TTL；
        // 跨请求缓存的 TTL 由 db/caches.php 中的定义(300秒)控制，此处不传。
        $statscache->set($persistkey, $data);
        return $data;
    }

    /**
     * 构建时间范围 SQL WHERE 片段和参数
     *
     * @param int|null $starttime 起始时间戳
     * @param int|null $endtime 结束时间戳
     * @param string $alias 表别名
     * @param string $field 时间字段名（默认 timecreated，conversation 表用 starttime）
     * @param string $paramsuffix 参数名后缀（用于同一条 SQL 中多次调用时参数名不冲突，
     *                           例如 CROSS JOIN 需要两套独立的参数绑定）
     */
    private function build_time_where(?int $starttime, ?int $endtime, string $alias = '', string $field = 'timecreated', string $paramsuffix = ''): array {
        $params = [];
        $where = '';
        $prefix = $alias ? "{$alias}." : '';

        if ($starttime !== null) {
            $where .= " AND {$prefix}{$field} >= :starttime{$paramsuffix}";
            $params['starttime' . $paramsuffix] = $starttime;
        }
        if ($endtime !== null) {
            $where .= " AND {$prefix}{$field} <= :endtime{$paramsuffix}";
            $params['endtime' . $paramsuffix] = $endtime;
        }

        return [$where, $params];
    }

    // ===================== JSON metadata 提取辅助方法 =====================

    /**
     * 从 message.metadata JSON 中提取指定键的 SQL 表达式
     *
     * 使用 JSON_VALUE() 函数（MariaDB 10.5+ / MySQL 5.7+）提取 JSON 值。
     * 不依赖 Moodle DB 层的 sql_json_extract()，兼容所有数据库驱动。
     *
     * @param string $key JSON 键名（model, success, responsetime, ai_backend, errormessage）
     * @return string SQL 表达式
     */
    protected function meta_extract(string $key): string {
        return "JSON_VALUE(m.metadata, '$.{$key}')";
    }

    /**
     * 构建 message.metadata.success 的 SUM SQL 表达式
     * JSON 中 success 存储为 boolean（true/false），需转换为 1/0
     */
    protected function meta_sum_success(): string {
        $expr = $this->meta_extract('success');
        // MariaDB 的 JSON_VALUE 返回 '1'（而非 'true'），MySQL 返回 'true'
        return "SUM(CASE WHEN {$expr} IN ('true', '1') THEN 1 ELSE 0 END)";
    }

    /**
     * 构建 message.metadata.success 的 CASE 表达式（不含 SUM 包装）
     * 用于需要单独使用 success 判断的场景（如 failed_requests = COUNT(*) - SUM(...)）
     */
    protected function meta_success_case(): string {
        $expr = $this->meta_extract('success');
        return "CASE WHEN {$expr} IN ('true', '1') THEN 1 ELSE 0 END";
    }

    /**
     * 构建失败请求数的 SQL 表达式：COUNT(*) - 成功数
     */
    protected function meta_sum_failed(): string {
        return "(COUNT(*) - SUM({$this->meta_success_case()}))";
    }

    /**
     * 构建 message.metadata.responsetime 的 AVG SQL 表达式
     * JSON 中 responsetime 存储为整数字符串
     */
    protected function meta_avg_responsetime(): string {
        $expr = $this->meta_extract('responsetime');
        return "AVG(CAST({$expr} AS DECIMAL(10,2)))";
    }

    // ===================== conversation.metadata 辅助方法 =====================
    // conversation 表的 metadata 与 message 表的 metadata 包含相同字段（success, model, tokens, responsetime）。
    // 使用 c.metadata 可避免 LEFT JOIN message 表导致的行膨胀问题（一个会话有多条 AI 消息时 COUNT(*) 会被放大）。

    /**
     * 从 conversation.metadata JSON 中提取指定键的 SQL 表达式
     */
    protected function conv_meta_extract(string $key): string {
        return "JSON_VALUE(c.metadata, '$.{$key}')";
    }

    /**
     * conversation.metadata.success 的 SUM 表达式
     */
    protected function conv_meta_sum_success(): string {
        $expr = $this->conv_meta_extract('success');
        return "SUM(CASE WHEN {$expr} IN ('true', '1') THEN 1 ELSE 0 END)";
    }

    /**
     * 失败请求数：COUNT(DISTINCT c.id) - 成功数
     */
    protected function conv_meta_sum_failed(): string {
        $expr = $this->conv_meta_extract('success');
        return "(COUNT(DISTINCT c.id) - SUM(CASE WHEN {$expr} IN ('true', '1') THEN 1 ELSE 0 END))";
    }

    /**
     * conversation.metadata.responsetime 的 AVG 表达式
     */
    protected function conv_meta_avg_responsetime(): string {
        $expr = $this->conv_meta_extract('responsetime');
        return "AVG(CAST({$expr} AS DECIMAL(10,2)))";
    }

    /**
     * 安全限制查询结果数
     */
    private function safe_limit(int $limit, int $max = 0): int {
        if ($max <= 0) {
            $max = self::$MAX_RESULTS;
        }
        return min(max(1, $limit), $max);
    }

    /**
     * 从聚合记录中提取通用统计字段
     */
    private function extract_common_stats(\stdClass $record): array {
        $total = (int)$record->total_requests;
        $successful = (int)$record->successful_requests;
        return [
            'failed_requests' => $total - $successful,
            'success_rate' => $total > 0 ? round(($successful / $total) * 100, 2) : 0,
            'avg_responsetime' => $record->avg_responsetime !== null ? round((float)$record->avg_responsetime, 2) : 0,
        ];
    }

    /**
     * 构建用户搜索过滤 SQL 片段和参数
     */
    private function build_user_search_where(string $search, array &$params, string $prefix = 'se'): string {
        if ($search === '') {
            return '';
        }
        $searchparam = '%' . $this->db->sql_like_escape($search) . '%';
        $params["{$prefix}1"] = $searchparam;
        $params["{$prefix}2"] = $searchparam;
        $params["{$prefix}3"] = $searchparam;
        return " AND (u.firstname LIKE :{$prefix}1 OR u.lastname LIKE :{$prefix}2 OR u.username LIKE :{$prefix}3)";
    }

    /**
     * 构建用户 ID 过滤 SQL 片段并合并参数
     *
     * 消除方法中重复的 get_in_or_equal + array_merge 模式。
     *
     * @param array|null $userids 用户 ID 数组
     * @param array &$params 参数引用（会被合并）
     * @param string $prefix SQL 参数命名前缀
     * @param string $alias 表别名（默认 c，对应 conversation 表）
     * @return string 如 " AND c.userid IN (:uid1,:uid2)" 或空字符串
     */
    private function build_user_filter(?array $userids, array &$params, string $prefix = 'u_', string $alias = 'c'): string {
        if (empty($userids)) {
            return '';
        }
        list($usql, $uparams) = $this->db->get_in_or_equal($userids, SQL_PARAMS_NAMED, $prefix);
        $params = array_merge($params, $uparams);
        return " AND {$alias}.userid {$usql}";
    }

    /**
     * 构建课程 ID 过滤 SQL 片段并合并参数
     *
     * 利用 conversation 表的 courseid 字段（含 courseid-starttime 复合索引）直接过滤，
     * 避免对 quiz 表做子查询。courseid <= 0 时不添加任何条件（全局视图）。
     *
     * @param int|null $courseid 课程 ID
     * @param array &$params 参数引用（会被合并）
     * @param string $prefix SQL 参数命名前缀
     * @param string $alias 表别名（默认 c，对应 conversation 表）
     * @return string 如 " AND c.courseid = :cid_xxx" 或空字符串
     */
    private function build_course_filter(?int $courseid, array &$params, string $prefix = 'cid_', string $alias = 'c'): string {
        if ($courseid === null || $courseid <= 0) {
            return '';
        }
        $paramname = $prefix . 'courseid';
        $params[$paramname] = $courseid;
        return " AND {$alias}.courseid = :{$paramname}";
    }

    /**
     * 安全遍历 recordset 并收集为数组，保证异常时也能关闭资源。
     *
     * 替代手写的 foreach + $rs->close() 模式，防止迭代过程抛出异常时
     * recordset 未关闭导致数据库游标泄漏。
     *
     * @param \moodle_recordset $rs 数据库记录集
     * @param callable|null $mapper 可选的行映射函数 (row) => mixed；返回值会被收集
     * @return array 收集到的结果数组
     */
    private function collect_recordset(\moodle_recordset $rs, ?callable $mapper = null): array {
        $results = [];
        try {
            if ($mapper !== null) {
                foreach ($rs as $row) {
                    $results[] = $mapper($row);
                }
            } else {
                foreach ($rs as $row) {
                    $results[] = $row;
                }
            }
        } finally {
            $rs->close();
        }
        return $results;
    }
}