<?php
/**
 * AI 编程助手 - 库文件
 *
 * @author zfl
 * @package    local_ai_programming_assistant
 */
defined('MOODLE_INTERNAL') || die();

/**
 * 检查用户是否有查看统计的权限
 *
 * 先检查系统级权限，再按需检查模块级权限。
 * 避免在多个导航扩展函数中重复相同的权限检查逻辑。
 *
 * @param \context|null $modcontext 模块上下文（可选，仅在系统级无权限时使用）
 * @return bool 是否有查看统计的权限
 */
function local_ai_programming_assistant_can_view_stats(?\context $modcontext = null): bool {
    $syscontext = \context_system::instance();
    if (has_capability('local/ai_programming_assistant:viewstats', $syscontext)) {
        return true;
    }
    if ($modcontext !== null) {
        return has_capability('local/ai_programming_assistant:viewstats', $modcontext);
    }
    return false;
}

/**
 * 在系统导航中添加统计页面入口
 * 以及在当前测试页面添加上下文相关的 AI 统计链接
 */
function local_ai_programming_assistant_extend_navigation($navigation) {
    global $PAGE;

    $canviewglobal = local_ai_programming_assistant_can_view_stats();

    $cankbmanage = has_capability('local/ai_programming_assistant:config', \context_system::instance());
    if ($cankbmanage) {
        $navigation->add(
            get_string('knowledgebase_manage_title', 'local_ai_programming_assistant'),
            new moodle_url('/local/ai_programming_assistant/kb_manage.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'ai_programming_assistant_kb_manage',
            new pix_icon('i/files', '')
        );
    }

    if ($canviewglobal) {
        $navigation->add(
            get_string('overview_page_title', 'local_ai_programming_assistant'),
            new moodle_url('/local/ai_programming_assistant/overview.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'ai_programming_assistant_overview',
            new pix_icon('i/dashboard', '')
        );
        $navigation->add(
            get_string('stats_page_title', 'local_ai_programming_assistant'),
            new moodle_url('/local/ai_programming_assistant/stats.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'ai_programming_assistant_stats',
            new pix_icon('i/report', '')
        );
        $navigation->add(
            get_string('free_stats_page_title', 'local_ai_programming_assistant'),
            new moodle_url('/local/ai_programming_assistant/free_stats.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'ai_programming_assistant_free_stats',
            new pix_icon('i/report', '')
        );
        $navigation->add(
            get_string('recent_free_chats_page_title', 'local_ai_programming_assistant'),
            new moodle_url('/local/ai_programming_assistant/recent_free_chats.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'ai_programming_assistant_recent_free_chats',
            new pix_icon('i/report', '')
        );
        $navigation->add(
            get_string('recent_quiz_chats_page_title', 'local_ai_programming_assistant'),
            new moodle_url('/local/ai_programming_assistant/recent_quiz_chats.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'ai_programming_assistant_recent_quiz_chats',
            new pix_icon('i/report', '')
        );
        $navigation->add(
            get_string('conversation_list_page_title', 'local_ai_programming_assistant'),
            new moodle_url('/local/ai_programming_assistant/conversation_list.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'ai_programming_assistant_conversation_list',
            new pix_icon('i/report', '')
        );
        $navigation->add(
            get_string('asked_questions_page_title', 'local_ai_programming_assistant'),
            new moodle_url('/local/ai_programming_assistant/asked_questions.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'ai_programming_assistant_asked_questions',
            new pix_icon('i/report', '')
        );
    }

    if ($PAGE->context && $PAGE->context->contextlevel === CONTEXT_MODULE) {
        $cm = get_coursemodule_from_id('quiz', $PAGE->context->instanceid, 0, false, IGNORE_MISSING);
        if ($cm && $cm->modname === 'quiz') {
            $quizurl = new moodle_url('/local/ai_programming_assistant/quiz_stats.php', [
                'quizid' => $cm->instance,
                'cmid' => $cm->id,
            ]);
            if (!$canviewglobal && !local_ai_programming_assistant_can_view_stats(\context_module::instance($cm->id))) {
                return;
            }
            $navigation->add(
                get_string('nav_quiz_stats_inline', 'local_ai_programming_assistant') . ' - ' . $cm->name,
                $quizurl,
                navigation_node::TYPE_CUSTOM,
                null,
                'ai_programming_assistant_quiz_current',
                new pix_icon('i/stats', '')
            );
        }
    }
}

/**
 * 在课程模块导航中添加当前测试的 AI 统计入口
 * 在查看测试页面时，在导航区块的"活动"下显示
 */
function local_ai_programming_assistant_extend_navigation_module($navigation, $cm, $course, $module) {
    if ($module->name !== 'quiz') {
        return;
    }

    if (!local_ai_programming_assistant_can_view_stats(\context_module::instance($cm->id))) {
        return;
    }

    $url = new moodle_url('/local/ai_programming_assistant/quiz_stats.php', [
        'quizid' => $cm->instance,
        'cmid' => $cm->id,
    ]);
    $navigation->add(
        get_string('nav_quiz_stats_inline', 'local_ai_programming_assistant'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'ai_programming_assistant_quiz_nav',
        new pix_icon('i/stats', '')
    );
}

/**
 * 在设置导航/操作菜单中添加当前测试的 AI 统计入口
 * 出现在测试页面右上角的"操作"菜单中
 */
function local_ai_programming_assistant_extend_settings_navigation($settingsnav, $context) {
    if ($context->contextlevel !== CONTEXT_MODULE) {
        return;
    }
    $cm = get_coursemodule_from_id('quiz', $context->instanceid, 0, false, false);
    if (!$cm || $cm->modname !== 'quiz') {
        return;
    }

    if (!local_ai_programming_assistant_can_view_stats($context)) {
        return;
    }

    $url = new moodle_url('/local/ai_programming_assistant/quiz_stats.php', [
        'quizid' => $cm->instance,
        'cmid' => $cm->id,
    ]);

    $quiznode = $settingsnav->find('mod_quiz_quiz_administration', navigation_node::TYPE_SETTING);
    if (!$quiznode) {
        $quiznode = $settingsnav->find('modulesettings', navigation_node::TYPE_SETTING);
    }
    if ($quiznode) {
        $quiznode->add(
            get_string('nav_quiz_stats_inline', 'local_ai_programming_assistant'),
            $url,
            navigation_node::TYPE_SETTING,
            null,
            'ai_programming_assistant_quiz_settings',
            new pix_icon('i/stats', '')
        );
    } else {
        $settingsnav->add(
            get_string('nav_quiz_stats_inline', 'local_ai_programming_assistant'),
            $url,
            navigation_node::TYPE_SETTING,
            null,
            'ai_programming_assistant_quiz_settings',
            new pix_icon('i/stats', '')
        );
    }
}

/**
 * 检查用户是否拥有查看知识库的权限
 *
 * viewknowledgebase 能力在 db/access.php 中由学生/教师/管理员 archetype 默认拥有，
 * 但这些角色通常只分配在课程上下文。系统上下文的 has_capability 检查只会考虑
 * 系统上下文链上的角色分配，会误拒绝仅拥有课程角色分配的学生（403）。
 * 因此回退遍历用户全部角色分配上下文，只要任一上下文拥有该能力即放行；
 * 游客无角色分配，仍会被正确拒绝。
 *
 * @param int|null $userid 用户 ID，默认当前用户
 * @return bool
 */
function local_ai_programming_assistant_has_view_knowledgebase_capability(?int $userid = null): bool {
    global $USER, $DB;
    $userid = $userid ?? (int)$USER->id;
    $cap = 'local/ai_programming_assistant:viewknowledgebase';
    $syscontext = \context_system::instance();
    if (has_capability($cap, $syscontext, $userid)) {
        return true;
    }
    // 回退：遍历用户全部角色分配，检查任意上下文是否拥有该能力
    $assignments = $DB->get_records('role_assignments', ['userid' => $userid], '', 'contextid');
    foreach ($assignments as $ra) {
        $context = \context::instance_by_id($ra->contextid, IGNORE_MISSING);
        if ($context && has_capability($cap, $context, $userid)) {
            return true;
        }
    }
    return false;
}

/**
 * 提供知识库原始文件下载服务
 *
 * 处理 /pluginfile.php/{contextid}/local_ai_programming_assistant/knowledgebase/{itemid}/{filename}
 * 格式的 URL 请求，返回存储在 Moodle 文件 API 中的知识库原始文件。
 *
 * @param \stdClass $course 课程对象
 * @param \stdClass $cm 课程模块对象
 * @param \context $context 上下文对象
 * @param string $filearea 文件域
 * @param array $args URL 参数数组
 * @param bool $forcedownload 是否强制下载
 * @param array $options 额外选项
 * @return void
 */
function local_ai_programming_assistant_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []): void {
    // 仅处理知识库文件域
    if ($filearea !== 'knowledgebase') {
        send_file_not_found();
    }

    require_login();

    // 知识库文件存储在系统上下文，且查看需具备 viewknowledgebase 权限。
    // 原实现仅 require_login() 无权能检查，导致任何已登录用户均可通过构造
    // pluginfile URL 下载知识库教材文件（可能含受版权保护的教材内容）。
    // 注意：不能直接在系统上下文 require_capability——学生/教师角色通常只分配在
    // 课程上下文，系统上下文检查会误拒绝学生（403），需使用兼容回退的辅助函数。
    if (!local_ai_programming_assistant_has_view_knowledgebase_capability()) {
        send_file_not_found();
    }

    // 知识库文件仅存储在系统上下文，拒绝其他上下文的请求
    if ($context->id !== \context_system::instance()->id) {
        send_file_not_found();
    }

    // 解析 URL 参数：itemid + filename
    $itemid = (int)array_shift($args);
    $filename = array_pop($args);

    // 验证 itemid 对应的知识库文件存在
    if (!\local_ai_programming_assistant\kb_service::get_file($itemid)) {
        send_file_not_found();
    }

    $fs = get_file_storage();
    $file = $fs->get_file(
        $context->id,
        'local_ai_programming_assistant',
        $filearea,
        $itemid,
        '/',
        $filename
    );

    if (!$file) {
        send_file_not_found();
    }

    send_stored_file($file, null, 0, $forcedownload, $options);
}