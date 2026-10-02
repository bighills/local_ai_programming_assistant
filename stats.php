<?php
/**
 * AI 编程助手 - 全局统计页面
 *
 * 展示 AI 助手的全局使用统计数据，包括请求量、成功率、模型使用等。
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */

require_once(__DIR__ . '/../../config.php');

defined('MOODLE_INTERNAL') || die();

require_login();

$pageinfo = \local_ai_programming_assistant\helper::init_stats_page(
    'stats_page_title',
    '/local/ai_programming_assistant/stats.php'
);
$context = $pageinfo['context'];
require_capability('local/ai_programming_assistant:viewstats', $context);
$starttime = $pageinfo['starttime'];
$endtime = $pageinfo['endtime'];
$starttime_display = $pageinfo['starttime_display'];
$endtime_display = $pageinfo['endtime_display'];
$PAGE->set_pagelayout('standard');

$quizid = optional_param('quizid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$studentsearch = optional_param('studentsearch', '', PARAM_TEXT);

$export = optional_param('export', '', PARAM_ALPHA);

// 题目列表分页和排序参数
$qperpageoptions = [10, 20, 50];
$qperpage = optional_param('qperpage', 10, PARAM_INT);
if (!in_array($qperpage, $qperpageoptions, true)) {
    $qperpage = 10;
}
$qpage = optional_param('qpage', 1, PARAM_INT);
$qpage = max(1, $qpage);
$qsort = optional_param('qsort', 'last_timecreated', PARAM_ALPHA);
$qorder = optional_param('qorder', 'DESC', PARAM_ALPHA);
$qorder = strtoupper($qorder) === 'ASC' ? 'ASC' : 'DESC';
$qoffset = ($qpage - 1) * $qperpage;

// 学生列表分页和排序参数
$sperpageoptions = [10, 20, 50];
$sperpage = optional_param('sperpage', 10, PARAM_INT);
if (!in_array($sperpage, $sperpageoptions, true)) {
    $sperpage = 10;
}
$spage = optional_param('spage', 1, PARAM_INT);
$spage = max(1, $spage);
$ssort = optional_param('ssort', 'last_timecreated', PARAM_ALPHA);
$sorder = optional_param('sorder', 'DESC', PARAM_ALPHA);
$sorder = strtoupper($sorder) === 'ASC' ? 'ASC' : 'DESC';
$soffset = ($spage - 1) * $sperpage;

$statsservice = \local_ai_programming_assistant\stats_service::get_instance();

// 构建重定向基础 URL（保留所有筛选参数）
$delete_redirect_params = [
    'starttime' => $starttime_display,
    'endtime' => $endtime_display,
    'quizid' => $quizid,
    'courseid' => $courseid,
    'studentsearch' => $studentsearch,
    'qperpage' => $qperpage,
    'qpage' => $qpage,
    'qsort' => $qsort,
    'qorder' => $qorder,
    'sperpage' => $sperpage,
    'spage' => $spage,
    'ssort' => $ssort,
    'sorder' => $sorder,
];

// 处理记录删除
$action = optional_param('action', '', PARAM_ALPHA);
if ($action === 'delete' && confirm_sesskey()) {
    $deletewhat = optional_param('deletewhat', '', PARAM_ALPHA);
    $recordids = required_param('recordids', PARAM_SEQUENCE);
    $ids = array_filter(array_map('intval', explode(',', $recordids)), function($id) { return $id > 0; });
    $deleted = 0;
    if (!empty($ids)) {
        // 删除范围必须与页面筛选保持一致（quizid/courseid/时间范围），
        // 否则会误删当前筛选范围之外（甚至自由模式）的会话，造成数据丢失。
        $quizfilter = $quizid > 0 ? $quizid : null;
        $coursefilter = $courseid > 0 ? $courseid : null;
        if ($deletewhat === 'questions') {
            $deleted = $statsservice->delete_conversations_by_questionids($ids, $quizfilter, $coursefilter, $starttime, $endtime);
        } else if ($deletewhat === 'students') {
            $deleted = $statsservice->delete_conversations_by_userids($ids, false, $quizfilter, $coursefilter, $starttime, $endtime);
        }
    }
    \core\notification::success(get_string('stats_record_delete_success', 'local_ai_programming_assistant', $deleted));
    redirect(new moodle_url('/local/ai_programming_assistant/stats.php', $delete_redirect_params));
}

$quizzes = $statsservice->get_quizzes_with_usage();

// 从测验列表中提取唯一课程列表
$courses = [];
$quizbycourse = [];
foreach ($quizzes as $q) {
    $cid = $q->courseid;
    if (!isset($courses[$cid])) {
        $courses[$cid] = (object)[
            'id' => $cid,
            'fullname' => $q->coursename,
            'isselected' => ($courseid > 0 && $cid === $courseid),
        ];
    }
    // 按课程分组存放测验
    if (!isset($quizbycourse[$cid])) {
        $quizbycourse[$cid] = [];
    }
    $quizbycourse[$cid][] = $q;
}
// 按课程名排序
usort($courses, fn($a, $b) => strcmp($a->fullname, $b->fullname));
$has_courses = !empty($courses);

// 如果选中的测验不属于选中的课程，重置 quizid
if ($courseid > 0 && $quizid > 0 && isset($quizbycourse[$courseid])) {
    $belongs = false;
    foreach ($quizbycourse[$courseid] as $q) {
        if ((int)$q->quizid === $quizid) {
            $belongs = true;
            break;
        }
    }
    if (!$belongs) {
        $quizid = 0;
    }
}

// 如果选中了课程，只显示该课程下的测验
$filteredquizzes = $quizzes;
if ($courseid > 0 && isset($quizbycourse[$courseid])) {
    $filteredquizzes = $quizbycourse[$courseid];
}

$currentquizname = '';
foreach ($filteredquizzes as $q) {
    $q->isselected = ($quizid > 0 && (int)$q->quizid === $quizid);
    if ($q->isselected) {
        $currentquizname = $q->quiz_name;
    }
}

if ($quizid > 0) {
    $overallstats = $statsservice->get_overall_stats($starttime, $endtime, $quizid);
    $totalquestions = $statsservice->count_quiz_question_stats($quizid);
    $totalstudents = $statsservice->count_quiz_student_stats($quizid, $starttime, $endtime, null, $studentsearch);
} else {
    // 未选中具体测验时，将课程筛选透传给所有并列数据区块（KPI、榜单、分页计数），
    // 避免"课程范围下拉 + 全局范围数据"的维度不一致视图。
    $overallstats = $statsservice->get_overall_stats($starttime, $endtime, null, $courseid);
    $totalquestions = $statsservice->count_top_questions($starttime, $endtime, $courseid);
    $totalstudents = $statsservice->count_student_statistics($starttime, $endtime, $studentsearch, $courseid);
}

// 页码 clamp：防止构造超大页码导致 offset 溢出/空页显示异常
$qtotalpages = max(1, (int)ceil($totalquestions / $qperpage));
if ($qpage > $qtotalpages) {
    $qpage = $qtotalpages;
    $qoffset = ($qpage - 1) * $qperpage;
}
$stotalpages = max(1, (int)ceil($totalstudents / $sperpage));
if ($spage > $stotalpages) {
    $spage = $stotalpages;
    $soffset = ($spage - 1) * $sperpage;
}

if ($quizid > 0) {
    $topquestions = $statsservice->get_quiz_question_stats($quizid, $starttime, $endtime, $qperpage, null, $qoffset, $qsort, $qorder);
    $studentstats = $statsservice->get_quiz_student_stats($quizid, $starttime, $endtime, $sperpage, null, $studentsearch, $soffset, $ssort, $sorder);
} else {
    $topquestions = $statsservice->get_top_questions($qperpage, $starttime, $endtime, $qoffset, $qsort, $qorder, $courseid);
    $studentstats = $statsservice->get_student_statistics($sperpage, $starttime, $endtime, $studentsearch, $soffset, $ssort, $sorder, $courseid);
}

if ($export === 'csv') {
    // 导出必须包含全部数据（非当前页）：重新查询全量记录，
    // 否则 CSV 只会导出当前分页的 10-50 条。
    if ($quizid > 0) {
        $exportquestions = $statsservice->get_quiz_question_stats($quizid, $starttime, $endtime, 5000, null, 0, $qsort, $qorder);
        $exportstudents = $statsservice->get_quiz_student_stats($quizid, $starttime, $endtime, 5000, null, $studentsearch, 0, $ssort, $sorder);
    } else {
        $exportquestions = $statsservice->get_top_questions(5000, $starttime, $endtime, 0, $qsort, $qorder, $courseid);
        $exportstudents = $statsservice->get_student_statistics(5000, $starttime, $endtime, $studentsearch, 0, $ssort, $sorder, $courseid);
    }

    $output = \local_ai_programming_assistant\helper::export_csv_header('top_questions_stats');

    \local_ai_programming_assistant\helper::csv_put($output, [get_string('stats_page_title', 'local_ai_programming_assistant')]);
    if ($quizid > 0) {
        \local_ai_programming_assistant\helper::csv_put($output, [get_string('stats_quiz', 'local_ai_programming_assistant') . ': ' . $currentquizname]);
    }
    \local_ai_programming_assistant\helper::csv_put($output, [
        get_string('stats_total_requests', 'local_ai_programming_assistant'),
        get_string('stats_success_rate', 'local_ai_programming_assistant'),
        get_string('stats_avg_responsetime', 'local_ai_programming_assistant') . ' (ms)',
        get_string('stats_unique_users', 'local_ai_programming_assistant'),
        get_string('stats_unique_questions', 'local_ai_programming_assistant'),
    ]);
    \local_ai_programming_assistant\helper::csv_put($output, [
        $overallstats['total_requests'],
        $overallstats['success_rate'] . '%',
        $overallstats['avg_responsetime'],
        $overallstats['unique_users'],
        $overallstats['unique_questions'],
    ]);
    \local_ai_programming_assistant\helper::csv_put($output, []);

    \local_ai_programming_assistant\helper::csv_put($output, [get_string('stats_top_questions', 'local_ai_programming_assistant')]);
    \local_ai_programming_assistant\helper::csv_put($output, [
        get_string('stats_question', 'local_ai_programming_assistant'),
        get_string('stats_question_name', 'local_ai_programming_assistant'),
        get_string('stats_total_requests', 'local_ai_programming_assistant'),
        get_string('stats_last_interaction', 'local_ai_programming_assistant'),
    ]);
    foreach ($exportquestions as $q) {
        \local_ai_programming_assistant\helper::csv_put($output, [$q['questionid'], $q['questionname'],
            $q['total_requests'], $q['last_timecreated_str'] ?? '']);
    }
    \local_ai_programming_assistant\helper::csv_put($output, []);

    \local_ai_programming_assistant\helper::csv_put($output, [get_string('stats_student_usage', 'local_ai_programming_assistant')]);
    \local_ai_programming_assistant\helper::csv_put($output, [
        get_string('stats_user', 'local_ai_programming_assistant'),
        get_string('stats_username', 'local_ai_programming_assistant'),
        get_string('stats_total_requests', 'local_ai_programming_assistant'),
        get_string('stats_last_interaction', 'local_ai_programming_assistant'),
        get_string('stats_unique_questions', 'local_ai_programming_assistant'),
    ]);
    foreach ($exportstudents as $s) {
        \local_ai_programming_assistant\helper::csv_put($output, [$s['fullname'] ?? '', $s['username'] ?? '',
            $s['total_requests'], $s['last_timecreated_str'] ?? '',
            $s['unique_questions']]);
    }

    \local_ai_programming_assistant\helper::finish_csv_export($output);
}

$questiontotals = null;
if (!empty($topquestions)) {
    $questiontotals = \local_ai_programming_assistant\helper::compute_stats_totals($topquestions);
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
    'quizid' => $quizid,
    'courseid' => $courseid,
    'studentsearch' => $studentsearch,
    'qperpage' => $qperpage,
    'sperpage' => $sperpage,
]);

// ===== 题目列表排序 URL =====
$qsortfields = ['questionid', 'questionname', 'total_requests', 'last_timecreated'];
$qsorturls = [];
foreach ($qsortfields as $field) {
    $neworder = ($field === $qsort && $qorder === 'DESC') ? 'ASC' : 'DESC';
    $url = clone $baseurl;
    $url->param('qsort', $field);
    $url->param('qorder', $neworder);
    $url->param('qpage', 1);
    $url->param('spage', $spage);
    $url->param('ssort', $ssort);
    $url->param('sorder', $sorder);
    $qsorturls[$field] = $url->out();
}

// 题目列表排序方向指示
$qsortindicators = [];
foreach ($qsortfields as $field) {
    $qsortindicators['q_' . $field . '_asc'] = ($qsort === $field && $qorder === 'ASC');
    $qsortindicators['q_' . $field . '_desc'] = ($qsort === $field && $qorder === 'DESC');
}

// ===== 学生列表排序 URL =====
$ssortfields = ['fullname', 'total_requests', 'last_timecreated', 'unique_questions'];
$ssorturls = [];
foreach ($ssortfields as $field) {
    $neworder = ($field === $ssort && $sorder === 'DESC') ? 'ASC' : 'DESC';
    $url = clone $baseurl;
    $url->param('ssort', $field);
    $url->param('sorder', $neworder);
    $url->param('spage', 1);
    $url->param('qpage', $qpage);
    $url->param('qsort', $qsort);
    $url->param('qorder', $qorder);
    $ssorturls[$field] = $url->out();
}

// 学生列表排序方向指示
$ssortindicators = [];
foreach ($ssortfields as $field) {
    $ssortindicators['s_' . $field . '_asc'] = ($ssort === $field && $sorder === 'ASC');
    $ssortindicators['s_' . $field . '_desc'] = ($ssort === $field && $sorder === 'DESC');
}

// ===== 题目分页 =====
$qtotalpages = max(1, (int)ceil($totalquestions / $qperpage));
$qpagination = [];
$qpagestart = max(1, $qpage - 5);
$qpageend = min($qtotalpages, $qpagestart + 9);
if ($qpageend - $qpagestart + 1 < 10) {
    $qpagestart = max(1, $qpageend - 9);
}
for ($i = $qpagestart; $i <= $qpageend; $i++) {
    $url = clone $baseurl;
    $url->param('qpage', $i);
    $url->param('qsort', $qsort);
    $url->param('qorder', $qorder);
    $url->param('spage', $spage);
    $url->param('ssort', $ssort);
    $url->param('sorder', $sorder);
    $qpagination[] = [
        'page' => $i,
        'url' => $url->out(),
        'is_active' => $i == $qpage,
    ];
}

$qprevurl = null;
$qnexturl = null;
if ($qpage > 1) {
    $url = clone $baseurl;
    $url->param('qpage', $qpage - 1);
    $url->param('qsort', $qsort);
    $url->param('qorder', $qorder);
    $url->param('spage', $spage);
    $url->param('ssort', $ssort);
    $url->param('sorder', $sorder);
    $qprevurl = $url->out();
}
if ($qpage < $qtotalpages) {
    $url = clone $baseurl;
    $url->param('qpage', $qpage + 1);
    $url->param('qsort', $qsort);
    $url->param('qorder', $qorder);
    $url->param('spage', $spage);
    $url->param('ssort', $ssort);
    $url->param('sorder', $sorder);
    $qnexturl = $url->out();
}

$qpagestartitem = ($qpage - 1) * $qperpage + 1;
$qpageenditem = min($qpage * $qperpage, $totalquestions);

// ===== 学生分页 =====
$stotalpages = max(1, (int)ceil($totalstudents / $sperpage));
$spagination = [];
$spagestart = max(1, $spage - 5);
$spageend = min($stotalpages, $spagestart + 9);
if ($spageend - $spagestart + 1 < 10) {
    $spagestart = max(1, $spageend - 9);
}
for ($i = $spagestart; $i <= $spageend; $i++) {
    $url = clone $baseurl;
    $url->param('spage', $i);
    $url->param('ssort', $ssort);
    $url->param('sorder', $sorder);
    $url->param('qpage', $qpage);
    $url->param('qsort', $qsort);
    $url->param('qorder', $qorder);
    $spagination[] = [
        'page' => $i,
        'url' => $url->out(),
        'is_active' => $i == $spage,
    ];
}

$sprevurl = null;
$snexturl = null;
if ($spage > 1) {
    $url = clone $baseurl;
    $url->param('spage', $spage - 1);
    $url->param('ssort', $ssort);
    $url->param('sorder', $sorder);
    $url->param('qpage', $qpage);
    $url->param('qsort', $qsort);
    $url->param('qorder', $qorder);
    $sprevurl = $url->out();
}
if ($spage < $stotalpages) {
    $url = clone $baseurl;
    $url->param('spage', $spage + 1);
    $url->param('ssort', $ssort);
    $url->param('sorder', $sorder);
    $url->param('qpage', $qpage);
    $url->param('qsort', $qsort);
    $url->param('qorder', $qorder);
    $snexturl = $url->out();
}

$spagestartitem = ($spage - 1) * $sperpage + 1;
$spageenditem = min($spage * $sperpage, $totalstudents);

$templatecontext = [
    'stats' => $overallstats,
    'wwwroot' => $CFG->wwwroot,
    'topquestions' => $topquestions,
    'questiontotals' => $questiontotals,
    'has_questiontotals' => !empty($questiontotals),
    'studentstats' => $studentstats,
    'studenttotals' => $studenttotals,
    'has_studenttotals' => !empty($studenttotals),

    'has_modelusage' => !empty($overallstats['model_usage']),
    'has_modeusage' => !empty($overallstats['mode_usage']),
    'has_export' => $hasdata,

    // 导航标签
    'nav_stats' => true,

    'recentquizchatsurl' => (new moodle_url('/local/ai_programming_assistant/recent_quiz_chats.php', [
        'starttime' => $starttime_display,
        'endtime' => $endtime_display,
    ]))->out(),

    'starttime' => $starttime,
    'endtime' => $endtime,
    'starttime_display' => $starttime_display,
    'endtime_display' => $endtime_display,
    'quizid' => $quizid,
    'courseid' => $courseid,
    'currentquizname' => $currentquizname,
    'quizzes' => $filteredquizzes,
    'has_quizzes' => !empty($filteredquizzes),
    'courses' => $courses,
    'has_courses' => $has_courses,
    'presets' => \local_ai_programming_assistant\helper::get_date_presets($starttime, $endtime),
    'studentsearch' => $studentsearch,

    // 题目分页
    'qpage' => $qpage,
    'qperpage' => $qperpage,
    'qtotalpages' => $qtotalpages,
    'qhas_pagination' => $qtotalpages > 1,
    'qpagination' => $qpagination,
    'qprevurl' => $qprevurl,
    'qnexturl' => $qnexturl,
    'qhas_previous' => $qpage > 1,
    'qhas_next' => $qpage < $qtotalpages,
    'qpagestartitem' => $qpagestartitem,
    'qpageenditem' => $qpageenditem,
    'qtotalcount' => $totalquestions,
    'qsort' => $qsort,
    'qorder' => strtolower($qorder),
    'qsorturls' => $qsorturls,
    'qperpageoptions' => array_map(function($opt) use ($qperpage, $baseurl, $qsort, $qorder, $spage, $ssort, $sorder) {
        $url = clone $baseurl;
        $url->param('qperpage', $opt);
        $url->param('qpage', 1);
        $url->param('qsort', $qsort);
        $url->param('qorder', $qorder);
        $url->param('spage', $spage);
        $url->param('ssort', $ssort);
        $url->param('sorder', $sorder);
        return ['value' => $opt, 'selected' => $opt === $qperpage, 'url' => $url->out()];
    }, $qperpageoptions),

    // 学生分页
    'spage' => $spage,
    'sperpage' => $sperpage,
    'stotalpages' => $stotalpages,
    'shas_pagination' => $stotalpages > 1,
    'spagination' => $spagination,
    'sprevurl' => $sprevurl,
    'snexturl' => $snexturl,
    'shas_previous' => $spage > 1,
    'shas_next' => $spage < $stotalpages,
    'spagestartitem' => $spagestartitem,
    'spageenditem' => $spageenditem,
    'stotalcount' => $totalstudents,
    'ssort' => $ssort,
    'sorder' => strtolower($sorder),
    'ssorturls' => $ssorturls,
    'sperpageoptions' => array_map(function($opt) use ($sperpage, $baseurl, $qsort, $qorder, $qpage, $ssort, $sorder) {
        $url = clone $baseurl;
        $url->param('sperpage', $opt);
        $url->param('spage', 1);
        $url->param('ssort', $ssort);
        $url->param('sorder', $sorder);
        $url->param('qpage', $qpage);
        $url->param('qsort', $qsort);
        $url->param('qorder', $qorder);
        return ['value' => $opt, 'selected' => $opt === $sperpage, 'url' => $url->out()];
    }, $sperpageoptions),

    // 记录删除
    'q_delete_action_url' => (new moodle_url('/local/ai_programming_assistant/stats.php', array_merge($delete_redirect_params, ['deletewhat' => 'questions'])))->out(),
    's_delete_action_url' => (new moodle_url('/local/ai_programming_assistant/stats.php', array_merge($delete_redirect_params, ['deletewhat' => 'students'])))->out(),
    'confirm_delete_msg_json' => json_encode(get_string('stats_record_confirm_delete', 'local_ai_programming_assistant', '__COUNT__')),
] + $qsortindicators + $ssortindicators + \local_ai_programming_assistant\helper::build_nav_urls($starttime_display, $endtime_display);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_programming_assistant/stats_page', $templatecontext);
echo $OUTPUT->footer();