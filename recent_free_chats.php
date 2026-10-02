<?php
/**
 * AI 编程助手 - 最近自由对话记录页（一级：按页面+用户汇总）
 *
 * 展示所有学生最近提交的自由对话记录，按页面和用户分组汇总，不按学生分类。
 * 支持时间范围筛选、学生姓名搜索、分页和 CSV 导出。
 * 点击记录可跳转到二级页面查看具体对话内容。
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */

require_once(__DIR__ . '/../../config.php');

defined('MOODLE_INTERNAL') || die();

require_login();

$syscontext = context_system::instance();
require_capability('local/ai_programming_assistant:viewstats', $syscontext);

$pageinfo = \local_ai_programming_assistant\helper::init_stats_page(
    'recent_free_chats_page_title',
    '/local/ai_programming_assistant/recent_free_chats.php'
);
$starttime = $pageinfo['starttime'];
$endtime = $pageinfo['endtime'];
$starttime_display = $pageinfo['starttime_display'];
$endtime_display = $pageinfo['endtime_display'];
$PAGE->set_pagelayout('standard');

$studentsearch = optional_param('studentsearch', '', PARAM_RAW_TRIMMED);
$export = optional_param('export', '', PARAM_ALPHA);

$perpageoptions = [10, 20, 50, 100];
$perpage = optional_param('perpage', 10, PARAM_INT);
if (!in_array($perpage, $perpageoptions, true)) {
    $perpage = 10;
}
$page = optional_param('page', 1, PARAM_INT);
$page = max(1, $page);

$statsservice = \local_ai_programming_assistant\stats_service::get_instance();

// 先统计总数，再基于 clamp 后的页码计算 offset（防止构造超大页码导致
// offset 溢出/空页显示异常，与 stats.php 行为一致）
$totalrecords = $statsservice->count_recent_free_chats_grouped(
    $starttime, $endtime, $studentsearch
);
$totalpages = max(1, (int)ceil($totalrecords / $perpage));
if ($page > $totalpages) {
    $page = $totalpages;
}
$offset = ($page - 1) * $perpage;

$records = $statsservice->get_recent_free_chats_grouped(
    $starttime, $endtime, $perpage, $offset, $studentsearch
);

foreach ($records as &$r) {
    $cid = (int)($r['contextid'] ?? 0);
    if ($cid > 0) {
        $info = \local_ai_programming_assistant\helper::resolve_context_info($cid, $r['pagetype'] ?? '');
        $r['pagename'] = $info['name'];
        $r['pageurl'] = $info['url'];
    } else {
        $r['pagename'] = $r['pagetype'] ?? 'unknown';
        $r['pageurl'] = null;
    }

    $r['detailurl'] = new moodle_url('/local/ai_programming_assistant/free_chat_detail.php', [
        'userid' => $r['userid'],
        'starttime' => $starttime_display,
        'endtime' => $endtime_display,
        'contextid' => $r['contextid'],
    ]);

    // 会话列表链接（按用户+页面+自由模式筛选）
    $r['conversationlisturl'] = (new moodle_url('/local/ai_programming_assistant/conversation_list.php', [
        'userid' => $r['userid'],
        'actiontype' => 'free',
        'contextid' => $r['contextid'],
        'starttime' => $starttime_display,
        'endtime' => $endtime_display,
    ]))->out();
}
unset($r);

if ($export === 'csv') {
    $allrecords = $statsservice->get_recent_free_chats_grouped(
        $starttime, $endtime, 5000, 0, $studentsearch
    );

    foreach ($allrecords as &$r) {
        $cid = (int)($r['contextid'] ?? 0);
        if ($cid > 0) {
            $info = \local_ai_programming_assistant\helper::resolve_context_info($cid, $r['pagetype'] ?? '');
            $r['pagename'] = $info['name'];
        } else {
            $r['pagename'] = $r['pagetype'] ?? 'unknown';
        }
        $r['failed_count'] = $r['interaction_count'] - $r['successful_count'];
    }
    unset($r);

    $output = \local_ai_programming_assistant\helper::export_csv_header('recent_free_chats_grouped');

    \local_ai_programming_assistant\helper::csv_put($output, [get_string('recent_free_chats_page_title', 'local_ai_programming_assistant')]);
    \local_ai_programming_assistant\helper::csv_put($output, [
        get_string('stats_time_from', 'local_ai_programming_assistant') . ': ' . $starttime_display,
        get_string('stats_time_to', 'local_ai_programming_assistant') . ': ' . $endtime_display,
    ]);
    if (!empty($studentsearch)) {
        \local_ai_programming_assistant\helper::csv_put($output, [get_string('recent_free_chats_filter_student', 'local_ai_programming_assistant') . ': ' . $studentsearch]);
    }
    \local_ai_programming_assistant\helper::csv_put($output, []);

    \local_ai_programming_assistant\helper::csv_put($output, [
        get_string('student_free_pages_pageid', 'local_ai_programming_assistant'),
        get_string('student_free_pages_pagename', 'local_ai_programming_assistant'),
        get_string('student_free_pages_pagetype', 'local_ai_programming_assistant'),
        get_string('student_free_pages_course', 'local_ai_programming_assistant'),
        get_string('stats_user', 'local_ai_programming_assistant'),
        get_string('stats_username', 'local_ai_programming_assistant'),
        get_string('stats_total_requests', 'local_ai_programming_assistant'),
        get_string('student_questions_last_interaction', 'local_ai_programming_assistant'),
    ]);

    foreach ($allrecords as $r) {
        \local_ai_programming_assistant\helper::csv_put($output, [
            $r['contextid'],
            $r['pagename'],
            $r['pagetype'],
            $r['coursename'],
            $r['fullname'],
            $r['username'],
            $r['interaction_count'],
            $r['last_interaction_str'],
        ]);
    }

    \local_ai_programming_assistant\helper::csv_put($output, []);
    \local_ai_programming_assistant\helper::csv_put($output, [
        get_string('recent_free_chats_total', 'local_ai_programming_assistant') . ': ' . $totalrecords,
    ]);

    \local_ai_programming_assistant\helper::finish_csv_export($output);
}

$perpageoptionlist = [];
foreach ($perpageoptions as $opt) {
    $perpageoptionlist[] = ['value' => $opt, 'selected' => $opt === $perpage];
}

$templatecontext = [
    'starttime_display' => $starttime_display,
    'endtime_display' => $endtime_display,
    'studentsearch' => $studentsearch,
    'perpage' => $perpage,
    'perpageoptions' => $perpageoptionlist,
    'records' => $records,
    'hasdata' => !empty($records),
    'totalrecords' => $totalrecords,
    'backtostatsurl' => new moodle_url('/local/ai_programming_assistant/free_stats.php', [
        'starttime' => $starttime_display,
        'endtime' => $endtime_display,
    ]),
    'backtofreestatsurl' => new moodle_url('/local/ai_programming_assistant/free_stats.php', [
        'starttime' => $starttime_display,
        'endtime' => $endtime_display,
    ]),
    // 导航标签
    'nav_free_stats' => true,
    'presets' => \local_ai_programming_assistant\helper::get_date_presets($starttime, $endtime),
];

$templatecontext = array_merge(
    $templatecontext,
    \local_ai_programming_assistant\helper::build_pagination_context($page, $perpage, $totalrecords),
    \local_ai_programming_assistant\helper::build_nav_urls($starttime_display, $endtime_display)
);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_programming_assistant/recent_free_chats', $templatecontext);
echo $OUTPUT->footer();