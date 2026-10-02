<?php
/**
 * AI 编程助手 - 学生题目列表页
 *
 * 显示某学生使用过 AI 反馈的题目列表，点击题目可查看详细对话记录。
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
$page = optional_param('page', 1, PARAM_INT);
$page = max(1, $page);
$perpage = 10;

$context = context_system::instance();
require_login();
require_capability('local/ai_programming_assistant:viewstats', $context);

$PAGE->set_url('/local/ai_programming_assistant/student_questions.php', [
    'userid' => $userid,
    'starttime' => $starttime,
    'endtime' => $endtime,
    'quizid' => $quizid,
]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('student_questions_page_title', 'local_ai_programming_assistant'));
$PAGE->set_heading(get_string('student_questions_page_title', 'local_ai_programming_assistant'));
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
$PAGE->navbar->add(get_string('student_questions_page_title', 'local_ai_programming_assistant'));

// 获取学生信息
$userinfo = \local_ai_programming_assistant\helper::get_user_display_info($userid);
$studentname = $userinfo['name'];
$studentusername = $userinfo['username'];

// 先统计总数，再基于 clamp 后的页码计算 offset（防止构造超大页码导致
// offset 溢出/空页显示异常，与 stats.php 行为一致）
$totalquestions = $statsservice->count_student_questions(
    $userid, $starttimestamp, $endtimestamp,
    $quizid > 0 ? $quizid : null
);
$totalpages = max(1, (int)ceil($totalquestions / $perpage));
if ($page > $totalpages) {
    $page = $totalpages;
}
$offset = ($page - 1) * $perpage;

// 获取题目列表（分页）
$questions = $statsservice->get_student_questions(
    $userid, $starttimestamp, $endtimestamp,
    $quizid > 0 ? $quizid : null, $perpage, $offset
);

// 为每条题目构建详情页 URL
foreach ($questions as &$q) {
    $q['detailurl'] = new moodle_url('/local/ai_programming_assistant/question_detail.php', [
        'quizid' => $q['quizid'],
        'questionid' => $q['questionid'],
        'userid' => $userid,
        'starttime' => $starttime_display,
        'endtime' => $endtime_display,
    ]);
}
unset($q);

$templatecontext = [
    'userid' => $userid,
    'studentname' => $studentname,
    'studentusername' => $studentusername,
    'starttime_display' => $starttime_display,
    'endtime_display' => $endtime_display,
    'quizid' => $quizid,
    'questions' => $questions,
    'hasdata' => !empty($questions),
    'wwwroot' => $CFG->wwwroot,
    'totalquestions' => $totalquestions,
    'backtourl' => new moodle_url('/local/ai_programming_assistant/stats.php', [
        'starttime' => $starttime_display,
        'endtime' => $endtime_display,
        'quizid' => $quizid,
    ]),
];

$templatecontext = array_merge($templatecontext, \local_ai_programming_assistant\helper::build_pagination_context($page, $perpage, $totalquestions));

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_programming_assistant/student_questions', $templatecontext);
echo $OUTPUT->footer();