<?php
/**
 * AI 编程助手 - 自由模式聊天详情页
 *
 * 显示某学生在自由模式下的具体对话记录，支持时间范围筛选和翻页。
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */

require_once(__DIR__ . '/../../config.php');

defined('MOODLE_INTERNAL') || die();

$userid = required_param('userid', PARAM_INT);
$starttime = optional_param('starttime', '', PARAM_TEXT);
$endtime = optional_param('endtime', '', PARAM_TEXT);
$page = optional_param('page', 1, PARAM_INT);
$fromstudentpages = optional_param('fromstudentpages', 0, PARAM_INT);
$contextid = optional_param('contextid', 0, PARAM_INT);
$perpage = 10;

$context = context_system::instance();
require_login();
require_capability('local/ai_programming_assistant:viewstats', $context);

// 获取学生信息（需在页面标题和面包屑设置之前）
$userinfo = \local_ai_programming_assistant\helper::get_user_display_info($userid);
$studentname = $userinfo['name'];
$studentusername = $userinfo['username'];

$PAGE->set_url('/local/ai_programming_assistant/free_chat_detail.php', [
    'userid' => $userid,
    'starttime' => $starttime,
    'endtime' => $endtime,
    'contextid' => $contextid,
]);
$PAGE->set_context($context);
$chatpagetitle = get_string('free_chat_detail_page_title', 'local_ai_programming_assistant');
$chatpageheading = $studentname
    ? $studentname . ' - ' . $chatpagetitle
    : $chatpagetitle;
$PAGE->set_title($chatpagetitle);
$PAGE->set_heading($chatpageheading);
$PAGE->set_pagelayout('standard');
$PAGE->navbar->add(
    get_string('free_stats_page_title', 'local_ai_programming_assistant'),
    new moodle_url('/local/ai_programming_assistant/free_stats.php')
);
if ($fromstudentpages) {
    $PAGE->navbar->add(
        get_string('student_free_pages_title', 'local_ai_programming_assistant'),
        new moodle_url('/local/ai_programming_assistant/student_free_pages.php', [
            'userid' => $userid,
            'starttime' => $starttime,
            'endtime' => $endtime,
        ])
    );
}
$PAGE->navbar->add($studentname ? $studentname . ' - ' . $chatpagetitle : $chatpagetitle);

// 当按 contextid 过滤时，解析页面名称用于显示
$pagename = '';
if ($contextid > 0) {
    $pagename = \local_ai_programming_assistant\helper::resolve_context_name($contextid);
    $PAGE->navbar->add($pagename);
}

$statsservice = \local_ai_programming_assistant\stats_service::get_instance();

$daterange = \local_ai_programming_assistant\helper::resolve_date_range($starttime, $endtime);
$starttimestamp = $daterange['starttimestamp'];
$endtimestamp = $daterange['endtimestamp'];
$starttime_display = $daterange['starttime_display'];
$endtime_display = $daterange['endtime_display'];

// 先统计总数，再基于 clamp 后的页码计算 offset（与 stats.php 行为一致）。
// 原页面在查询前单独做 max(1, $page) 下界保护，此处统一用 min(max()) 同时钳制上下界。
$totalrecords = $statsservice->count_free_log_chat($userid, $starttimestamp, $endtimestamp, $contextid);
$totalpages = max(1, (int)ceil($totalrecords / $perpage));
$page = min(max(1, $page), $totalpages);
$offset = ($page - 1) * $perpage;

$chatrecords = $statsservice->get_free_log_chat_detail(
    $userid, $starttimestamp, $endtimestamp, $perpage, $offset, $contextid
);

// 为每条记录添加页面过滤链接和页面名称
foreach ($chatrecords as &$record) {
    $cid = (int)($record['contextid'] ?? 0);
    if ($cid > 0) {
        $record['pagefilterurl'] = new moodle_url('/local/ai_programming_assistant/free_chat_detail.php', [
            'userid' => $userid,
            'starttime' => $starttime_display,
            'endtime' => $endtime_display,
            'contextid' => $cid,
        ]);
        $record['page_contextname'] = \local_ai_programming_assistant\helper::resolve_context_name(
            $cid, $record['pagetype'] ?? ''
        );
    } else {
        $record['pagefilterurl'] = null;
        $record['page_contextname'] = '';
    }
}
unset($record);

$templatecontext = [
    'userid' => $userid,
    'studentname' => $studentname,
    'studentusername' => $studentusername,
    'starttime_display' => $starttime_display,
    'endtime_display' => $endtime_display,
    'chatrecords' => $chatrecords,
    'hasdata' => !empty($chatrecords),
    'totalrecords' => $totalrecords,
    'wwwroot' => $CFG->wwwroot,
    'backtofreestatsurl' => new moodle_url('/local/ai_programming_assistant/free_stats.php', [
        'starttime' => $starttime_display,
        'endtime' => $endtime_display,
    ]),
    'fromstudentpages' => $fromstudentpages,
    'contextid' => $contextid,
    'pagename' => $pagename,
    'clearfilterurl' => $contextid > 0 ? new moodle_url('/local/ai_programming_assistant/free_chat_detail.php', [
        'userid' => $userid,
        'starttime' => $starttime_display,
        'endtime' => $endtime_display,
        'page' => $page,
    ]) : null,
    'backtostudentpagesurl' => $fromstudentpages ? new moodle_url('/local/ai_programming_assistant/student_free_pages.php', [
        'userid' => $userid,
        'starttime' => $starttime_display,
        'endtime' => $endtime_display,
    ]) : null,
];

$templatecontext = array_merge($templatecontext, \local_ai_programming_assistant\helper::build_pagination_context($page, $perpage, $totalrecords));

\local_ai_programming_assistant\helper::init_code_highlight($PAGE);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_programming_assistant/free_chat_detail', $templatecontext);
echo $OUTPUT->footer();