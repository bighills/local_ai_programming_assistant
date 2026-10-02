<?php
/**
 * AI 编程助手 - 有题号聊天详情页
 *
 * 显示某学生在有题号（主日志）下的具体对话记录，支持时间范围筛选和翻页。
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */

require_once(__DIR__ . '/../../config.php');

defined('MOODLE_INTERNAL') || die();

$userid = required_param('userid', PARAM_INT);
$starttime = optional_param('starttime', '', PARAM_TEXT);
$endtime = optional_param('endtime', '', PARAM_TEXT);
$quizid = optional_param('quizid', 0, PARAM_INT);
$backurl = optional_param('backurl', '', PARAM_URL);
$page = optional_param('page', 1, PARAM_INT);
$page = max(1, $page);
// 验证 backurl 必须为站内 URL，防止开放重定向
if (!empty($backurl)) {
    $parsed = parse_url($backurl);
    if (!$parsed || !empty($parsed['host'])) {
        $backurl = '';
    }
}
$perpage = 10;

$context = context_system::instance();
require_login();
require_capability('local/ai_programming_assistant:viewstats', $context);

$PAGE->set_url('/local/ai_programming_assistant/chat_detail.php', [
    'userid' => $userid,
    'starttime' => $starttime,
    'endtime' => $endtime,
    'quizid' => $quizid,
]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('chat_detail_page_title', 'local_ai_programming_assistant'));
$PAGE->set_heading(get_string('chat_detail_page_title', 'local_ai_programming_assistant'));
$PAGE->set_pagelayout('standard');

$statsservice = \local_ai_programming_assistant\stats_service::get_instance();

$daterange = \local_ai_programming_assistant\helper::resolve_date_range($starttime, $endtime);
$starttimestamp = $daterange['starttimestamp'];
$endtimestamp = $daterange['endtimestamp'];
$starttime_display = $daterange['starttime_display'];
$endtime_display = $daterange['endtime_display'];

$PAGE->navbar->add(
    get_string('stats_page_title', 'local_ai_programming_assistant'),
    new moodle_url('/local/ai_programming_assistant/stats.php', [
        'starttime' => $starttime_display,
        'endtime' => $endtime_display,
    ])
);
$PAGE->navbar->add(get_string('chat_detail_page_title', 'local_ai_programming_assistant'));

// 先统计总数，再基于 clamp 后的页码计算 offset（防止构造超大页码导致
// offset 溢出/空页显示异常，与 stats.php 行为一致）
$totalrecords = $statsservice->count_chat($userid, $starttimestamp, $endtimestamp, $quizid > 0 ? $quizid : null);
$totalpages = max(1, (int)ceil($totalrecords / $perpage));
if ($page > $totalpages) {
    $page = $totalpages;
}
$offset = ($page - 1) * $perpage;

$chatrecords = $statsservice->get_chat_detail(
    $userid, $starttimestamp, $endtimestamp,
    $quizid > 0 ? $quizid : null, $perpage, $offset
);

// 获取学生信息
$userinfo = \local_ai_programming_assistant\helper::get_user_display_info($userid);
$studentname = $userinfo['name'];
$studentusername = $userinfo['username'];

// 获取测验名称（如果有）
$quizname = '';
if ($quizid > 0) {
    $cm = get_coursemodule_from_instance('quiz', $quizid);
    if ($cm) {
        $quizname = $cm->name;
    }
}

// 如果未指定 backurl 则默认回 stats.php
if (empty($backurl)) {
    $backtourl = new moodle_url('/local/ai_programming_assistant/stats.php', [
        'starttime' => $starttime_display,
        'endtime' => $endtime_display,
        'quizid' => $quizid,
    ]);
} else {
    // 使用 moodle_url 校验 backurl，防止开放重定向
    $backtourl = new moodle_url($backurl);
}

$templatecontext = [
    'userid' => $userid,
    'studentname' => $studentname,
    'studentusername' => $studentusername,
    'quizid' => $quizid,
    'quizname' => $quizname,
    'hasquiz' => $quizid > 0 && !empty($quizname),
    'starttime_display' => $starttime_display,
    'endtime_display' => $endtime_display,
    'chatrecords' => $chatrecords,
    'hasdata' => !empty($chatrecords),
    'totalrecords' => $totalrecords,
    'wwwroot' => $CFG->wwwroot,
    'backtourl' => $backtourl,
];

$templatecontext = array_merge($templatecontext, \local_ai_programming_assistant\helper::build_pagination_context($page, $perpage, $totalrecords));

\local_ai_programming_assistant\helper::init_code_highlight($PAGE);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_programming_assistant/chat_detail', $templatecontext);
echo $OUTPUT->footer();