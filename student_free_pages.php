<?php
/**
 * AI 编程助手 - 学生自由模式页面列表页
 *
 * 显示某学生在自由模式下使用过 AI 反馈的页面列表，点击页面类型可查看具体对话记录。
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */

require_once(__DIR__ . '/../../config.php');

defined('MOODLE_INTERNAL') || die();

$userid = required_param('userid', PARAM_INT);
$starttime = optional_param('starttime', '', PARAM_TEXT);
$endtime = optional_param('endtime', '', PARAM_TEXT);
$export = optional_param('export', '', PARAM_ALPHA);
$page = optional_param('page', 1, PARAM_INT);
$page = max(1, $page);
$perpage = 10;

$context = context_system::instance();
require_login();
require_capability('local/ai_programming_assistant:viewstats', $context);

$PAGE->set_url('/local/ai_programming_assistant/student_free_pages.php', [
    'userid' => $userid,
    'starttime' => $starttime,
    'endtime' => $endtime,
]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('student_free_pages_page_title', 'local_ai_programming_assistant'));

$statsservice = \local_ai_programming_assistant\stats_service::get_instance();

$daterange = \local_ai_programming_assistant\helper::resolve_date_range($starttime, $endtime);
$starttimestamp = $daterange['starttimestamp'];
$endtimestamp = $daterange['endtimestamp'];
$starttime_display = $daterange['starttime_display'];
$endtime_display = $daterange['endtime_display'];

// 必须先获取学生信息，后续页面标题和面包屑需要用到
$userinfo = \local_ai_programming_assistant\helper::get_user_display_info($userid);
$studentname = $userinfo['name'];
$studentusername = $userinfo['username'];

$pageheading = $studentname ? $studentname . ' - ' . get_string('student_free_pages_page_title', 'local_ai_programming_assistant')
    : get_string('student_free_pages_page_title', 'local_ai_programming_assistant');
$PAGE->set_heading($pageheading);
$PAGE->set_pagelayout('standard');
$PAGE->navbar->add(
    get_string('free_stats_page_title', 'local_ai_programming_assistant'),
    new moodle_url('/local/ai_programming_assistant/free_stats.php')
);
$PAGE->navbar->add($studentname ?: get_string('student_free_pages_page_title', 'local_ai_programming_assistant'));

// 分页参数
$offset = ($page - 1) * $perpage;

// 获取页面列表（分页）
$pages = $statsservice->get_student_free_pages($userid, $starttimestamp, $endtimestamp, $perpage, $offset);
$totalpages = $statsservice->count_student_free_pages($userid, $starttimestamp, $endtimestamp);

// 为每条记录构建页面名称、页面链接和聊天详情链接
foreach ($pages as &$p) {
    // 解析 contextid 获取页面名称和链接
    $pagename = '';
    $pageurl = null;
    if ($p['contextid'] > 0) {
        $info = \local_ai_programming_assistant\helper::resolve_context_info($p['contextid'], $p['pagetype']);
        $pagename = $info['name'];
        $pageurl = $info['url'];
    } else {
        $pagename = $p['pagetype'];
    }
    $p['pagename'] = $pagename;
    $p['pageurl'] = $pageurl;

    $p['detailurl'] = new moodle_url('/local/ai_programming_assistant/free_chat_detail.php', [
        'userid' => $userid,
        'starttime' => $starttime_display,
        'endtime' => $endtime_display,
        'contextid' => $p['contextid'],
        'fromstudentpages' => 1,
    ]);
}
unset($p);

// 总计：交互次数必须取全量统计，不能对当前分页结果 array_sum
// （分页结果仅含当前页 10 条记录，直接求和会导致徽章与 CSV 合计偏小）
$totalinteractions = $statsservice->get_student_free_pages_total_interactions($userid, $starttimestamp, $endtimestamp);

// CSV 导出
if ($export === 'csv') {
    // CSV 导出时获取所有记录（不限制分页）
    $allpages = $statsservice->get_student_free_pages($userid, $starttimestamp, $endtimestamp, 5000, 0);
    $output = \local_ai_programming_assistant\helper::export_csv_header('student_free_pages');

    \local_ai_programming_assistant\helper::csv_put($output, [$studentname . ' - ' . get_string('student_free_pages_page_title', 'local_ai_programming_assistant')]);
    \local_ai_programming_assistant\helper::csv_put($output, [
        get_string('student_free_pages_pageid', 'local_ai_programming_assistant'),
        get_string('student_free_pages_pagename', 'local_ai_programming_assistant'),
        get_string('student_free_pages_pagetype', 'local_ai_programming_assistant'),
        get_string('student_free_pages_course', 'local_ai_programming_assistant'),
        get_string('stats_total_requests', 'local_ai_programming_assistant'),
        get_string('stats_success', 'local_ai_programming_assistant'),
        get_string('student_questions_last_interaction', 'local_ai_programming_assistant'),
    ]);
    foreach ($allpages as &$p) {
        $pagename = '';
        if ($p['contextid'] > 0) {
            $info = \local_ai_programming_assistant\helper::resolve_context_info($p['contextid'], $p['pagetype']);
            $pagename = $info['name'];
        } else {
            $pagename = $p['pagetype'];
        }
        \local_ai_programming_assistant\helper::csv_put($output, [
            $p['contextid'],
            $pagename,
            $p['pagetype'],
            $p['coursename'],
            $p['interaction_count'],
            $p['successful_count'],
            userdate($p['last_interaction']),
        ]);
    }
    \local_ai_programming_assistant\helper::csv_put($output, []);
    \local_ai_programming_assistant\helper::csv_put($output, [
        get_string('student_free_pages_total', 'local_ai_programming_assistant') . ': ' . $totalpages,
        get_string('student_free_pages_interactions', 'local_ai_programming_assistant') . ': ' . $totalinteractions,
    ]);
    \local_ai_programming_assistant\helper::finish_csv_export($output);
}

$templatecontext = [
    'userid' => $userid,
    'studentname' => $studentname,
    'studentusername' => $studentusername,
    'starttime_display' => $starttime_display,
    'endtime_display' => $endtime_display,
    'pages' => $pages,
    'hasdata' => !empty($pages),
    // 页面数使用独立键名，避免被下方 build_pagination_context() 返回的
    // 'totalpages'（分页总页数）键覆盖。
    'totalpagecount' => $totalpages,
    'totalinteractions' => $totalinteractions,
    'backtourl' => new moodle_url('/local/ai_programming_assistant/free_stats.php', [
        'starttime' => $starttime_display,
        'endtime' => $endtime_display,
    ]),
];

$templatecontext = array_merge($templatecontext, \local_ai_programming_assistant\helper::build_pagination_context($page, $perpage, $totalpages));

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_programming_assistant/student_free_pages', $templatecontext);
echo $OUTPUT->footer();