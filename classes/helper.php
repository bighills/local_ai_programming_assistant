<?php
/**
 * AI 编程助手 - 工具类
 *
 * 提供日期范围解析、模式标签获取等通用辅助方法。
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */

namespace local_ai_programming_assistant;

defined('MOODLE_INTERNAL') || die();

class helper {

    const DEFAULT_DAYS = 30;
    const QUIZ_DEFAULT_DAYS = 90;
    const MAX_RANGE_DAYS = 730; // 2年

    /**
     * 重置所有静态缓存（用于 PHP-FPM 常驻进程或单元测试）
     */
    public static function reset(): void {
        self::$modelabelcache = [];
        self::$contextinfocache = [];
        self::$userdisplaycache = [];
    }

    // ===================== 日期范围工具 =====================

    /**
     * 解析日期范围，返回时间戳和显示字符串
     *
     * @param string $starttime 起始日期字符串
     * @param string $endtime 结束日期字符串
     * @return array{starttimestamp: ?int, endtimestamp: ?int, starttime_display: string, endtime_display: string}
     */
    public static function parse_date_range(string $starttime = '', string $endtime = ''): array {
        $starttimestamp = null;
        $endtimestamp = null;
        $starttime_display = '';
        $endtime_display = '';

        if (!empty($starttime)) {
            $parsed = strtotime($starttime);
            $starttimestamp = $parsed !== false ? $parsed : null;
            $starttime_display = $starttime;
        }

        if (!empty($endtime)) {
            $parsed = strtotime($endtime);
            $endtimestamp = $parsed !== false ? ($parsed + 86399) : null;
            $endtime_display = $endtime;
        }

        return [
            'starttimestamp' => $starttimestamp,
            'endtimestamp' => $endtimestamp,
            'starttime_display' => $starttime_display,
            'endtime_display' => $endtime_display,
        ];
    }

    /**
     * 校验并修正日期范围：默认天数、上限、起止颠倒恢复
     *
     * @param int|null &$starttime 起始时间戳（引用）
     * @param int|null &$endtime 结束时间戳（引用）
     * @param string &$starttime_display 起始日期显示（引用）
     * @param string &$endtime_display 结束日期显示（引用）
     * @param int $defaultdays 未指定时的默认天数
     */
    public static function sanitize_date_range(?int &$starttime, ?int &$endtime,
            string &$starttime_display, string &$endtime_display,
            int $defaultdays = self::DEFAULT_DAYS): void {
        $maxrange = self::MAX_RANGE_DAYS * 86400;
        $defaultsecs = $defaultdays * 86400;

        // 确保两个时间戳都不为 null，避免后续的 null-int 运算触发 PHP 8.1+ 弃用警告
        $now = time();
        if ($starttime === null && $endtime === null) {
            $endtime = $now;
            $starttime = $endtime - $defaultsecs;
        } elseif ($starttime === null) {
            $starttime = $endtime - $defaultsecs;
        } elseif ($endtime === null) {
            $endtime = $starttime + $defaultsecs;
        }

        // 此时 $starttime 和 $endtime 都已保证为非 null 的 int
        if ($endtime - $starttime > $maxrange) {
            $starttime = $endtime - $maxrange;
        }
        if ($starttime > $endtime) {
            $endtime = $now;
            $starttime = $endtime - $defaultsecs;
            // 起止颠倒被重置为默认范围后，显示字符串必须同步重置，
            // 否则页面筛选框/导航 URL 保留用户输入的颠倒日期，与实际查询范围不一致
            $starttime_display = userdate($starttime, '%Y-%m-%d');
            $endtime_display = userdate($endtime, '%Y-%m-%d');
        }
        if (empty($starttime_display)) {
            $starttime_display = userdate($starttime, '%Y-%m-%d');
        }
        if (empty($endtime_display)) {
            $endtime_display = userdate($endtime, '%Y-%m-%d');
        }
    }

    /**
     * 解析并标准化日期范围 — 一键完成 parse_date_range + sanitize_date_range 两步骤。
     *
     * 消除 chat_detail.php、question_detail.php、student_questions.php 等 7 个页面中重复的 7 行模板代码。
     *
     * @param string $starttime 起始日期字符串
     * @param string $endtime 结束日期字符串
     * @param int $defaultdays 未指定时的默认天数
     * @return array{starttimestamp: ?int, endtimestamp: ?int, starttime_display: string, endtime_display: string}
     */
    public static function resolve_date_range(string $starttime = '', string $endtime = '', int $defaultdays = self::DEFAULT_DAYS): array {
        $range = self::parse_date_range($starttime, $endtime);
        self::sanitize_date_range(
            $range['starttimestamp'],
            $range['endtimestamp'],
            $range['starttime_display'],
            $range['endtime_display'],
            $defaultdays
        );
        return $range;
    }

    // ===================== 统计计算工具 =====================

    /**
     * 计算统计数据集的合计行（请求数、成功率、加权响应时间）
     *
     * @param array $items 统计数据数组，每项包含 total_requests, successful_requests, failed_requests, avg_responsetime
     * @param array $extrasumfields 额外需要求和的字段名列表（如 unique_questions）
     * @return array{total_requests: int, successful_requests: int, failed_requests: int, success_rate: float, avg_responsetime: float}
     */
    public static function compute_stats_totals(array $items, array $extrasumfields = []): array {
        $fields = ['total_requests', 'successful_requests', 'failed_requests', 'avg_responsetime'];
        $totals = array_fill_keys($fields, 0);
        foreach ($extrasumfields as $field) {
            $totals[$field] = 0;
        }

        $totalresp = 0;
        foreach ($items as $item) {
            $tr = (int)($item['total_requests'] ?? 0);
            $totals['total_requests'] += $tr;
            $totals['successful_requests'] += (int)($item['successful_requests'] ?? 0);
            $totals['failed_requests'] += (int)($item['failed_requests'] ?? 0);
            $totalresp += (float)($item['avg_responsetime'] ?? 0) * $tr;
            foreach ($extrasumfields as $field) {
                $totals[$field] += (int)($item[$field] ?? 0);
            }
        }

        $totals['avg_responsetime'] = $totals['total_requests'] > 0
            ? round($totalresp / $totals['total_requests'], 2) : 0;
        $totals['success_rate'] = $totals['total_requests'] > 0
            ? round(($totals['successful_requests'] / $totals['total_requests']) * 100, 2) : 0;
        return $totals;
    }

    /**
     * 计算测验统计数据集的合计行（含各模式计数）
     *
     * @param array $items 统计数据数组，每项额外包含 mode_question_analysis_count, mode_idea_organization_count, mode_code_debugging_count, mode_free_count
     * @return array 含模式计数的合计行
     */
    public static function compute_quiz_stats_totals(array $items): array {
        return self::compute_stats_totals($items, [
            'mode_question_analysis_count',
            'mode_idea_organization_count',
            'mode_code_debugging_count',
            'mode_free_count',
        ]);
    }

    /** @var array<string, string> 模式标识 → 语言字符串键映射 */
    private const MODE_MAP = [
        'question_analysis' => 'mode_question_analysis',
        'idea_organization' => 'mode_idea_organization',
        'code_debugging' => 'mode_code_debugging',
        'free' => 'mode_free',
    ];

    // ===================== 显示格式化工具 =====================

    /** @var array<string, string> 模式标签请求级缓存 */
    private static array $modelabelcache = [];

    public static function get_mode_label(string $mode): string {
        if (isset(self::$modelabelcache[$mode])) {
            return self::$modelabelcache[$mode];
        }
        $langkey = self::MODE_MAP[$mode] ?? null;
        if ($langkey !== null) {
            $str = \get_string($langkey, 'local_ai_programming_assistant');
            if ($str !== $langkey) {
                self::$modelabelcache[$mode] = $str;
                return $str;
            }
        }
        $label = ucfirst(str_replace('_', ' ', $mode));
        self::$modelabelcache[$mode] = $label;
        return $label;
    }

    /**
     * 格式化页面类型为可读名称
     *
     * @param string $pagetype Moodle 页面类型标识（如 mod-quiz-view, course-view）
     * @return string 格式化后的可读名称
     */
    public static function format_pagetype(string $pagetype): string {
        // 将 mod-quiz-view → Quiz, course-view → Course, site-index → Site
        $parts = explode('-', $pagetype);
        // 取倒数第二个部分（通常表示活动类型），如果是 view 则再前推
        $name = '';
        for ($i = count($parts) - 2; $i >= 0; $i--) {
            if ($parts[$i] !== 'mod' && $parts[$i] !== 'view') {
                $name = $parts[$i];
                break;
            }
        }
        if (empty($name)) {
            $name = end($parts);
        }
        return ucfirst(str_replace('_', ' ', $name));
    }

    /**
     * 获取常用时间范围预设
     *
     * 返回预设名称及对应的 starttime_display / endtime_display 值，
     * 用于在统计页面上快速切换时间范围。
     *
     * @param int $selecteddays 当前选中的天数（用于标记 active）
     * @param int|null $starttime 当前起始时间戳
     * @param int|null $endtime 当前结束时间戳
     * @return array 预设列表，每项含 days, label, starttime_display, endtime_display, isactive
     */
    public static function get_date_presets(?int $starttime = null, ?int $endtime = null): array {
        $presetdays = [7, 30, 90];
        $presets = [];

        if ($endtime === null) {
            $endtime = time();
        }
        // 修复：直接比较 $starttime 与预设起点，而非比较范围差值
        // 避免 $currentrange 因 $starttime === $endtime 为 0 而无法匹配任何预设
        foreach ($presetdays as $days) {
            $pstart = $endtime - ($days * 86400);
            // 判断是否当前选中：比较起始时间戳，允许 ±1 小时误差
            $isactive = ($starttime !== null) && abs($pstart - $starttime) < 3600;

            $presets[] = [
                'days' => $days,
                'label' => get_string('stats_preset_days', 'local_ai_programming_assistant', $days),
                'starttime_display' => userdate($pstart, '%Y-%m-%d'),
                'endtime_display' => userdate($endtime, '%Y-%m-%d'),
                'isactive' => $isactive,
            ];
        }

        return $presets;
    }

    /**
     * 渲染 AI 回复内容
     *
     * 如果内容已经是 HTML（blocking 路径已存 HTML），按 HTML 净化后返回；
     * 否则按 Markdown 格式渲染。
     * 注意：不能仅用 strpos($content, '<') 判断，因为代码中常有 <=、< 等运算符。
     * 安全：即使命中"疑似 HTML"分支也必须经 clean_text 白名单净化，
     * 防止 AI 回复中的 <script>、<img onerror> 等构成存储型 XSS。
     *
     * @param string $content 原始 AI 回复内容
     * @return string 渲染后的 HTML
     */
    public static function render_ai_response(string $content): string {
        if ($content === '') {
            return '';
        }
        $isalreadyhtml = preg_match('/^\s*<(?:p|div|ul|ol|li|h[1-6]|pre|table|span|code|blockquote|br|em|strong|a|img|hr|sub|sup|mark)[\s>\/]/i', $content);
        if ($isalreadyhtml) {
            // 已含 HTML 的内容也必须净化（clean_text 走 HTMLPurifier 白名单）
            return clean_text($content, FORMAT_HTML);
        }
        return format_text($content, FORMAT_MARKDOWN, ['context' => \context_system::instance()]);
    }

    // ===================== 上下文解析工具 =====================

    /**
     * 根据 contextid 解析页面/上下文名称
     *
     * 支持课程（CONTEXT_COURSE）、活动模块（CONTEXT_MODULE）、用户（CONTEXT_USER）等上下文级别。
     *
     * @param int    $contextid 上下文 ID
     * @param string $fallback  解析失败时的默认名称
     * @return string 可读的页面名称
     */
    public static function resolve_context_name(int $contextid, string $fallback = ''): string {
        $info = self::resolve_context_info($contextid, $fallback);
        return $info['name'] ?: ($fallback ?: '#' . $contextid);
    }

    /** @var array 请求级上下文信息缓存 */
    private static array $contextinfocache = [];

    /** @var array 请求级用户信息缓存 */
    private static array $userdisplaycache = [];

    /**
     * 根据 contextid 解析上下文信息（名称 + 链接 URL）
     *
     * 使用请求级静态缓存，避免相同 contextid 在循环中的重复数据库查询。
     *
     * @param int    $contextid 上下文 ID
     * @param string $pagetype  页面类型（解析失败时的 fallback 名称组成部分）
     * @return array{name: string, url: ?\moodle_url} 包含名称和链接 URL 的数组
     */
    public static function resolve_context_info(int $contextid, string $pagetype = ''): array {
        if (isset(self::$contextinfocache[$contextid])) {
            return self::$contextinfocache[$contextid];
        }
        $result = ['name' => '', 'url' => null];
        try {
            $ctx = \context::instance_by_id($contextid);
            if ($ctx->contextlevel == CONTEXT_COURSE) {
                $course = get_course($ctx->instanceid);
                $result['name'] = $course->fullname;
                $result['url'] = new \moodle_url('/course/view.php', ['id' => $ctx->instanceid]);
            } else if ($ctx->contextlevel == CONTEXT_MODULE) {
                list($course, $cm) = get_course_and_cm_from_cmid($ctx->instanceid);
                $result['name'] = $cm->name;
                $result['url'] = new \moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]);
            } else if ($ctx->contextlevel == CONTEXT_USER) {
                $user = \core_user::get_user($ctx->instanceid);
                $result['name'] = $user ? fullname($user) : '';
                $result['url'] = new \moodle_url('/user/profile.php', ['id' => $ctx->instanceid]);
            }
        } catch (\Throwable $e) {
            config_manager::debug_log('resolve_context_info failed for contextid ' . $contextid, $e->getMessage());
        }
        if (empty($result['name'])) {
            $result['name'] = $pagetype ? $pagetype . ' (#' . $contextid . ')' : '#' . $contextid;
        }
        self::$contextinfocache[$contextid] = $result;
        return $result;
    }

    /**
     * 根据 contextid 获取上下文对象
     *
     * 安全策略：
     * - contextid > 0 但无效时抛出异常，防止攻击者通过提供不存在的 contextid
     *   绕过基于上下文的权限检查（如将课程级权限降级为系统级）。
     * - contextid <= 0 时回退到系统上下文，用于自由对话场景。
     *
     * @param int $contextid 上下文 ID
     * @return \context 上下文对象
     * @throws \moodle_exception 当 contextid > 0 但上下文不存在时
     */
    public static function resolve_context_from_id(int $contextid): \context {
        if ($contextid > 0) {
            try {
                return \context::instance_by_id($contextid, MUST_EXIST);
            } catch (\Throwable $e) {
                // 上下文不存在或无效时抛出异常，不静默回退到 SYSCONTEXTID
                // 静默回退会允许攻击者通过提供无效 contextid 绕过上下文权限检查
                throw new \moodle_exception(
                    'error_invalid_context',
                    'local_ai_programming_assistant',
                    '',
                    [],
                    'Context id ' . $contextid . ' not found'
                );
            }
        }
        return \context::instance_by_id(SYSCONTEXTID);
    }

    /**
     * 从上下文对象中提取 courseid 和 cmid
     *
     * @param \context $context 上下文对象
     * @return array{courseid: int, cmid: int} 课程ID和课程模块ID
     */
    public static function resolve_course_and_cm_from_context(\context $context): array {
        $courseid = 0;
        $cmid = 0;
        if ($context instanceof \context_module) {
            $cmid = $context->instanceid;
            $coursecontext = $context->get_course_context(false);
            if ($coursecontext) {
                $courseid = $coursecontext->instanceid;
            }
        } else if ($context instanceof \context_course) {
            $courseid = $context->instanceid;
        }
        return ['courseid' => $courseid, 'cmid' => $cmid];
    }

    // ===================== 分页工具 =====================

    /**
     * 构建分页器上下文数组（消除 3 个统计页面的重复分页计算）
     *
     * @param int $page      当前页码（从 1 开始）
     * @param int $perpage   每页记录数
     * @param int $total     总记录数
     * @return array 可直接合并到模板上下文的数组（page, totalpages, hasmulti, pageone, pagelast, prevpage, nextpage, pagenums）
     */
    public static function build_pagination_context(int $page, int $perpage, int $total): array {
        $page = max(1, $page);
        $totalpages = max(1, (int)ceil($total / $perpage));
        $ctx = [
            'page' => $page,
            'totalpages' => $totalpages,
            'hasmulti' => $total > $perpage,
            'pageone' => ($page <= 1),
            'pagelast' => ($page >= $totalpages),
            'prevpage' => max(1, $page - 1),
            'nextpage' => min($totalpages, $page + 1),
        ];
        $pagenums = [];
        $startpage = max(1, $page - 3);
        $endpage = min($totalpages, $page + 3);
        for ($i = $startpage; $i <= $endpage; $i++) {
            $pagenums[] = ['num' => $i, 'active' => ($i == $page)];
        }
        $ctx['pagenums'] = $pagenums;
        return $ctx;
    }

    /**
     * 生成带前缀的分页上下文（用于同一页面多个独立分区域）
     *
     * @param string $prefix 变量名前缀，如 'q' 生成 qpage, qtotalpages, qhasmulti...
     * @return array
     */
    public static function build_pagination_context_prefix(int $page, int $perpage, int $total, string $prefix): array {
        $ctx = self::build_pagination_context($page, $perpage, $total);
        $prefixed = [];
        foreach ($ctx as $key => $value) {
            $prefixed[$prefix . $key] = $value;
        }
        return $prefixed;
    }

    // ===================== CSV 导出工具 =====================

    /**
     * 设置 CSV 导出 HTTP 头并返回输出句柄（消除 4 个统计页面的重复 CSV 样板代码）
     *
     * @param string $filename 导出的文件名（不含扩展名，不含日期）
     * @return resource fopen('php://output', 'w') 句柄
     */
    public static function export_csv_header(string $filename) {
        // 过滤文件名中的特殊字符，防止 HTTP 头注入
        // preg_replace 带 /u 修饰符在输入含非法 UTF-8 字节时返回 null，
        // 回退到 fix_utf8 清洗后重试，保证 $filename 始终为有效字符串
        $cleaned = preg_replace('/[^a-zA-Z0-9_\-\x{4e00}-\x{9fa5}]/u', '', $filename);
        if ($cleaned === null) {
            $cleaned = preg_replace('/[^a-zA-Z0-9_\-]/', '', fix_utf8($filename));
        }
        $filename = $cleaned;
        $filename = $filename . '_' . date('Ymd') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $output = fopen('php://output', 'w');
        fputs($output, "\xEF\xBB\xBF"); // BOM for Excel UTF-8 compatibility
        return $output;
    }

    /**
     * 完成 CSV 导出并终止请求
     *
     * @param resource $output fopen 句柄
     */
    public static function finish_csv_export($output): void {
        fclose($output);
        exit;
    }

    /**
     * 净化 CSV 单元格值，防止公式注入
     *
     * 以 = + - @ \t \r 开头的单元格会被 Excel/LibreOffice 解释为公式，
     * 可能导致命令执行（CSV injection / formula injection）。
     * 前缀单引号 ' 阻止公式解析（单引号在 Excel 中不显示，仅作为文本前缀标记）。
     *
     * @param string $value 原始单元格值
     * @return string 净化后的值
     */
    public static function sanitize_csv_cell(string $value): string {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * 净化 CSV 行中所有字符串值（整数/浮点数等标量类型不受影响）
     *
     * @param array $row 原始行数据
     * @return array 净化后的行数据
     */
    public static function sanitize_csv_row(array $row): array {
        foreach ($row as $i => $value) {
            if (is_string($value)) {
                $row[$i] = self::sanitize_csv_cell($value);
            }
        }
        return $row;
    }

    /**
     * 安全的 fputcsv 包装器：自动净化所有字符串单元格后写入
     *
     * @param resource $output fopen 句柄
     * @param array $fields 行数据
     * @return int|false 写入的字符串长度，或 false 失败
     */
    public static function csv_put($output, array $fields) {
        return fputcsv($output, self::sanitize_csv_row($fields));
    }

    // ===================== 用户信息工具 =====================

    /**
     * 获取用户的显示名称和用户名（消除 4 个统计页面的重复 core_user::get_user 调用）
     *
     * @param int $userid 用户 ID
     * @return array{name: string, username: string} 用户全名和登录名
     */
    public static function get_user_display_info(int $userid): array {
        if (!isset(self::$userdisplaycache[$userid])) {
            $userrecord = \core_user::get_user($userid);
            self::$userdisplaycache[$userid] = [
                'name' => $userrecord ? fullname($userrecord) : '',
                'username' => $userrecord ? $userrecord->username : '',
            ];
        }
        return self::$userdisplaycache[$userid];
    }

    // ===================== 前端资源工具 =====================

    /**
     * 初始化代码高亮所需的 CSS/JS 引用（消除 3 个详情页的重复代码）
     *
     * @param \moodle_page $page $PAGE 对象
     */
    public static function init_code_highlight(\moodle_page $page): void {
        $page->requires->css('/filter/codehighlighter/styles.css');
        $page->requires->js_call_amd('local_ai_programming_assistant/code_highlight', 'init', ['div.border.p-3.rounded']);
    }

    /**
     * 初始化统计页面的通用设置
     *
     * 消除 3 个统计页面（stats.php、quiz_stats.php、free_stats.php）
     * 中重复的页面初始化和日期解析逻辑。
     *
     * @param string $pagetitle 页面标题语言字符串键
     * @param string $pageurl 页面 URL 路径
     * @param array $urlparams URL 参数
     * @return array 包含 page, starttime, endtime, starttime_display, endtime_display 的数组
     */
    public static function init_stats_page(string $pagetitle, string $pageurl, array $urlparams = []): array {
        global $PAGE, $OUTPUT;

        /** @var \context $context */
        $context = \context_system::instance();
        // 注意：权限检查由调用方执行。
        // 原因：
        // 1. 避免重复检查（stats.php 等页面在 init_stats_page 后又 require_capability）
        // 2. quiz_stats.php 等页面可能需要在 COURSE 或 MODULE 上下文检查，
        //    而非强制固定为 SYSTEM 上下文。全局统计页面（stats/free_stats）
        //    会自行在 SYSTEM 上下文调用 require_capability('...:viewstats', $context)
        // 3. 权限 contextlevel 已改为 CONTEXT_COURSE（见 access.php），
        //    在 SYSTEM 上下文检查时仅有 manager 角色能通过（含全校数据，合理）

        $PAGE->set_context($context);
        $PAGE->set_url($pageurl, $urlparams);
        $PAGE->set_title(get_string($pagetitle, 'local_ai_programming_assistant'));
        $PAGE->set_heading(get_string($pagetitle, 'local_ai_programming_assistant'));

        $starttime = optional_param('starttime', '', PARAM_TEXT);
        $endtime = optional_param('endtime', '', PARAM_TEXT);

        $range = self::resolve_date_range($starttime, $endtime);

        return [
            'page' => $PAGE,
            'output' => $OUTPUT,
            'context' => $context,
            'starttime' => $range['starttimestamp'],
            'endtime' => $range['endtimestamp'],
            'starttime_display' => $range['starttime_display'],
            'endtime_display' => $range['endtime_display'],
        ];
    }

    /**
     * 构建导航标签 URL（消除 6 个统计/列表页面的重复 nav URL 构建代码）
     *
     * @param string $starttime_display 起始日期显示字符串
     * @param string $endtime_display 结束日期显示字符串
     * @return array 包含 nav_stats_url / nav_free_stats_url / nav_conversation_list_url 的数组
     */
    public static function build_nav_urls(string $starttime_display, string $endtime_display): array {
        $params = [
            'starttime' => $starttime_display,
            'endtime' => $endtime_display,
        ];
        return [
            'nav_overview_url' => (new \moodle_url('/local/ai_programming_assistant/overview.php', $params))->out(),
            'nav_stats_url' => (new \moodle_url('/local/ai_programming_assistant/stats.php', $params))->out(),
            'nav_free_stats_url' => (new \moodle_url('/local/ai_programming_assistant/free_stats.php', $params))->out(),
            'nav_conversation_list_url' => (new \moodle_url('/local/ai_programming_assistant/conversation_list.php', $params))->out(),
            'nav_asked_questions_url' => (new \moodle_url('/local/ai_programming_assistant/asked_questions.php', $params))->out(),
        ];
    }

    /**
     * 获取统计页面的通用模板上下文
     *
     * @param array $pageinfo init_stats_page() 的返回值
     * @param array $datepresets 日期预设
     * @return array 可合并到模板上下文的通用数据
     */
    public static function get_stats_page_base_context(array $pageinfo, array $datepresets = []): array {
        return [
            'starttime_display' => $pageinfo['starttime_display'],
            'endtime_display' => $pageinfo['endtime_display'],
            'datepresets' => $datepresets,
        ];
    }
}