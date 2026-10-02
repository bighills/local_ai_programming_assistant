<?php
/**
 * AI 编程助手 - 最近 AI 使用对话记录页（一级：按试题+用户汇总）
 *
 * 展示所有学生最近提交的 AI 使用对话记录（有题号），按试题和用户分组汇总。
 * 支持时间范围筛选、学生姓名搜索、分页和 CSV 导出。
 * 点击记录可跳转到 question_detail.php 查看具体对话内容。
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
    'recent_quiz_chats_page_title',
    '/local/ai_programming_assistant/recent_quiz_chats.php'
);
$starttime = $pageinfo['starttime'];
$endtime = $pageinfo['endtime'];
$starttime_display = $pageinfo['starttime_display'];
$endtime_display = $pageinfo['endtime_display'];
$PAGE->set_pagelayout('standard');

$studentsearch = optional_param('studentsearch', '', PARAM_RAW_TRIMMED);
$export = optional_param('export', '', PARAM_ALPHA);
$quizid = optional_param('quizid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);

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
$totalrecords = $statsservice->count_recent_quiz_chats_grouped(
    $starttime, $endtime, $studentsearch,
    $courseid > 0 ? $courseid : null, $quizid > 0 ? $quizid : null
);
$totalpages = max(1, (int)ceil($totalrecords / $perpage));
if ($page > $totalpages) {
    $page = $totalpages;
}
$offset = ($page - 1) * $perpage;

$records = $statsservice->get_recent_quiz_chats_grouped(
    $starttime, $endtime, $perpage, $offset, $studentsearch,
    $courseid > 0 ? $courseid : null, $quizid > 0 ? $quizid : null
);

foreach ($records as &$r) {
    // 测验过滤链接（点击测验名称筛选该测验的所有记录）
    // 透传 studentsearch 与 courseid，避免跳转后已生效的搜索/课程筛选丢失
    $quizfilterparams = [
        'starttime' => $starttime_display,
        'endtime' => $endtime_display,
        'quizid' => $r['quizid'],
    ];
    if ($studentsearch !== '') {
        $quizfilterparams['studentsearch'] = $studentsearch;
    }
    if ($courseid > 0) {
        $quizfilterparams['courseid'] = $courseid;
    }
    $r['quizfilterurl'] = new moodle_url('/local/ai_programming_assistant/recent_quiz_chats.php', $quizfilterparams);

    // 测验查看链接（通过 quizid 查找 cmid 跳转到测验页面）
    $r['quizviewurl'] = null;
    if ($r['quizid'] > 0) {
        $cm = get_coursemodule_from_instance('quiz', $r['quizid'], 0, false, IGNORE_MISSING);
        if ($cm) {
            $r['quizviewurl'] = new moodle_url('/mod/quiz/view.php', ['id' => $cm->id]);
        }
    }

    // 会话列表链接（按试题+测验+策略模式筛选）
    $convparams = ['questionid' => $r['questionid'], 'starttime' => $starttime_display, 'endtime' => $endtime_display];
    if ($r['quizid'] > 0) {
        $convparams['quizid'] = $r['quizid'];
    }
    if (!empty($r['actiontype'])) {
        $convparams['actiontype'] = $r['actiontype'];
    }
    $r['conversationlisturl'] = (new moodle_url('/local/ai_programming_assistant/conversation_list.php', $convparams))->out();

    // 试题名称链接：查看该题在当前策略模式下的对话（所有学生），按时间从早到晚排列
    $nameparams = $convparams;
    $nameparams['order'] = 'asc';
    $r['questionnameurl'] = (new moodle_url('/local/ai_programming_assistant/conversation_list.php', $nameparams))->out();

    // 策略模式链接：直接显示该学生在当前策略模式下对该试题的AI对话详情（从最早一条开始，可 prev/next 切换）
    $modeparams = [
        'questionid' => $r['questionid'],
        'userid'     => $r['userid'],
        'starttime'  => $starttime_display,
        'endtime'    => $endtime_display,
    ];
    if ($r['quizid'] > 0) {
        $modeparams['quizid'] = $r['quizid'];
    }
    if (!empty($r['actiontype'])) {
        $modeparams['actiontype'] = $r['actiontype'];
    }
    $r['modedetailurl'] = (new moodle_url('/local/ai_programming_assistant/conversation_detail.php', $modeparams))->out();
}
unset($r);

if ($export === 'csv') {
    $allrecords = $statsservice->get_recent_quiz_chats_grouped(
        $starttime, $endtime, 5000, 0, $studentsearch,
        $courseid > 0 ? $courseid : null, $quizid > 0 ? $quizid : null
    );

    $output = \local_ai_programming_assistant\helper::export_csv_header('recent_quiz_chats_grouped');

    \local_ai_programming_assistant\helper::csv_put($output, [get_string('recent_quiz_chats_page_title', 'local_ai_programming_assistant')]);
    \local_ai_programming_assistant\helper::csv_put($output, [
        get_string('stats_time_from', 'local_ai_programming_assistant') . ': ' . $starttime_display,
        get_string('stats_time_to', 'local_ai_programming_assistant') . ': ' . $endtime_display,
    ]);
    if (!empty($studentsearch)) {
        \local_ai_programming_assistant\helper::csv_put($output, [get_string('recent_free_chats_filter_student', 'local_ai_programming_assistant') . ': ' . $studentsearch]);
    }
    if ($quizid > 0) {
        \local_ai_programming_assistant\helper::csv_put($output, [get_string('recent_quiz_chats_quizid', 'local_ai_programming_assistant') . ': ' . $quizid]);
    }
    if ($courseid > 0) {
        \local_ai_programming_assistant\helper::csv_put($output, [get_string('recent_quiz_chats_course', 'local_ai_programming_assistant') . ' ID: ' . $courseid]);
    }
    \local_ai_programming_assistant\helper::csv_put($output, []);

    \local_ai_programming_assistant\helper::csv_put($output, [
        get_string('recent_quiz_chats_questionid', 'local_ai_programming_assistant'),
        get_string('recent_quiz_chats_questionname', 'local_ai_programming_assistant'),
        get_string('recent_quiz_chats_quizname', 'local_ai_programming_assistant'),
        get_string('recent_quiz_chats_course', 'local_ai_programming_assistant'),
        get_string('stats_user', 'local_ai_programming_assistant'),
        get_string('stats_username', 'local_ai_programming_assistant'),
        get_string('recent_quiz_chats_mode', 'local_ai_programming_assistant'),
        get_string('stats_total_requests', 'local_ai_programming_assistant'),
        get_string('student_questions_last_interaction', 'local_ai_programming_assistant'),
    ]);

    foreach ($allrecords as $r) {
        \local_ai_programming_assistant\helper::csv_put($output, [
            $r['questionid'],
            $r['questionname'],
            $r['quizname'],
            $r['coursename'],
            $r['fullname'],
            $r['username'],
            $r['actiontype_label'],
            $r['interaction_count'],
            $r['last_interaction_str'],
        ]);
    }

    \local_ai_programming_assistant\helper::csv_put($output, []);
    \local_ai_programming_assistant\helper::csv_put($output, [
        get_string('recent_quiz_chats_total', 'local_ai_programming_assistant') . ': ' . $totalrecords,
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
    'quizid' => $quizid,
    'courseid' => $courseid,
    'perpage' => $perpage,
    'perpageoptions' => $perpageoptionlist,
    'records' => $records,
    'hasdata' => !empty($records),
    'totalrecords' => $totalrecords,
    'backtostatsurl' => new moodle_url('/local/ai_programming_assistant/stats.php', [
        'starttime' => $starttime_display,
        'endtime' => $endtime_display,
    ]),
    // 导航标签
    'nav_stats' => true,
    
    'presets' => \local_ai_programming_assistant\helper::get_date_presets($starttime, $endtime),
];
$templatecontext += \local_ai_programming_assistant\helper::build_nav_urls($starttime_display, $endtime_display);

if ($quizid > 0) {
    $quizinfo = $statsservice->get_quiz_info($quizid);
    if ($quizinfo) {
        $templatecontext['quizname'] = $quizinfo->name;
        // 清除测验筛选时保留 studentsearch，并透传 courseid，避免课程筛选一并丢失
        $clearfilterparams = [
            'starttime' => $starttime_display,
            'endtime' => $endtime_display,
            'studentsearch' => $studentsearch,
        ];
        if ($courseid > 0) {
            $clearfilterparams['courseid'] = $courseid;
        }
        $templatecontext['clearfilterurl'] = new moodle_url('/local/ai_programming_assistant/recent_quiz_chats.php', $clearfilterparams);
    }
}

$templatecontext = array_merge(
    $templatecontext,
    \local_ai_programming_assistant\helper::build_pagination_context($page, $perpage, $totalrecords)
);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_programming_assistant/recent_quiz_chats', $templatecontext);
echo $OUTPUT->footer();