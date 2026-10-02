<?php
/**
 * AI 编程助手 - 使用总揽页面
 *
 * 合并展示试题模式 + 自由模式的整体使用情况：KPI 卡片、每日趋势柱状图、
 * 模式/模型分布、试题模式 vs 自由模式对比、Top 榜单快捷入口。
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */

require_once(__DIR__ . '/../../config.php');

defined('MOODLE_INTERNAL') || die();

require_login();

$pageinfo = \local_ai_programming_assistant\helper::init_stats_page(
    'overview_page_title',
    '/local/ai_programming_assistant/overview.php'
);
$context = $pageinfo['context'];
require_capability('local/ai_programming_assistant:viewstats', $context);
$starttime = $pageinfo['starttime'];
$endtime = $pageinfo['endtime'];
$starttime_display = $pageinfo['starttime_display'];
$endtime_display = $pageinfo['endtime_display'];
$PAGE->set_pagelayout('standard');

$courseid = optional_param('courseid', 0, PARAM_INT);

$statsservice = \local_ai_programming_assistant\stats_service::get_instance();

// 课程列表（复用 stats.php 的提取模式：从有使用记录的 quiz 反推唯一课程）
$quizzes = $statsservice->get_quizzes_with_usage();
$courses = [];
foreach ($quizzes as $q) {
    $cid = $q->courseid;
    if (!isset($courses[$cid])) {
        $courses[$cid] = (object)[
            'id' => $cid,
            'fullname' => $q->coursename,
            'isselected' => ($courseid > 0 && $cid === $courseid),
        ];
    }
}
usort($courses, fn($a, $b) => strcmp($a->fullname, $b->fullname));
$has_courses = !empty($courses);

// 总揽统计（课程筛选时仅统计该课程下 quiz 的会话）
$overviewstats = $statsservice->get_overview_stats($starttime, $endtime, $courseid);

// Top 5 榜单：未选课程时展示全局 Top；选了课程时按 courseid 过滤该课程范围。
// get_top_questions / get_student_statistics 已支持 courseid 过滤（利用 conversation.courseid 索引）。
// get_recent_free_chats_grouped 暂不支持 courseid，自由模式会话天然跨课程，保持全局视图。
$topquestions = $statsservice->get_top_questions(5, $starttime, $endtime, courseid: $courseid);
$topstudents = $statsservice->get_student_statistics(5, $starttime, $endtime, courseid: $courseid);
$topfreepages = $statsservice->get_recent_free_chats_grouped($starttime, $endtime, 5);

$hasdata = !empty($overviewstats['total_requests']) && $overviewstats['total_requests'] > 0;

// 试题模式 vs 自由模式对比百分比
$quizmodecount = (int)($overviewstats['quiz_mode_count'] ?? 0);
$freemodecount = (int)($overviewstats['free_mode_count'] ?? 0);
$modetotal = $quizmodecount + $freemodecount;
$quizmodepercent = $modetotal > 0 ? round($quizmodecount / $modetotal * 100, 1) : 0;
$freemodepercent = $modetotal > 0 ? round($freemodecount / $modetotal * 100, 1) : 0;

$templatecontext = [
    'stats' => $overviewstats,
    'wwwroot' => $CFG->wwwroot,
    'hasdata' => $hasdata,

    // 导航标签
    'nav_overview' => true,

    'starttime' => $starttime,
    'endtime' => $endtime,
    'starttime_display' => $starttime_display,
    'endtime_display' => $endtime_display,
    'courseid' => $courseid,
    'courses' => $courses,
    'has_courses' => $has_courses,
    'presets' => \local_ai_programming_assistant\helper::get_date_presets($starttime, $endtime),

    // 试题 vs 自由模式对比
    'quiz_mode_count' => $quizmodecount,
    'free_mode_count' => $freemodecount,
    'quiz_mode_percent' => $quizmodepercent,
    'free_mode_percent' => $freemodepercent,
    'has_mode_split' => $modetotal > 0,

    // 分布
    'has_modelusage' => !empty($overviewstats['model_usage']),
    'has_modeusage' => !empty($overviewstats['mode_usage']),

    // Top 榜单
    'topquestions' => $topquestions,
    'has_topquestions' => !empty($topquestions),
    'topstudents' => $topstudents,
    'has_topstudents' => !empty($topstudents),
    'topfreepages' => $topfreepages,
    'has_topfreepages' => !empty($topfreepages),

    // 快捷入口 URL
    'statsurl' => (new moodle_url('/local/ai_programming_assistant/stats.php', [
        'starttime' => $starttime_display, 'endtime' => $endtime_display,
    ]))->out(),
    'freestatsurl' => (new moodle_url('/local/ai_programming_assistant/free_stats.php', [
        'starttime' => $starttime_display, 'endtime' => $endtime_display,
    ]))->out(),
    'recentquizchatsurl' => (new moodle_url('/local/ai_programming_assistant/recent_quiz_chats.php', [
        'starttime' => $starttime_display, 'endtime' => $endtime_display,
    ]))->out(),
    'recentfreechatsurl' => (new moodle_url('/local/ai_programming_assistant/recent_free_chats.php', [
        'starttime' => $starttime_display, 'endtime' => $endtime_display,
    ]))->out(),
    'conversationlisturl' => (new moodle_url('/local/ai_programming_assistant/conversation_list.php', [
        'starttime' => $starttime_display, 'endtime' => $endtime_display,
    ]))->out(),
] + \local_ai_programming_assistant\helper::build_nav_urls($starttime_display, $endtime_display);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_programming_assistant/overview_page', $templatecontext);
echo $OUTPUT->footer();
