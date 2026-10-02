<?php
/**
 * AI 编程助手 - 会话列表页（一级页面）
 *
 * 展示所有 AI 会话记录，按时间从近到远排列。
 * 支持时间范围筛选、试题筛选、学生筛选、测验筛选、分页。
 * 点击某条记录可跳转到 conversation_detail.php 查看会话详情。
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
$actiontype = optional_param('actiontype', '', PARAM_ALPHANUMEXT);
$contextid = optional_param('contextid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);
// 排序方向：asc=从早到晚，desc=从晚到早（默认）
$order = strtolower(optional_param('order', 'desc', PARAM_ALPHA));
$order = $order === 'asc' ? 'asc' : 'desc';

$urlparams = [
    'questionid' => $questionid,
    'userid' => $userid,
    'quizid' => $quizid,
    'actiontype' => $actiontype,
    'contextid' => $contextid,
    'courseid' => $courseid,
];
if ($order === 'asc') {
    $urlparams['order'] = $order;
}

$pageinfo = \local_ai_programming_assistant\helper::init_stats_page(
    'conversation_list_page_title',
    '/local/ai_programming_assistant/conversation_list.php',
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

// 构建删除后重定向 URL（保留筛选与分页参数）
$delete_redirect_params = [
    'starttime' => $starttime_display,
    'endtime' => $endtime_display,
    'perpage' => $perpage,
    'page' => $page,
    'questionid' => $questionid,
    'userid' => $userid,
    'quizid' => $quizid,
    'actiontype' => $actiontype,
    'contextid' => $contextid,
    'courseid' => $courseid,
    'search' => $search,
];
if ($order === 'asc') {
    $delete_redirect_params['order'] = $order;
}

// 处理会话删除
$action = optional_param('action', '', PARAM_ALPHA);
if ($action === 'delete' && confirm_sesskey()) {
    $conversationids = required_param('conversationids', PARAM_SEQUENCE);
    $ids = array_filter(array_map('intval', explode(',', $conversationids)), function($id) { return $id > 0; });
    if (!empty($ids)) {
        $deleted = $statsservice->delete_conversations($ids);
        \core\notification::success(get_string('conversation_delete_success', 'local_ai_programming_assistant', $deleted));
    }
    redirect(new moodle_url('/local/ai_programming_assistant/conversation_list.php', $delete_redirect_params));
}

// 解析筛选条件用于显示
$questionname = '';
$studentname = '';
$quizname = '';

if ($questionid > 0) {
    $qinfo = $statsservice->get_question_info($questionid);
    $questionname = $qinfo ? $qinfo->name : get_string('unknown_question_id', 'local_ai_programming_assistant', $questionid);
}

if ($userid > 0) {
    $userinfo = \local_ai_programming_assistant\helper::get_user_display_info($userid);
    $studentname = $userinfo['name'];
}

if ($quizid > 0) {
    $qinfo = $statsservice->get_quiz_info($quizid);
    $quizname = $qinfo ? $qinfo->name : get_string('unknown_quiz_id', 'local_ai_programming_assistant', $quizid);
}

// 构建筛选参数（仅传有效值）
$filter_questionid = $questionid > 0 ? $questionid : null;
$filter_userid = $userid > 0 ? $userid : null;
$filter_quizid = $quizid > 0 ? $quizid : null;
$filter_actiontype = $actiontype !== '' ? $actiontype : null;
$filter_contextid = $contextid > 0 ? $contextid : null;
$filter_courseid = $courseid > 0 ? $courseid : null;

// 获取会话总数（先查询总数以便限制页码范围）
$totalrecords = $statsservice->count_filtered_conversations(
    $filter_questionid, $filter_userid, $filter_quizid, $filter_actiontype, $filter_contextid,
    $starttime, $endtime, $search, $filter_courseid
);

// 限制页码范围（删除会话后当前页可能超出总页数）
$totalpages = max(1, (int)ceil($totalrecords / $perpage));
if ($page > $totalpages) {
    $page = $totalpages;
    $offset = ($page - 1) * $perpage;
}

// 获取会话列表
$conversations = $statsservice->get_filtered_conversations(
    $filter_questionid, $filter_userid, $filter_quizid, $filter_actiontype, $filter_contextid,
    $starttime, $endtime, $perpage, $offset, $search, strtoupper($order), $filter_courseid
);

// 为每条记录添加详情页 URL
foreach ($conversations as &$conv) {
    $conv['detailurl'] = new moodle_url('/local/ai_programming_assistant/conversation_detail.php', [
        'id' => $conv['id'],
        'starttime' => $starttime_display,
        'endtime' => $endtime_display,
    ]);
}
unset($conv);

// 构建筛选 URL 参数片段（用于分页链接）
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
if ($actiontype !== '') {
    $filterurlparams .= '&amp;actiontype=' . $actiontype;
}
if ($contextid > 0) {
    $filterurlparams .= '&amp;contextid=' . $contextid;
}
if ($courseid > 0) {
    $filterurlparams .= '&amp;courseid=' . $courseid;
}
if ($search !== '') {
    $filterurlparams .= '&amp;search=' . urlencode($search);
}
if ($order === 'asc') {
    $filterurlparams .= '&amp;order=asc';
}

$perpageoptionlist = [];
foreach ($perpageoptions as $opt) {
    $perpageoptionlist[] = ['value' => $opt, 'selected' => $opt === $perpage];
}

// 是否有筛选条件
$has_question_filter = $questionid > 0;
$has_user_filter = $userid > 0;
$has_quiz_filter = $quizid > 0;
$has_actiontype_filter = $actiontype !== '';
$has_contextid_filter = $contextid > 0;
$has_courseid_filter = $courseid > 0;
$has_filter = $has_question_filter || $has_user_filter || $has_quiz_filter || $has_actiontype_filter || $has_contextid_filter || $has_courseid_filter;

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
    'conversations' => $conversations,
    'hasdata' => !empty($conversations),
    'totalrecords' => $totalrecords,
    'backtostatsurl' => $backurl,
    'recentquizchatsurl' => (new moodle_url('/local/ai_programming_assistant/recent_quiz_chats.php', [
        'starttime' => $starttime_display,
        'endtime' => $endtime_display,
    ]))->out(),
    'recentfreechatsurl' => (new moodle_url('/local/ai_programming_assistant/recent_free_chats.php', [
        'starttime' => $starttime_display,
        'endtime' => $endtime_display,
    ]))->out(),

    // 导航标签
    'nav_conversation_list' => true,

    'presets' => \local_ai_programming_assistant\helper::get_date_presets($starttime, $endtime),
    'filterurlparams' => $filterurlparams,
    'has_filter' => $has_filter || $search !== '',
    'has_question_filter' => $has_question_filter,
    'has_user_filter' => $has_user_filter,
    'has_quiz_filter' => $has_quiz_filter,
    'has_actiontype_filter' => $has_actiontype_filter,
    'has_contextid_filter' => $has_contextid_filter,
    'actiontype' => $actiontype,
    'actiontype_label' => $has_actiontype_filter ? \local_ai_programming_assistant\helper::get_mode_label($actiontype) : '',
    'contextid' => $contextid,
    'questionid' => $questionid,
    'questionname' => $questionname,
    'userid' => $userid,
    'studentname' => $studentname,
    'quizid' => $quizid,
    'quizname' => $quizname,
    'search' => $search,
    'order' => $order,
    'has_order_asc' => $order === 'asc',

    // 会话删除
    'delete_action_url' => (new moodle_url('/local/ai_programming_assistant/conversation_list.php', $delete_redirect_params))->out(),
    'confirm_delete_msg_json' => json_encode(get_string('conversation_confirm_delete', 'local_ai_programming_assistant', '__COUNT__')),
];

$templatecontext = array_merge(
    $templatecontext,
    \local_ai_programming_assistant\helper::build_pagination_context($page, $perpage, $totalrecords),
    \local_ai_programming_assistant\helper::build_nav_urls($starttime_display, $endtime_display)
);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_programming_assistant/conversation_list', $templatecontext);
echo $OUTPUT->footer();