<?php
/**
 * AI 编程助手 - 会话详情页（二级页面）
 *
 * 显示指定会话的详细信息，包括会话概览和所有消息记录。
 * 消息按时间从早到晚顺序显示，学生消息和 AI 回复交替展示。
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */

require_once(__DIR__ . '/../../config.php');

defined('MOODLE_INTERNAL') || die();

$conversationid = optional_param('id', 0, PARAM_INT);
$questionid = optional_param('questionid', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$quizid = optional_param('quizid', 0, PARAM_INT);
$actiontype = optional_param('actiontype', '', PARAM_ALPHANUMEXT);
$starttime = optional_param('starttime', '', PARAM_TEXT);
$endtime = optional_param('endtime', '', PARAM_TEXT);

$context = context_system::instance();
require_login();
require_capability('local/ai_programming_assistant:viewstats', $context);

$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->navbar->add(
    get_string('stats_page_title', 'local_ai_programming_assistant'),
    new moodle_url('/local/ai_programming_assistant/stats.php', [
        'starttime' => $starttime,
        'endtime' => $endtime,
    ])
);

$statsservice = \local_ai_programming_assistant\stats_service::get_instance();

// 解析日期范围（用于"自动选择"模式按时间范围筛选会话）
$daterange = \local_ai_programming_assistant\helper::resolve_date_range($starttime, $endtime);
$starttimestamp = $daterange['starttimestamp'];
$endtimestamp = $daterange['endtimestamp'];
$starttime_display = $daterange['starttime_display'];
$endtime_display = $daterange['endtime_display'];

/**
 * 构造会话筛选参数（用于 auto 选择模式、prev/next 导航、返回列表链接）
 */
$filterparams = [
    'questionid' => $questionid > 0 ? $questionid : null,
    'userid'     => $userid > 0 ? $userid : null,
    'quizid'     => $quizid > 0 ? $quizid : null,
    'actiontype' => $actiontype !== '' ? $actiontype : null,
];

// 若未直接指定 id，则基于 questionid+userid（+actiontype+quizid+时间）自动选择最早的匹配会话
$autoconvlist = null;
$autoconvindex = null;
if ($conversationid <= 0) {
    if ($questionid <= 0 || $userid <= 0) {
        print_error('invalid_parameters', 'local_ai_programming_assistant');
    }
    // 取同一 (student, question, mode, time) 范围内的全部会话（按时间升序），用于：
    //   1) 挑出"当前"会话（第一条）  2) 渲染 prev/next 链接与计数器
    $autoconvlist = $statsservice->get_filtered_conversations(
        $filterparams['questionid'], $filterparams['userid'], $filterparams['quizid'],
        $filterparams['actiontype'], null, $starttimestamp, $endtimestamp,
        500, 0, '', 'ASC'
    );
    if (empty($autoconvlist)) {
        print_error('invalid_parameters', 'local_ai_programming_assistant');
    }
    $conversationid = (int)$autoconvlist[0]['id'];
    $autoconvindex = 0;
}

$PAGE->set_url('/local/ai_programming_assistant/conversation_detail.php', array_filter([
    'id' => $conversationid,
    'questionid' => $questionid > 0 ? $questionid : null,
    'userid' => $userid > 0 ? $userid : null,
    'quizid' => $quizid > 0 ? $quizid : null,
    'actiontype' => $actiontype !== '' ? $actiontype : null,
    'starttime' => $starttime,
    'endtime' => $endtime,
]));
$PAGE->set_title(get_string('conversation_detail_page_title', 'local_ai_programming_assistant'));
$PAGE->set_heading(get_string('conversation_detail_page_title', 'local_ai_programming_assistant'));

// "返回会话列表"导航链接 — 保留筛选参数
$listurlparams = ['starttime' => $starttime_display, 'endtime' => $endtime_display];
foreach (['questionid' => 'questionid', 'userid' => 'userid', 'quizid' => 'quizid', 'actiontype' => 'actiontype'] as $k => $p) {
    if (!empty($$p)) {
        $listurlparams[$k] = $$p;
    }
}
$listurlparams['order'] = 'asc';
$conversationlisturl = new moodle_url('/local/ai_programming_assistant/conversation_list.php', $listurlparams);
$PAGE->navbar->add(get_string('conversation_list_page_title', 'local_ai_programming_assistant'), $conversationlisturl);
$PAGE->navbar->add(get_string('conversation_detail_page_title', 'local_ai_programming_assistant'));

$conversation = $statsservice->get_conversation($conversationid);
if (!$conversation) {
    print_error('invalid_parameters', 'local_ai_programming_assistant');
}

$messages = $statsservice->get_conversation_messages($conversationid);

// prev/next 导航：若会话是通过 auto 筛选命中（或即使传 id 也计算相邻，只要有筛选条件可用）
$prevconvurl = null;
$nextconvurl = null;
$convcounter = null;
$canshowprevnext = !empty($filterparams['questionid']) && !empty($filterparams['userid']);
if ($canshowprevnext) {
    if ($autoconvlist === null) {
        $autoconvlist = $statsservice->get_filtered_conversations(
            $filterparams['questionid'], $filterparams['userid'], $filterparams['quizid'],
            $filterparams['actiontype'], null, $starttimestamp, $endtimestamp,
            500, 0, '', 'ASC'
        );
    }
    $ids = array_map(function ($c) { return (int)$c['id']; }, $autoconvlist);
    $idx = array_search($conversationid, $ids, true);
    if ($idx !== false) {
        $autoconvindex = $idx;
        $convcounter = (object)[
            'current' => $idx + 1,
            'total'   => count($ids),
        ];
        $navbase = array_filter([
            'questionid' => $questionid > 0 ? $questionid : null,
            'userid'     => $userid > 0 ? $userid : null,
            'quizid'     => $quizid > 0 ? $quizid : null,
            'actiontype' => $actiontype !== '' ? $actiontype : null,
            'starttime'  => $starttime_display,
            'endtime'    => $endtime_display,
        ]);
        if ($idx > 0) {
            $p = $navbase + ['id' => $ids[$idx - 1]];
            $prevconvurl = (new moodle_url('/local/ai_programming_assistant/conversation_detail.php', $p))->out();
        }
        if ($idx < count($ids) - 1) {
            $p = $navbase + ['id' => $ids[$idx + 1]];
            $nextconvurl = (new moodle_url('/local/ai_programming_assistant/conversation_detail.php', $p))->out();
        }
    }
}

// 渲染 AI 消息内容（Markdown → HTML）
foreach ($messages as &$msg) {
    if (($msg['role'] ?? '') === 'assistant' && !empty($msg['content'])) {
        $msg['content'] = \local_ai_programming_assistant\helper::render_ai_response($msg['content']);
    }
}
unset($msg);

// 使用 HTTP_REFERER 返回来源页面，仅限本插件内部 URL，否则回退到会话列表
$referer = get_local_referer(false);
if ($referer && strpos($referer, '/local/ai_programming_assistant/') !== false) {
    $backurl = $referer;
} else {
    $backurl = (new moodle_url('/local/ai_programming_assistant/conversation_list.php', [
        'starttime' => $starttime,
        'endtime' => $endtime,
    ]))->out();
}

// 构建"返回测验"链接
$quizurl = null;
$hasquiz = false;
if (!empty($conversation['quizid']) && $conversation['quizid'] > 0) {
    if (!empty($conversation['cmid']) && $conversation['cmid'] > 0) {
        $quizurl = new moodle_url('/mod/quiz/view.php', ['id' => $conversation['cmid']]);
        $hasquiz = true;
    } else {
        // 尝试通过 quizid 获取 cmid
        $cm = get_coursemodule_from_instance('quiz', $conversation['quizid'], $conversation['courseid'] ?: 0);
        if ($cm) {
            $quizurl = new moodle_url('/mod/quiz/view.php', ['id' => $cm->id]);
            $hasquiz = true;
        }
    }
}

$templatecontext = [
    'conversation' => $conversation,
    'messages' => $messages,
    'hasdata' => !empty($messages),
    'totalmessages' => count($messages),
    'backurl' => $backurl,
    'hasquiz' => $hasquiz,
    'quizurl' => $quizurl ? $quizurl->out() : '',
    'has_coursename' => !empty($conversation['coursename']),
    // 会话筛选导航
    'conversationlisturl' => isset($conversationlisturl) ? $conversationlisturl->out() : null,
    'has_convcounter' => $convcounter !== null,
    'conv_current' => $convcounter ? $convcounter->current : null,
    'conv_total'   => $convcounter ? $convcounter->total   : null,
    'prevconvurl'  => $prevconvurl,
    'nextconvurl'  => $nextconvurl,
];

\local_ai_programming_assistant\helper::init_code_highlight($PAGE);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ai_programming_assistant/conversation_detail', $templatecontext);
echo $OUTPUT->footer();