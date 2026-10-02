<?php
/**
 * AI 编程助手 - 学生提问记录页面（一级页面）
 *
 * 统计学生向 AI 提问的原始问题列表，支持四种查看维度：
 *  1) 某道题学生提问的问题（questionid）
 *  2) 某位同学问了哪些问题（userid）
 *  3) 某位同学对某道题提了哪些问题（userid + questionid）
 *  4) 某位同学对某道题某种策略模式下提问的问题（userid + questionid + actiontype）
 *
 * 支持时间范围、策略模式、测验、课程过滤，学生姓名/用户名搜索，
 * 分页查看以及 CSV 全量导出。
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */

require_once(__DIR__ . '/../../config.php');

defined('MOODLE_INTERNAL') || die();

require_login();

$syscontext = context_system::instance();
require_capability('local/ai_programming_assistant:viewstats', $syscontext);

$questionid = optional_param('questionid', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$quizid = optional_param('quizid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$actiontype = optional_param('actiontype', '', PARAM_ALPHANUMEXT);
$search = optional_param('search', '', PARAM_TEXT);
$export = optional_param('export', '', PARAM_ALPHA);

$urlparams = [
    'questionid' => $questionid,
    'userid' => $userid,
    'quizid' => $quizid,
    'courseid' => $courseid,
    'actiontype' => $actiontype,
];

$pageinfo = \local_ai_programming_assistant\helper::init_stats_page(
    'asked_questions_page_title',
    '/local/ai_programming_assistant/asked_questions.php',
    $urlparams
);
$starttime = $pageinfo['starttime'];
$endtime = $pageinfo['endtime'];
$starttime_display = $pageinfo['starttime_display'];
$endtime_display = $pageinfo['endtime_display'];
$PAGE->set_pagelayout('standard');

$perpageoptions = [10, 20, 50, 100];
$perpage = optional_param('perpage', 20, PARAM_INT);
if (!in_array($perpage, $perpageoptions, true)) {
    $perpage = 20;
}
$page = optional_param('page', 1, PARAM_INT);
$page = max(1, $page);
$offset = ($page - 1) * $perpage;

$statsservice = \local_ai_programming_assistant\stats_service::get_instance();

// 构建筛选参数（仅传有效值）
$filter_questionid = $questionid > 0 ? $questionid : null;
$filter_userid = $userid > 0 ? $userid : null;
$filter_quizid = $quizid > 0 ? $quizid : null;
$filter_courseid = $courseid > 0 ? $courseid : null;
$filter_actiontype = $actiontype !== '' ? $actiontype : null;

// 解析筛选条件用于显示
$questionname = '';
$studentname = '';
$studentusername = '';
$quizname = '';
$coursename = '';

if ($questionid > 0) {
    $qinfo = $statsservice->get_question_info($questionid);
    $questionname = $qinfo ? $qinfo->name : get_string('unknown_question_id', 'local_ai_programming_assistant', $questionid);
}

if ($userid > 0) {
    $userinfo = \local_ai_programming_assistant\helper::get_user_display_info($userid);
    $studentname = $userinfo['name'];
    $studentusername = $userinfo['username'];
}

if ($quizid > 0) {
    $qinfo = $statsservice->get_quiz_info($quizid);
    $quizname = $qinfo ? $qinfo->name : get_string('unknown_quiz_id', 'local_ai_programming_assistant', $quizid);
}

if ($courseid > 0) {
    $courseinfo = get_course($courseid, IGNORE_MISSING);
    $coursename = $courseinfo ? $courseinfo->fullname : get_string('unknown_course_id', 'local_ai_programming_assistant', $courseid);
}

// 先统计总数，再基于 clamp 后的页码计算 offset（防止构造超大页码导致
// offset 溢出/空页显示异常，与 stats.php 行为一致）
$totalrecords = $statsservice->count_asked_questions(
    $filter_questionid, $filter_userid, $filter_actiontype, $filter_quizid, $filter_courseid,
    $starttime, $endtime, $search
);

// CSV 导出：全量导出（非当前页），上限由 safe_limit 保证
if ($export === 'csv') {
    $allrecords = $statsservice->get_asked_questions(
        $filter_questionid, $filter_userid, $filter_actiontype, $filter_quizid, $filter_courseid,
        $starttime, $endtime, $search, 5000, 0
    );

    $output = \local_ai_programming_assistant\helper::export_csv_header('asked_questions');

    \local_ai_programming_assistant\helper::csv_put($output, [
        get_string('csv_student', 'local_ai_programming_assistant'),
        get_string('csv_username', 'local_ai_programming_assistant'),
        get_string('csv_question', 'local_ai_programming_assistant'),
        get_string('csv_mode', 'local_ai_programming_assistant'),
        get_string('csv_question_content', 'local_ai_programming_assistant'),
        get_string('csv_time', 'local_ai_programming_assistant'),
        get_string('csv_quiz', 'local_ai_programming_assistant'),
        get_string('csv_course', 'local_ai_programming_assistant'),
    ]);
    foreach ($allrecords as $rec) {
        \local_ai_programming_assistant\helper::csv_put($output, [
            $rec['fullname'],
            $rec['username'],
            $rec['questionname'],
            $rec['mode_label'],
            $rec['question'],
            $rec['timecreated_str'],
            $rec['quizname'],
            $rec['coursename'],
        ]);
    }
    \local_ai_programming_assistant\helper::finish_csv_export($output);
}

$totalpages = max(1, (int)ceil($totalrecords / $perpage));
if ($page > $totalpages) {
    $page = $totalpages;
    $offset = ($page - 1) * $perpage;
}

// 获取提问记录列表
$records = $statsservice->get_asked_questions(
    $filter_questionid, $filter_userid, $filter_actiontype, $filter_quizid, $filter_courseid,
    $starttime, $endtime, $search, $perpage, $offset
);

// 为每条记录添加会话详情页 URL
foreach ($records as &$rec) {
    $rec['detailurl'] = new moodle_url('/local/ai_programming_assistant/conversation_detail.php', [
        'id' => $rec['conversation_id'],
        'starttime' => $starttime_display,
        'endtime' => $endtime_display,
    ]);
}
unset($rec);

// 构建筛选 URL 参数片段（用于分页链接和 CSV 导出）
$filterurlparams = '';
if ($questionid > 0) {
    $filterurlparams .= '&amp;questionid=' . $questionid;
}
if ($userid > 0) {
    $filterurlparams .= '&amp;userid=' . $userid;
}
if ($quizid > 0) {
    $filterurlparams .= '&amp;quizid=' . $quizid;
}
if ($courseid > 0) {
    $filterurlparams .= '&amp;courseid=' . $courseid;
}
if ($actiontype !== '') {
    $filterurlparams .= '&amp;actiontype=' . $actiontype;
}
if ($search !== '') {
    $filterurlparams .= '&amp;search=' . urlencode($search);
}

$perpageoptionlist = [];
foreach ($perpageoptions as $opt) {
    $perpageoptionlist[] = ['value' => $opt, 'selected' => $opt === $perpage];
}

// 是否有筛选条件
$has_filter = $questionid > 0 || $userid > 0 || $quizid > 0 || $courseid > 0 || $actiontype !== '' || $search !== '';

// 导出 URL（保留当前筛选，但不含分页参数）
$exporturl = new moodle_url('/local/ai_programming_assistant/asked_questions.php', [
    'questionid' => $questionid,
    'userid' => $userid,
    'quizid' => $quizid,
    'courseid' => $courseid,
    'actiontype' => $actiontype,
    'search' => $search,
    'starttime' => $starttime_display,
    'endtime' => $endtime_display,
    'export' => 'csv',
]);

// 返回 URL：优先使用 HTTP_REFERER（来自哪个页面就返回哪个页面），否则回退到 stats.php
$referer = get_local_referer(false);
if ($referer && strpos($referer, '/local/ai_programming_assistant/') !== false) {
    $backurl = $referer;
} else {
    $backurl = new moodle_url('/local/ai_programming_assistant/stats.php', [
        'starttime' => $starttime_display,
        'endtime' => $endtime_display,
        'quizid' => $quizid,
    ]);
}

$templatecontext = [
    'starttime_display' => $starttime_display,
    'endtime_display' => $endtime_display,
    'perpage' => $perpage,
    'perpageoptions' => $perpageoptionlist,
    'records' => $records,
    'hasdata' => !empty($records),
    'totalrecords' => $totalrecords,
    'backurl' => $backurl,

    // 筛选状态
    'questionid' => $questionid,
    'questionname' => $questionname,
    'has_question_filter' => $questionid > 0,
    'userid' => $userid,
    'studentname' => $studentname,
    'studentusername' => $studentusername,
    'has_user_filter' => $userid > 0,
    'quizid' => $quizid,
    'quizname' => $quizname,
    'has_quiz_filter' => $quizid > 0,
    'courseid' => $courseid,
    'coursename' => $coursename,
    'has_course_filter' => $courseid > 0,
    'actiontype' => $actiontype,
    'has_actiontype_filter' => $actiontype !== '',
    'actiontype_question_analysis' => $actiontype === 'question_analysis',
    'actiontype_idea_organization' => $actiontype === 'idea_organization',
    'actiontype_code_debugging' => $actiontype === 'code_debugging',
    'actiontype_free' => $actiontype === 'free',
    'search' => $search,
    'has_filter' => $has_filter,
    'filterurlparams' => $filterurlparams,
    'exporturl' => $exporturl->out(),

    // 导航标签
    'nav_asked_questions' => true,

    'presets' => \local_ai_programming_assistant\helper::get_date_presets($starttime, $endtime),
];

$templatecontext = array_merge(
    $templatecontext,
    \local_ai_programming_assistant\helper::build_pagination_context($page, $perpage, $totalrecords),
    \local_ai_programming_assistant\helper::build_nav_urls($starttime_display, $endtime_display)
);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_programming_assistant/asked_questions', $templatecontext);
echo $OUTPUT->footer();
