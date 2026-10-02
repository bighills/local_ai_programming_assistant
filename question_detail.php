<?php
/**
 * AI 编程助手 - 试题详情页
 *
 * 显示单个题目的 AI 辅助统计详情，包括学生使用情况和对话记录。
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */

require_once(__DIR__ . '/../../config.php');

defined('MOODLE_INTERNAL') || die();

$quizid = optional_param('quizid', 0, PARAM_INT);
$questionid = optional_param('questionid', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$cmid = optional_param('cmid', 0, PARAM_INT);
$groupid = optional_param('groupid', 0, PARAM_INT);
$starttime = optional_param('starttime', '', PARAM_TEXT);
$endtime = optional_param('endtime', '', PARAM_TEXT);
$actiontype = optional_param('actiontype', '', PARAM_ALPHAEXT);
$page = optional_param('page', 1, PARAM_INT);
$page = max(1, $page);
$perpage = 10;

$PAGE->set_url('/local/ai_programming_assistant/question_detail.php', [
    'quizid' => $quizid, 'questionid' => $questionid, 'userid' => $userid,
    'cmid' => $cmid, 'groupid' => $groupid, 'actiontype' => $actiontype,
]);

// 必须提供 quizid 和 questionid
if ($quizid <= 0 || $questionid <= 0) {
    redirect(new moodle_url('/local/ai_programming_assistant/stats.php'),
        get_string('invalid_parameters', 'local_ai_programming_assistant'), null, 'error');
}

if ($cmid > 0) {
    $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
    $modulecontext = context_module::instance($cm->id);
    $course = get_course($cm->course);
    require_login($course, true, $cm);
    // 安全：传入 cmid 时强制绑定其对应的测验，忽略用户提供的 quizid，
    // 防止通过 cmid（自己有权访问）+ quizid（其它课程的测验）组合越权读取数据。
    $quizid = (int)$cm->instance;
} else {
    $modulecontext = null;
    require_login();
}

$statsservice = \local_ai_programming_assistant\stats_service::get_instance();

if ($cmid > 0) {
    require_capability('local/ai_programming_assistant:viewstats', $modulecontext);
    $PAGE->set_context($modulecontext);
    $PAGE->set_course($course);
    $PAGE->set_heading($course->fullname);
    $PAGE->navbar->add(get_string('quiz_stats_page_title', 'local_ai_programming_assistant'),
        new moodle_url('/local/ai_programming_assistant/quiz_stats.php', ['quizid' => $quizid, 'cmid' => $cmid]));
} else if ($quizid > 0) {
    $quizinfo = $statsservice->get_quiz_info($quizid);
    if ($quizinfo) {
        $coursecontext = context_course::instance($quizinfo->course);
        $PAGE->set_context($coursecontext);
        require_capability('local/ai_programming_assistant:viewstats', $coursecontext);
    } else {
        require_capability('local/ai_programming_assistant:viewstats', context_system::instance());
    }
} else {
    require_capability('local/ai_programming_assistant:viewstats', context_system::instance());
}

$PAGE->set_title(get_string('question_detail_page_title', 'local_ai_programming_assistant'));
$PAGE->set_pagelayout('standard');

$daterange = \local_ai_programming_assistant\helper::resolve_date_range($starttime, $endtime, \local_ai_programming_assistant\helper::QUIZ_DEFAULT_DAYS);
$starttimestamp = $daterange['starttimestamp'];
$endtimestamp = $daterange['endtimestamp'];
$starttime_display = $daterange['starttime_display'];
$endtime_display = $daterange['endtime_display'];

$baseurlparams = http_build_query(array_filter([
    'quizid' => $quizid ?: null,
    'questionid' => $questionid ?: null,
    'cmid' => $cmid ?: null,
    'groupid' => $groupid ?: null,
    'starttime' => $starttime_display ?: null,
    'endtime' => $endtime_display ?: null,
    'actiontype' => $actiontype ?: null,
    'page' => $page > 1 ? $page : null,
]));

$templatecontext = [
    'quizid' => $quizid,
    'questionid' => $questionid,
    'userid' => $userid,
    'cmid' => $cmid,
    'groupid' => $groupid,
    'starttime_display' => $starttime_display,
    'endtime_display' => $endtime_display,
    'baseurlparams' => $baseurlparams,
    'showbacktoquiz' => ($cmid > 0),
    'backtoquizurl' => $cmid > 0 ? new moodle_url('/mod/quiz/view.php', ['id' => $cmid]) : '',
    'backtostatsurl' => new moodle_url('/local/ai_programming_assistant/quiz_stats.php', ['quizid' => $quizid, 'cmid' => $cmid]),
    'invalidquestion' => false,
    'hasdata' => false,
    'shownodata' => false,
];

$questioninfo = $statsservice->get_question_info($questionid);

if (!isset($quizinfo)) {
    $quizinfo = $statsservice->get_quiz_info($quizid);
}

if (!$quizinfo || !$questioninfo) {
    $templatecontext['invalidquestion'] = true;
} else {
    $templatecontext['quizname'] = $quizinfo->name;
    $templatecontext['questionname'] = $questioninfo->name;

    $courseid = $quizinfo->course;

    // 若未传入 cmid，根据 quizid 自动查找 course module
    if ($cmid <= 0) {
        $cm = get_coursemodule_from_instance('quiz', $quizid, $courseid);
        if ($cm) {
            $cmid = $cm->id;
            $templatecontext['cmid'] = $cmid;
            $templatecontext['showbacktoquiz'] = true;
            $templatecontext['backtoquizurl'] = new moodle_url('/mod/quiz/view.php', ['id' => $cmid]);
            $templatecontext['backtostatsurl'] = new moodle_url(
                '/local/ai_programming_assistant/quiz_stats.php',
                ['quizid' => $quizid, 'cmid' => $cmid]
            );
        }
    }

    $groups = [];
    $allgroups = groups_get_all_groups($courseid);
    foreach ($allgroups as $g) {
        $groups[] = [
            'id' => $g->id,
            'name' => $g->name,
            'selected' => ($g->id == $groupid),
        ];
    }
    $templatecontext['hasgroups'] = !empty($groups);
    $templatecontext['groups'] = $groups;

    $groupuserids = [];
    if ($groupid > 0) {
        $validgroup = groups_get_group($groupid);
        if (!$validgroup || $validgroup->courseid != $courseid) {
            $groupid = 0;
            $templatecontext['groupid'] = 0;
        } else {
            $groupmembers = groups_get_members($groupid);
            $groupuserids = !empty($groupmembers) ? array_keys($groupmembers) : [];
        }
    }

    if ($userid > 0) {
        $userdetail = $statsservice->get_question_student_feedback_details(
            $quizid, $questionid, $userid, $starttimestamp, $endtimestamp, 5000, $actiontype
        );
        $templatecontext['userdetail'] = $userdetail;
        $templatecontext['hasdata'] = !empty($userdetail);
        $templatecontext['isstudentview'] = true;
        $templatecontext['actiontype'] = $actiontype;
        $templatecontext['actiontype_label'] = $actiontype !== ''
            ? \local_ai_programming_assistant\helper::get_mode_label($actiontype) : '';
        $templatecontext['totalrecords'] = count($userdetail);

        $userinfo = \local_ai_programming_assistant\helper::get_user_display_info($userid);
        $templatecontext['studentname'] = $userinfo['name'];
        $templatecontext['studentusername'] = $userinfo['username'];
    } else {
        // 先统计总数，再基于 clamp 后的页码计算 offset（与 stats.php 行为一致）
        $totalstudents = $statsservice->count_question_followups(
            $quizid, $questionid, $starttimestamp, $endtimestamp,
            $groupid > 0 ? $groupuserids : null, $actiontype
        );
        $totalpages = max(1, (int)ceil($totalstudents / $perpage));
        if ($page > $totalpages) {
            $page = $totalpages;
        }
        $offset = ($page - 1) * $perpage;
        $studentfollowups = $statsservice->get_question_followups(
            $quizid, $questionid, $starttimestamp, $endtimestamp, $perpage,
            $groupid > 0 ? $groupuserids : null, $offset, $actiontype
        );

        $templatecontext['studentfollowups'] = $studentfollowups;
        $templatecontext['hasdata'] = !empty($studentfollowups);
        $templatecontext['isstudentview'] = false;
        $templatecontext['totalstudents'] = $totalstudents;
        $templatecontext['actiontype'] = $actiontype;
        $templatecontext['actiontype_label'] = $actiontype !== ''
            ? \local_ai_programming_assistant\helper::get_mode_label($actiontype) : '';

        $templatecontext = array_merge($templatecontext, \local_ai_programming_assistant\helper::build_pagination_context($page, $perpage, $totalstudents));
    }

    $templatecontext['shownodata'] = !$templatecontext['hasdata'];
}

\local_ai_programming_assistant\helper::init_code_highlight($PAGE);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_programming_assistant/question_detail_page', $templatecontext);
echo $OUTPUT->footer();