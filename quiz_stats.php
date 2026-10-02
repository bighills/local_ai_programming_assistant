<?php
/**
 * AI 编程助手 - 测验统计页面
 *
 * 按试题维度展示测验中 AI 助手的使用统计数据。
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */

require_once(__DIR__ . '/../../config.php');

defined('MOODLE_INTERNAL') || die();

$quizid = optional_param('quizid', 0, PARAM_INT);
$cmid = optional_param('cmid', 0, PARAM_INT);
$starttime = optional_param('starttime', '', PARAM_TEXT);
$endtime = optional_param('endtime', '', PARAM_TEXT);
$tab = optional_param('tab', 'overview', PARAM_ALPHAEXT);
$groupid = optional_param('groupid', 0, PARAM_INT);
$qpage = optional_param('qpage', 1, PARAM_INT);
$fqpage = optional_param('fqpage', 1, PARAM_INT);
$fspage = optional_param('fspage', 1, PARAM_INT);
// 下界保护：page=0/负数会产生负 offset，被 Moodle DML 钳制但会输出
// "Negative limitfrom parameter detected" 调试日志噪音
$qpage = max(1, $qpage);
$fqpage = max(1, $fqpage);
$fspage = max(1, $fspage);
$filterquestionid = optional_param('filter_questionid', 0, PARAM_INT);
$filteruserid = optional_param('filter_userid', 0, PARAM_INT);
$filteractiontype = optional_param('filter_actiontype', '', PARAM_ALPHAEXT);
$perpage = 10;
$export = optional_param('export', '', PARAM_ALPHA);

if ($cmid > 0) {
    $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
    $modulecontext = context_module::instance($cm->id);
    $course = get_course($cm->course);
    require_login($course, true, $cm);
    // 安全：传入 cmid 时强制绑定其对应的测验，忽略用户提供的 quizid，
    // 防止通过 cmid（自己有权访问）+ quizid（其它课程的测验）组合越权读取数据。
    $quizid = (int)$cm->instance;
    $PAGE->set_url('/local/ai_programming_assistant/quiz_stats.php', ['cmid' => $cmid, 'quizid' => $quizid, 'tab' => $tab, 'groupid' => $groupid]);
    $PAGE->set_context($modulecontext);
    $PAGE->set_course($course);
    $PAGE->set_heading($course->fullname);
    $PAGE->navbar->add(get_string('quiz_stats_page_title', 'local_ai_programming_assistant'));
} else {
    $modulecontext = null;
    require_login();
    $PAGE->set_url('/local/ai_programming_assistant/quiz_stats.php', ['quizid' => $quizid, 'tab' => $tab, 'groupid' => $groupid]);
    $PAGE->set_context(context_system::instance());
}

$statsservice = \local_ai_programming_assistant\stats_service::get_instance();

if ($cmid > 0) {
    require_capability('local/ai_programming_assistant:viewstats', $modulecontext);
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

$PAGE->set_title(get_string('quiz_stats_page_title', 'local_ai_programming_assistant'));
$PAGE->set_pagelayout('standard');

$daterange = \local_ai_programming_assistant\helper::resolve_date_range($starttime, $endtime, \local_ai_programming_assistant\helper::QUIZ_DEFAULT_DAYS);
$starttimestamp = $daterange['starttimestamp'];
$endtimestamp = $daterange['endtimestamp'];
$starttime_display = $daterange['starttime_display'];
$endtime_display = $daterange['endtime_display'];

$templatecontext = [
    'quizid' => $quizid,
    'cmid' => $cmid,
    'tab' => $tab,
    'starttime_display' => $starttime_display,
    'endtime_display' => $endtime_display,
    'hasquiz' => false,
    'invalidquiz' => false,
    'requireresttaken' => false,
    'showbacktoquiz' => ($cmid > 0),
    'backtoquizurl' => $cmid > 0 ? new moodle_url('/mod/quiz/view.php', ['id' => $cmid]) : '',
    'tab_overview' => true,
    'shownodata' => false,
    'has_export' => false,
];

if ($quizid > 0) {
    $quizinfo = $statsservice->get_quiz_info($quizid);
    if (!$quizinfo) {
        $templatecontext['invalidquiz'] = true;
    } else {
        $templatecontext['hasquiz'] = true;
        $templatecontext['quizname'] = $quizinfo->name;

        $courseid = $quizinfo->course;

        if ($cmid <= 0) {
            $cm = get_coursemodule_from_instance('quiz', $quizid, $courseid);
            if ($cm) {
                $cmid = $cm->id;
                $templatecontext['cmid'] = $cmid;
                $templatecontext['showbacktoquiz'] = true;
                $templatecontext['backtoquizurl'] = new moodle_url('/mod/quiz/view.php', ['id' => $cmid]);
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
        $templatecontext['groupid'] = $groupid;

        $groupuserids = [];
        if ($groupid > 0) {
            $validgroup = groups_get_group($groupid);
            if (!$validgroup || $validgroup->courseid != $courseid) {
                $groupid = 0;
            } else {
                $groupmembers = groups_get_members($groupid);
                $groupuserids = !empty($groupmembers) ? array_keys($groupmembers) : [];
            }
        }

        $questionstats = [];

        if ($tab === 'overview') {
            $totalquestions = $statsservice->count_quiz_question_stats($quizid);
            // 页码 clamp：防止构造超大页码导致 offset 溢出/空页显示异常（与 stats.php 行为一致）
            $qtotalpages = max(1, (int)ceil($totalquestions / $perpage));
            if ($qpage > $qtotalpages) {
                $qpage = $qtotalpages;
            }
            $qoffset = ($qpage - 1) * $perpage;
            $questionstats = $statsservice->get_quiz_question_stats(
                $quizid, $starttimestamp, $endtimestamp, $perpage,
                $groupid > 0 ? $groupuserids : null, $qoffset,
                'questionid', 'ASC'
            );
            $templatecontext['questionstats'] = $questionstats;

            if (!empty($questionstats)) {
                $totals = \local_ai_programming_assistant\helper::compute_quiz_stats_totals($questionstats);
                if (!empty($totals)) {
                    $templatecontext['totals'] = $totals;
                    $templatecontext['has_totals'] = true;
                }
            }

            $hassomedata = !empty($questionstats);
            if (!$hassomedata) {
                $templatecontext['requireresttaken'] = true;
            }
            $templatecontext['shownodata'] = !$hassomedata;
            $templatecontext = array_merge($templatecontext,
                \local_ai_programming_assistant\helper::build_pagination_context_prefix($qpage, $perpage, $totalquestions, 'q'));

        } else if ($tab === 'question_followups') {
            $templatecontext['tab_overview'] = false;
            $templatecontext['tab_question_followups'] = true;
            $totalfq = $statsservice->count_quiz_conversations(
                $quizid, $starttimestamp, $endtimestamp,
                $filterquestionid > 0 ? $filterquestionid : null,
                null,
                $filteractiontype !== '' ? $filteractiontype : null,
                $groupid > 0 ? $groupuserids : null
            );
            // 页码 clamp：防止构造超大页码导致 offset 溢出/空页显示异常（与 stats.php 行为一致）
            $fqtotalpages = max(1, (int)ceil($totalfq / $perpage));
            if ($fqpage > $fqtotalpages) {
                $fqpage = $fqtotalpages;
            }
            $fqoffset = ($fqpage - 1) * $perpage;
            try {
                $conversations = $statsservice->get_quiz_conversations(
                    $quizid, $starttimestamp, $endtimestamp,
                    $filterquestionid > 0 ? $filterquestionid : null,
                    null,
                    $filteractiontype !== '' ? $filteractiontype : null,
                    $groupid > 0 ? $groupuserids : null,
                    $perpage, $fqoffset
                );
                // 按试题分组
                $groupedbyquestion = [];
                foreach ($conversations as $conv) {
                    $qid = $conv['questionid'];
                    if (!isset($groupedbyquestion[$qid])) {
                        $groupedbyquestion[$qid] = [
                            'questionid' => $qid,
                            'questionname' => $conv['questionname'],
                            'conversations' => [],
                        ];
                    }
                    $conv['detailurl'] = new moodle_url('/local/ai_programming_assistant/conversation_detail.php', [
                        'id' => $conv['id'],
                        'starttime' => $starttime_display,
                        'endtime' => $endtime_display,
                    ]);
                    $groupedbyquestion[$qid]['conversations'][] = $conv;
                }
                $templatecontext['questionconversations'] = array_values($groupedbyquestion);
                $templatecontext['has_questionconversations'] = !empty($groupedbyquestion);
                $templatecontext['filter_questionid'] = $filterquestionid;
                $templatecontext['filter_actiontype'] = $filteractiontype;
                $templatecontext['has_filter'] = ($filterquestionid > 0 || $filteractiontype !== '');
            } catch (\Throwable $e) {
                debugging('[ai_programming_assistant] quiz_stats: ' . $e->getMessage(), DEBUG_DEVELOPER);
                $templatecontext['questionconversations'] = [];
                $templatecontext['has_questionconversations'] = false;
                $templatecontext['filter_questionid'] = $filterquestionid;
                $templatecontext['filter_actiontype'] = $filteractiontype;
                $templatecontext['has_filter'] = false;
            }
            $hassomedata = !empty($templatecontext['questionconversations']);
            if (!$hassomedata) {
                $templatecontext['requireresttaken'] = true;
            }
            $templatecontext['shownodata'] = !$hassomedata;
            $templatecontext = array_merge($templatecontext,
                \local_ai_programming_assistant\helper::build_pagination_context_prefix($fqpage, $perpage, $totalfq, 'fq'));

        } else if ($tab === 'student_followups') {
            $templatecontext['tab_overview'] = false;
            $templatecontext['tab_student_followups'] = true;
            $totalfs = $statsservice->count_quiz_conversations(
                $quizid, $starttimestamp, $endtimestamp,
                null,
                $filteruserid > 0 ? $filteruserid : null,
                null,
                $groupid > 0 ? $groupuserids : null
            );
            // 页码 clamp：防止构造超大页码导致 offset 溢出/空页显示异常（与 stats.php 行为一致）
            $fstotalpages = max(1, (int)ceil($totalfs / $perpage));
            if ($fspage > $fstotalpages) {
                $fspage = $fstotalpages;
            }
            $fsoffset = ($fspage - 1) * $perpage;
            try {
                $conversations = $statsservice->get_quiz_conversations(
                    $quizid, $starttimestamp, $endtimestamp,
                    null,
                    $filteruserid > 0 ? $filteruserid : null,
                    null,
                    $groupid > 0 ? $groupuserids : null,
                    $perpage, $fsoffset
                );
                // 按学生分组
                $groupedbystudent = [];
                foreach ($conversations as $conv) {
                    $uid = $conv['userid'];
                    if (!isset($groupedbystudent[$uid])) {
                        $groupedbystudent[$uid] = [
                            'userid' => $uid,
                            'studentname' => $conv['studentname'],
                            'username' => $conv['username'],
                            'conversations' => [],
                        ];
                    }
                    $conv['detailurl'] = new moodle_url('/local/ai_programming_assistant/conversation_detail.php', [
                        'id' => $conv['id'],
                        'starttime' => $starttime_display,
                        'endtime' => $endtime_display,
                    ]);
                    $groupedbystudent[$uid]['conversations'][] = $conv;
                }
                $templatecontext['studentconversations'] = array_values($groupedbystudent);
                $templatecontext['has_studentconversations'] = !empty($groupedbystudent);
                $templatecontext['filter_userid'] = $filteruserid;
                $templatecontext['has_userid_filter'] = ($filteruserid > 0);
            } catch (\Throwable $e) {
                debugging('[ai_programming_assistant] quiz_stats: ' . $e->getMessage(), DEBUG_DEVELOPER);
                $templatecontext['studentconversations'] = [];
                $templatecontext['has_studentconversations'] = false;
                $templatecontext['filter_userid'] = $filteruserid;
                $templatecontext['has_userid_filter'] = false;
            }
            $hassomedata = !empty($templatecontext['studentconversations']);
            if (!$hassomedata) {
                $templatecontext['requireresttaken'] = true;
            }
            $templatecontext['shownodata'] = !$hassomedata;
            $templatecontext = array_merge($templatecontext,
                \local_ai_programming_assistant\helper::build_pagination_context_prefix($fspage, $perpage, $totalfs, 'fs'));
        }

        if ($export === 'csv') {
            // CSV 导出必须包含全部数据（非当前页）：重新查询全量记录，
            // 否则 CSV 只会导出当前分页的 10 条。与 stats.php 导出行为保持一致。
            $output = \local_ai_programming_assistant\helper::export_csv_header('quiz_stats');

            if ($tab === 'overview') {
                $exportquestions = $statsservice->get_quiz_question_stats(
                    $quizid, $starttimestamp, $endtimestamp, 5000,
                    $groupid > 0 ? $groupuserids : null, 0, 'questionid', 'ASC'
                );
                if (!empty($exportquestions)) {
                    \local_ai_programming_assistant\helper::csv_put($output, [get_string('quiz_stats_page_title', 'local_ai_programming_assistant') . ': ' . $quizinfo->name]);
                    \local_ai_programming_assistant\helper::csv_put($output, [get_string('stats_question', 'local_ai_programming_assistant'),
                        get_string('stats_question_name', 'local_ai_programming_assistant'),
                        get_string('stats_total_requests', 'local_ai_programming_assistant'),
                        get_string('stats_last_interaction', 'local_ai_programming_assistant'),
                        get_string('stats_unique_users', 'local_ai_programming_assistant'),
                    ]);
                    foreach ($exportquestions as $qs) {
                        \local_ai_programming_assistant\helper::csv_put($output, [$qs['questionid'], $qs['questionname'],
                            $qs['total_requests'], $qs['last_timecreated_str'] ?? '',
                            $qs['unique_users']]);
                    }
                }
            } else if ($tab === 'question_followups') {
                $exportconversations = $statsservice->get_quiz_conversations(
                    $quizid, $starttimestamp, $endtimestamp,
                    $filterquestionid > 0 ? $filterquestionid : null,
                    null,
                    $filteractiontype !== '' ? $filteractiontype : null,
                    $groupid > 0 ? $groupuserids : null,
                    5000, 0
                );
                // 按试题分组（与页面展示逻辑一致）
                $exportgrouped = [];
                foreach ($exportconversations as $conv) {
                    $qid = $conv['questionid'];
                    if (!isset($exportgrouped[$qid])) {
                        $exportgrouped[$qid] = [
                            'questionid' => $qid,
                            'questionname' => $conv['questionname'],
                            'conversations' => [],
                        ];
                    }
                    $exportgrouped[$qid]['conversations'][] = $conv;
                }
                if (!empty($exportgrouped)) {
                    \local_ai_programming_assistant\helper::csv_put($output, [get_string('quiz_stats_page_title', 'local_ai_programming_assistant') . ': ' . $quizinfo->name . ' - ' . get_string('quiz_stats_tab_question_followups', 'local_ai_programming_assistant')]);
                    \local_ai_programming_assistant\helper::csv_put($output, ['#', get_string('stats_question_name', 'local_ai_programming_assistant'),
                        get_string('stats_user', 'local_ai_programming_assistant'),
                        get_string('recent_quiz_chats_mode', 'local_ai_programming_assistant'),
                        get_string('conversation_list_messagecount', 'local_ai_programming_assistant'),
                        get_string('stats_time', 'local_ai_programming_assistant'),
                    ]);
                    $entrynum = 1;
                    foreach ($exportgrouped as $qgroup) {
                        foreach ($qgroup['conversations'] ?? [] as $conv) {
                            \local_ai_programming_assistant\helper::csv_put($output, [
                                $entrynum++,
                                $qgroup['questionname'],
                                $conv['studentname'] . ' (' . $conv['username'] . ')',
                                $conv['actiontype_label'],
                                $conv['messagecount'],
                                $conv['starttime_str'],
                            ]);
                        }
                    }
                }
            } else if ($tab === 'student_followups') {
                $exportconversations = $statsservice->get_quiz_conversations(
                    $quizid, $starttimestamp, $endtimestamp,
                    null,
                    $filteruserid > 0 ? $filteruserid : null,
                    null,
                    $groupid > 0 ? $groupuserids : null,
                    5000, 0
                );
                // 按学生分组（与页面展示逻辑一致）
                $exportgrouped = [];
                foreach ($exportconversations as $conv) {
                    $uid = $conv['userid'];
                    if (!isset($exportgrouped[$uid])) {
                        $exportgrouped[$uid] = [
                            'userid' => $uid,
                            'studentname' => $conv['studentname'],
                            'username' => $conv['username'],
                            'conversations' => [],
                        ];
                    }
                    $exportgrouped[$uid]['conversations'][] = $conv;
                }
                if (!empty($exportgrouped)) {
                    \local_ai_programming_assistant\helper::csv_put($output, [get_string('quiz_stats_page_title', 'local_ai_programming_assistant') . ': ' . $quizinfo->name . ' - ' . get_string('quiz_stats_tab_student_followups', 'local_ai_programming_assistant')]);
                    \local_ai_programming_assistant\helper::csv_put($output, ['#', get_string('stats_user', 'local_ai_programming_assistant'),
                        get_string('stats_question_name', 'local_ai_programming_assistant'),
                        get_string('recent_quiz_chats_mode', 'local_ai_programming_assistant'),
                        get_string('conversation_list_messagecount', 'local_ai_programming_assistant'),
                        get_string('stats_time', 'local_ai_programming_assistant'),
                    ]);
                    $entrynum = 1;
                    foreach ($exportgrouped as $sgroup) {
                        foreach ($sgroup['conversations'] ?? [] as $conv) {
                            \local_ai_programming_assistant\helper::csv_put($output, [
                                $entrynum++,
                                $sgroup['studentname'] . ' (' . $sgroup['username'] . ')',
                                $conv['questionname'],
                                $conv['actiontype_label'],
                                $conv['messagecount'],
                                $conv['starttime_str'],
                            ]);
                        }
                    }
                }
            }

            \local_ai_programming_assistant\helper::finish_csv_export($output);
        }
    }
}

$templatecontext['has_export'] = (
    (!empty($questionstats)) ||
    !empty($templatecontext['questionconversations']) ||
    !empty($templatecontext['studentconversations'])
);

$baseurlparams = http_build_query(array_filter([
    'quizid' => $quizid ?: null,
    'cmid' => $cmid ?: null,
    'groupid' => $groupid ?: null,
    'starttime' => $starttime_display ?: null,
    'endtime' => $endtime_display ?: null,
    'qpage' => $qpage > 1 ? $qpage : null,
    'fqpage' => $fqpage > 1 ? $fqpage : null,
    'fspage' => $fspage > 1 ? $fspage : null,
    'filter_questionid' => $filterquestionid > 0 ? $filterquestionid : null,
    'filter_userid' => $filteruserid > 0 ? $filteruserid : null,
    'filter_actiontype' => $filteractiontype !== '' ? $filteractiontype : null,
]));
$templatecontext['baseurlparams'] = $baseurlparams;

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_programming_assistant/quiz_stats_page', $templatecontext);
echo $OUTPUT->footer();