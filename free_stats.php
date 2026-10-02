<?php
/**
 * AI 编程助手 - 自由模式（无题号）AI 反馈统计页面
 *
 * 展示无试题号关联的自由对话统计数据，包括使用概览、模型分布和学生排行。
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */

require_once(__DIR__ . '/../../config.php');

defined('MOODLE_INTERNAL') || die();

require_login();

$pageinfo = \local_ai_programming_assistant\helper::init_stats_page(
    'free_stats_page_title',
    '/local/ai_programming_assistant/free_stats.php'
);
$context = $pageinfo['context'];
require_capability('local/ai_programming_assistant:viewstats', $context);
$starttime = $pageinfo['starttime'];
$endtime = $pageinfo['endtime'];
$starttime_display = $pageinfo['starttime_display'];
$endtime_display = $pageinfo['endtime_display'];
$PAGE->set_pagelayout('standard');

$studentsearch = optional_param('studentsearch', '', PARAM_RAW_TRIMMED);

$export = optional_param('export', '', PARAM_ALPHA);

// 学生列表分页和排序参数
$perpageoptions = [10, 20, 50, 100];
$perpage = optional_param('perpage', 10, PARAM_INT);
if (!in_array($perpage, $perpageoptions, true)) {
    $perpage = 10;
}
$page = optional_param('page', 1, PARAM_INT);
$page = max(1, $page);
$sort = optional_param('sort', 'last_timecreated', PARAM_ALPHA);
$order = optional_param('order', 'DESC', PARAM_ALPHA);
$order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
$offset = ($page - 1) * $perpage;

// 每日趋势分页参数
$dperpage = 10;
$dpage = optional_param('dpage', 1, PARAM_INT);
$dpage = max(1, $dpage);

$statsservice = \local_ai_programming_assistant\stats_service::get_instance();

// 构建重定向基础 URL（保留所有筛选参数）
$delete_redirect_params = [
    'starttime' => $starttime_display,
    'endtime' => $endtime_display,
    'studentsearch' => $studentsearch,
    'perpage' => $perpage,
    'page' => $page,
    'sort' => $sort,
    'order' => $order,
    'dpage' => $dpage,
];

// 处理记录删除
$action = optional_param('action', '', PARAM_ALPHA);
if ($action === 'delete' && confirm_sesskey()) {
    $recordids = required_param('recordids', PARAM_SEQUENCE);
    $ids = array_filter(array_map('intval', explode(',', $recordids)), function($id) { return $id > 0; });
    $deleted = 0;
    if (!empty($ids)) {
        // 自由模式只删除 questionid = 0 的会话
        $deleted = $statsservice->delete_conversations_by_userids(array_values($ids), true);
    }
    \core\notification::success(get_string('stats_record_delete_success', 'local_ai_programming_assistant', $deleted));
    redirect(new moodle_url('/local/ai_programming_assistant/free_stats.php', $delete_redirect_params));
}

$overallstats = $statsservice->get_free_log_overall_stats($starttime, $endtime);
// 格式化页面类型名称为可读形式
if (!empty($overallstats['pagetype_usage'])) {
    foreach ($overallstats['pagetype_usage'] as &$pt) {
        $pt['key'] = \local_ai_programming_assistant\helper::format_pagetype($pt['key']);
    }
    unset($pt);
}
$studentstats = $statsservice->get_free_log_student_stats($starttime, $endtime, $perpage, $studentsearch, $offset, $sort, $order);
$totalstudents = $statsservice->count_free_log_student_stats($starttime, $endtime, $studentsearch);
$dailystatsall = $statsservice->get_free_log_daily_stats($starttime, $endtime);
$dailystatstotal = count($dailystatsall);
$dailystats = array_slice($dailystatsall, ($dpage - 1) * $dperpage, $dperpage);
$dtotalpages = max(1, (int)ceil($dailystatstotal / $dperpage));

if ($export === 'csv') {
    // For CSV export, fetch all records without pagination
    $allstudentstats = $statsservice->get_free_log_student_stats($starttime, $endtime, 5000, $studentsearch, 0, $sort, $order);

    $output = \local_ai_programming_assistant\helper::export_csv_header('free_stats');

    \local_ai_programming_assistant\helper::csv_put($output, [get_string('free_stats_page_title', 'local_ai_programming_assistant')]);
    \local_ai_programming_assistant\helper::csv_put($output, [
        get_string('stats_total_requests', 'local_ai_programming_assistant'),
        get_string('stats_success_rate', 'local_ai_programming_assistant'),
        get_string('stats_avg_responsetime', 'local_ai_programming_assistant') . ' (ms)',
        get_string('stats_unique_users', 'local_ai_programming_assistant'),
        get_string('free_stats_pages', 'local_ai_programming_assistant'),
    ]);
    \local_ai_programming_assistant\helper::csv_put($output, [
        $overallstats['total_requests'],
        $overallstats['success_rate'] . '%',
        $overallstats['avg_responsetime'],
        $overallstats['unique_users'],
        $overallstats['unique_pages'],
    ]);
    \local_ai_programming_assistant\helper::csv_put($output, []);

    if (!empty($overallstats['model_usage'])) {
        \local_ai_programming_assistant\helper::csv_put($output, [get_string('stats_model_usage', 'local_ai_programming_assistant')]);
        \local_ai_programming_assistant\helper::csv_put($output, [
            get_string('stats_model', 'local_ai_programming_assistant'),
            get_string('stats_count', 'local_ai_programming_assistant'),
        ]);
        foreach ($overallstats['model_usage'] as $m) {
            \local_ai_programming_assistant\helper::csv_put($output, [$m['key'], $m['value']]);
        }
        \local_ai_programming_assistant\helper::csv_put($output, []);
    }

    if (!empty($overallstats['pagetype_usage'])) {
        \local_ai_programming_assistant\helper::csv_put($output, [get_string('free_stats_pagetype_distribution', 'local_ai_programming_assistant')]);
        \local_ai_programming_assistant\helper::csv_put($output, [
            get_string('free_stats_pagetype', 'local_ai_programming_assistant'),
            get_string('stats_count', 'local_ai_programming_assistant'),
        ]);
        foreach ($overallstats['pagetype_usage'] as $p) {
            \local_ai_programming_assistant\helper::csv_put($output, [$p['key'], $p['value']]);
        }
        \local_ai_programming_assistant\helper::csv_put($output, []);
    }

    // 每日趋势导出
    if (!empty($dailystatsall)) {
        \local_ai_programming_assistant\helper::csv_put($output, [get_string('stats_daily_trend', 'local_ai_programming_assistant')]);
        \local_ai_programming_assistant\helper::csv_put($output, [
            get_string('stats_date', 'local_ai_programming_assistant'),
            get_string('stats_total_requests', 'local_ai_programming_assistant'),
            get_string('stats_success', 'local_ai_programming_assistant'),
            get_string('stats_failed', 'local_ai_programming_assistant'),
            get_string('stats_success_rate', 'local_ai_programming_assistant'),
            get_string('stats_avg_responsetime', 'local_ai_programming_assistant') . ' (ms)',
        ]);
        foreach ($dailystatsall as $d) {
            \local_ai_programming_assistant\helper::csv_put($output, [$d['date'], $d['total'], $d['successful'],
                $d['failed'], $d['success_rate'] . '%', $d['avg_responsetime']]);
        }
        \local_ai_programming_assistant\helper::csv_put($output, []);
    }

    \local_ai_programming_assistant\helper::csv_put($output, [get_string('stats_student_usage', 'local_ai_programming_assistant')]);
    \local_ai_programming_assistant\helper::csv_put($output, [
        get_string('stats_user', 'local_ai_programming_assistant'),
        get_string('stats_username', 'local_ai_programming_assistant'),
        get_string('free_stats_pages', 'local_ai_programming_assistant'),
        get_string('stats_total_requests', 'local_ai_programming_assistant'),
        get_string('stats_last_interaction', 'local_ai_programming_assistant'),
    ]);
    foreach ($allstudentstats as $s) {
        \local_ai_programming_assistant\helper::csv_put($output, [$s['fullname'] ?? '', $s['username'] ?? '',
            $s['unique_pages'], $s['total_requests'], $s['last_timecreated_str'] ?? '']);
    }

    \local_ai_programming_assistant\helper::finish_csv_export($output);
}

$studenttotals = null;
if (!empty($studentstats)) {
    $studenttotals = \local_ai_programming_assistant\helper::compute_stats_totals($studentstats);
}

$hasdata = !empty($overallstats['total_requests']) && $overallstats['total_requests'] > 0;

// 构建基础 URL（保留所有筛选参数）
$baseurl = new \moodle_url($PAGE->url, [
    'starttime' => $starttime_display,
    'endtime' => $endtime_display,
    'studentsearch' => $studentsearch,
    'dpage' => $dpage,
    'perpage' => $perpage,
]);
$baseurl->param('sort', $sort);
$baseurl->param('order', $order);

// 构建排序 URL（切换排序方向或字段时重置到第1页）
$sortfields = ['fullname', 'unique_pages', 'total_requests', 'last_timecreated'];
$sorturls = [];
foreach ($sortfields as $field) {
    $neworder = ($field === $sort && $order === 'DESC') ? 'ASC' : 'DESC';
    $url = clone $baseurl;
    $url->param('sort', $field);
    $url->param('order', $neworder);
    $url->param('page', 1);
    $sorturls[$field] = $url->out();
}

// 当前排序方向指示
$sortindicators = [];
foreach ($sortfields as $field) {
    $sortindicators[$field . '_asc'] = ($sort === $field && $order === 'ASC');
    $sortindicators[$field . '_desc'] = ($sort === $field && $order === 'DESC');
}

$totalpages = max(1, (int)ceil($totalstudents / $perpage));
// 页码 clamp：防止构造超大页码导致 offset 溢出/空页显示异常（与 stats.php 行为一致）
if ($page > $totalpages) {
    $page = $totalpages;
    $offset = ($page - 1) * $perpage;
}

// 构建分页数据
$pagination = [];
$maxpagelinks = 10;
$pagestart = max(1, $page - (int)floor($maxpagelinks / 2));
$pageend = min($totalpages, $pagestart + $maxpagelinks - 1);
if ($pageend - $pagestart + 1 < $maxpagelinks) {
    $pagestart = max(1, $pageend - $maxpagelinks + 1);
}
for ($i = $pagestart; $i <= $pageend; $i++) {
    $url = clone $baseurl;
    $url->param('page', $i);
    $pagination[] = [
        'page' => $i,
        'url' => $url->out(),
        'is_active' => $i == $page,
    ];
}

// 上一页/下一页
$previouspageurl = null;
$nextpageurl = null;
if ($page > 1) {
    $url = clone $baseurl;
    $url->param('page', $page - 1);
    $previouspageurl = $url->out();
}
if ($page < $totalpages) {
    $url = clone $baseurl;
    $url->param('page', $page + 1);
    $nextpageurl = $url->out();
}

// 当前页显示范围
$pagestartitem = ($page - 1) * $perpage + 1;
$pageenditem = min($page * $perpage, $totalstudents);

// 每日趋势分页
$dpagination = [];
$dpagestart = max(1, $dpage - 5);
$dpageend = min($dtotalpages, $dpagestart + 9);
if ($dpageend - $dpagestart + 1 < 10) {
    $dpagestart = max(1, $dpageend - 9);
}
for ($i = $dpagestart; $i <= $dpageend; $i++) {
    $url = clone $baseurl;
    $url->param('dpage', $i);
    $url->param('page', $page);
    $url->param('sort', $sort);
    $url->param('order', $order);
    $dpagination[] = [
        'page' => $i,
        'url' => $url->out(),
        'is_active' => $i == $dpage,
    ];
}

$dprevurl = null;
$dnexturl = null;
if ($dpage > 1) {
    $url = clone $baseurl;
    $url->param('dpage', $dpage - 1);
    $url->param('page', $page);
    $url->param('sort', $sort);
    $url->param('order', $order);
    $dprevurl = $url->out();
}
if ($dpage < $dtotalpages) {
    $url = clone $baseurl;
    $url->param('dpage', $dpage + 1);
    $url->param('page', $page);
    $url->param('sort', $sort);
    $url->param('order', $order);
    $dnexturl = $url->out();
}

$dpagestartitem = ($dpage - 1) * $dperpage + 1;
$dpageenditem = min($dpage * $dperpage, $dailystatstotal);

$templatecontext = [
    'stats' => $overallstats,
    'wwwroot' => $CFG->wwwroot,
    'studentstats' => $studentstats,
    'studentcount' => $totalstudents,
    'studenttotals' => $studenttotals,
    'has_studenttotals' => !empty($studenttotals),
    'dailystats' => $dailystats,
    'has_dailystats' => !empty($dailystatsall),
    'has_export' => $hasdata,

    // 导航标签
    'nav_free_stats' => true,

    'recentfreechatsurl' => (new moodle_url('/local/ai_programming_assistant/recent_free_chats.php', [
        'starttime' => $starttime_display,
        'endtime' => $endtime_display,
    ]))->out(),

    'starttime' => $starttime,
    'endtime' => $endtime,
    'starttime_display' => $starttime_display,
    'endtime_display' => $endtime_display,
    'presets' => \local_ai_programming_assistant\helper::get_date_presets($starttime, $endtime),
    'studentsearch' => $studentsearch,
    // 学生分页数据
    'page' => $page,
    'perpage' => $perpage,
    'perpageoptions' => array_map(function($opt) use ($perpage, $baseurl) {
        $url = clone $baseurl;
        $url->param('perpage', $opt);
        $url->param('page', 1);
        return ['value' => $opt, 'selected' => $opt === $perpage, 'url' => $url->out()];
    }, $perpageoptions),
    'totalpages' => $totalpages,
    'has_pagination' => $totalpages > 1,
    'pagination' => $pagination,
    'previouspageurl' => $previouspageurl,
    'nextpageurl' => $nextpageurl,
    'has_previous' => $page > 1,
    'has_next' => $page < $totalpages,
    'pagestartitem' => $pagestartitem,
    'pageenditem' => $pageenditem,
    // 学生排序数据
    'sort' => $sort,
    'order' => strtolower($order),
    'sorturls' => $sorturls,
    // 每日趋势分页数据
    'dpage' => $dpage,
    'dperpage' => $dperpage,
    'dtotalpages' => $dtotalpages,
    'dhas_pagination' => $dtotalpages > 1,
    'dpagination' => $dpagination,
    'dprevurl' => $dprevurl,
    'dnexturl' => $dnexturl,
    'dhas_previous' => $dpage > 1,
    'dhas_next' => $dpage < $dtotalpages,
    'dpagestartitem' => $dpagestartitem,
    'dpageenditem' => $dpageenditem,
    'dailytotalcount' => $dailystatstotal,

    // 记录删除
    'delete_action_url' => (new moodle_url('/local/ai_programming_assistant/free_stats.php', $delete_redirect_params))->out(),
    'confirm_delete_msg_json' => json_encode(get_string('stats_record_confirm_delete', 'local_ai_programming_assistant', '__COUNT__')),
] + $sortindicators + \local_ai_programming_assistant\helper::build_nav_urls($starttime_display, $endtime_display);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_programming_assistant/free_stats_page', $templatecontext);
echo $OUTPUT->footer();