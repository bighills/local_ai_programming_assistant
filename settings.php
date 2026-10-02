<?php
/**
 * AI 编程助手 - 管理设置页面
 *
 * 定义插件在 Moodle 管理后台的配置界面，包括：
 * - 基本设置（启用/禁用、助手名称、用户名）
 * - AI 后端配置（core_ai / ai_manager / 直接 API）
 * - 直接 API 参数（端点、密钥、模型、token 数、温度）
 * - 日志和调试选项
 * - 系统提示词和知识来源配置
 * - 各策略模式的提示词配置
 * - 输入/输出过滤规则配置
 *
 * @author zfl
 * @package    local_ai_programming_assistant
 */
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_ai_programming_assistant',
        get_string('pluginname', 'local_ai_programming_assistant'));

    $ADMIN->add('localplugins', $settings);

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_ai_programming_assistant_stats',
        get_string('stats_page_title', 'local_ai_programming_assistant'),
        new moodle_url('/local/ai_programming_assistant/stats.php'),
        'local/ai_programming_assistant:viewstats'
    ));

    // ==== 基本设置 ====
    $settings->add(new admin_setting_configcheckbox(
        'local_ai_programming_assistant/enable',
        get_string('enable', 'local_ai_programming_assistant'),
        get_string('enabledesc', 'local_ai_programming_assistant'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_programming_assistant/enable_non_quiz_pages',
        get_string('enable_non_quiz_pages', 'local_ai_programming_assistant'),
        get_string('enable_non_quiz_pages_desc', 'local_ai_programming_assistant'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_programming_assistant/enable_non_coderunner',
        get_string('enable_non_coderunner', 'local_ai_programming_assistant'),
        get_string('enable_non_coderunner_desc', 'local_ai_programming_assistant'),
        1
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_ai_programming_assistant/allowed_page_types',
        get_string('allowed_page_types', 'local_ai_programming_assistant'),
        get_string('allowed_page_types_desc', 'local_ai_programming_assistant'),
        "mod-quiz-attempt\nmod-quiz-review\nmod-quiz-summary\nmod-assign\nmod-lesson\nmod-page",
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_programming_assistant/assistantname',
        get_string('assistantname', 'local_ai_programming_assistant'),
        '',
        get_string('assistantname', 'local_ai_programming_assistant'),
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_programming_assistant/username',
        get_string('username', 'local_ai_programming_assistant'),
        '',
        get_string('username', 'local_ai_programming_assistant'),
        PARAM_TEXT
    ));

    // ==== AI 后端配置 ====
    $settings->add(new admin_setting_heading('local_ai_programming_assistant/ai_backend_heading',
        get_string('ai_backend_heading', 'local_ai_programming_assistant'),
        get_string('ai_backend_heading_desc', 'local_ai_programming_assistant')
    ));

    // 选择 AI 后端类型
    $settings->add(new admin_setting_configselect(
        'local_ai_programming_assistant/ai_backend',
        get_string('ai_backend', 'local_ai_programming_assistant'),
        get_string('ai_backend_desc', 'local_ai_programming_assistant'),
        'core_ai',
        [
            'core_ai' => get_string('ai_backend_core_ai', 'local_ai_programming_assistant'),
            'ai_manager' => get_string('ai_backend_ai_manager', 'local_ai_programming_assistant'),
            'direct' => get_string('ai_backend_direct', 'local_ai_programming_assistant'),
        ]
    ));

    // 直接 API 设置（仅当后端选择 'direct' 时使用）
    $settings->add(new admin_setting_configtext(
        'local_ai_programming_assistant/apiendpoint',
        get_string('apiendpoint', 'local_ai_programming_assistant'),
        get_string('apiendpoint_desc', 'local_ai_programming_assistant'),
        'https://api.openai.com/v1/chat/completions',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_ai_programming_assistant/apikey',
        get_string('apikey', 'local_ai_programming_assistant'),
        get_string('apikey_desc', 'local_ai_programming_assistant'),
        ''
    ));
    
    $settings->add(new admin_setting_configtext(
        'local_ai_programming_assistant/model',
        get_string('model', 'local_ai_programming_assistant'),
        get_string('model_desc', 'local_ai_programming_assistant'),
        'gpt-4o',
        PARAM_TEXT
    ));

    // 多端点配置（可视化增删改界面）
    $settings->add(new \local_ai_programming_assistant\admin_setting_endpoints(
        'local_ai_programming_assistant/apiendpoint_list',
        get_string('apiendpoint_list', 'local_ai_programming_assistant'),
        get_string('apiendpoint_list_desc', 'local_ai_programming_assistant'),
        '',
        'apiendpoint_selector',
        'apiendpoint_strategy'
    ));

    // 活动端点选择器（初始选项包含已配置的端点；运行时由 JS 动态更新）
    $selectoroptions = \local_ai_programming_assistant\config_manager::get_endpoint_selector_options();
    $settings->add(new admin_setting_configselect(
        'local_ai_programming_assistant/apiendpoint_selector',
        get_string('apiendpoint_selector', 'local_ai_programming_assistant'),
        get_string('apiendpoint_selector_desc', 'local_ai_programming_assistant'),
        '__default__',
        $selectoroptions
    ));

    // 路由策略（仅在选择轮询时使用）
    $settings->add(new admin_setting_configselect(
        'local_ai_programming_assistant/apiendpoint_strategy',
        get_string('apiendpoint_strategy', 'local_ai_programming_assistant'),
        get_string('apiendpoint_strategy_desc', 'local_ai_programming_assistant'),
        'roundrobin',
        [
            'roundrobin' => get_string('apiendpoint_strategy_roundrobin', 'local_ai_programming_assistant'),
            'priority' => get_string('apiendpoint_strategy_priority', 'local_ai_programming_assistant'),
        ]
    ));


    $settings->add(new admin_setting_configcheckbox(
        'local_ai_programming_assistant/enable_thinking',
        get_string('enable_thinking', 'local_ai_programming_assistant'),
        get_string('enable_thinking_desc', 'local_ai_programming_assistant'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_programming_assistant/maxtokens',
        get_string('maxtokens', 'local_ai_programming_assistant'),
        get_string('maxtokens_desc', 'local_ai_programming_assistant'),
        '2048',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_programming_assistant/temperature',
        get_string('temperature', 'local_ai_programming_assistant'),
        get_string('temperature_desc', 'local_ai_programming_assistant'),
        '0.7',
        PARAM_FLOAT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_programming_assistant/max_execution_time',
        get_string('max_execution_time', 'local_ai_programming_assistant'),
        get_string('max_execution_time_desc', 'local_ai_programming_assistant'),
        '0',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configselect(
        'local_ai_programming_assistant/responsemode',
        get_string('responsemode', 'local_ai_programming_assistant'),
        get_string('responsemode_desc', 'local_ai_programming_assistant'),
        'streaming',
        [
            'streaming' => get_string('responsemode_streaming', 'local_ai_programming_assistant'),
            'blocking' => get_string('responsemode_blocking', 'local_ai_programming_assistant'),
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_programming_assistant/recent_history_rounds',
        get_string('recent_history_rounds', 'local_ai_programming_assistant'),
        get_string('recent_history_rounds_desc', 'local_ai_programming_assistant'),
        '3',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_programming_assistant/max_conversation_turns',
        get_string('max_conversation_turns', 'local_ai_programming_assistant'),
        get_string('max_conversation_turns_desc', 'local_ai_programming_assistant'),
        '5',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_programming_assistant/ratelimit_per_minute',
        get_string('ratelimit_per_minute', 'local_ai_programming_assistant'),
        get_string('ratelimit_per_minute_desc', 'local_ai_programming_assistant'),
        '15',
        PARAM_INT
    ));

    // AI 管理器用途配置
    $settings->add(new admin_setting_configtext(
        'local_ai_programming_assistant/ai_manager_purpose',
        get_string('ai_manager_purpose', 'local_ai_programming_assistant'),
        get_string('ai_manager_purpose_desc', 'local_ai_programming_assistant'),
        'feedback',
        PARAM_TEXT
    ));

    // ==== 日志和调试选项 ====
    $settings->add(new admin_setting_configcheckbox(
        'local_ai_programming_assistant/logging',
        get_string('logging', 'local_ai_programming_assistant'),
        get_string('loggingdesc', 'local_ai_programming_assistant'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_programming_assistant/log_retention_days',
        get_string('log_retention_days', 'local_ai_programming_assistant'),
        get_string('log_retention_days_desc', 'local_ai_programming_assistant'),
        30,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_programming_assistant/debug',
        get_string('debug', 'local_ai_programming_assistant'),
        get_string('debugdesc', 'local_ai_programming_assistant'),
        0
    ));

    // ==== 系统提示词-身份设定 ====
    $settings->add(new admin_setting_heading('local_ai_programming_assistant/identity_setting_heading',
        get_string('identity_setting_heading', 'local_ai_programming_assistant'),
        get_string('identity_setting_heading_desc', 'local_ai_programming_assistant')
    ));
    $settings->add(new admin_setting_configtextarea(
        'local_ai_programming_assistant/identity_setting',
        get_string('identity_setting', 'local_ai_programming_assistant'),
        get_string('identity_setting_desc', 'local_ai_programming_assistant'),
        '',
        PARAM_RAW
    ));
    $settings->add(new admin_setting_configtextarea(
        'local_ai_programming_assistant/identity_setting_en',
        get_string('identity_setting_en', 'local_ai_programming_assistant'),
        get_string('identity_setting_en_desc', 'local_ai_programming_assistant'),
        '',
        PARAM_RAW
    ));
    // ==== 教学守则配置 ====
    $settings->add(new admin_setting_heading('local_ai_programming_assistant/teaching_principles_heading',
        get_string('teaching_principles_heading', 'local_ai_programming_assistant'),
        get_string('teaching_principles_heading_desc', 'local_ai_programming_assistant')
    ));
    $settings->add(new admin_setting_configtextarea(
        'local_ai_programming_assistant/teaching_principles',
        get_string('teaching_principles', 'local_ai_programming_assistant'),
        get_string('teaching_principles_desc', 'local_ai_programming_assistant'),
        '',
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_ai_programming_assistant/teaching_principles_en',
        get_string('teaching_principles_en', 'local_ai_programming_assistant'),
        get_string('teaching_principles_en_desc', 'local_ai_programming_assistant'),
        '',
        PARAM_RAW
    ));


    // ==== 系统提示词-安全边界 ====
    $settings->add(new admin_setting_heading('local_ai_programming_assistant/safety_boundaries_heading',
        get_string('safety_boundaries_heading', 'local_ai_programming_assistant'),
        get_string('safety_boundaries_heading_desc', 'local_ai_programming_assistant')
    ));
    $settings->add(new admin_setting_configtextarea(
        'local_ai_programming_assistant/safety_boundaries',
        get_string('safety_boundaries', 'local_ai_programming_assistant'),
        get_string('safety_boundaries_desc', 'local_ai_programming_assistant'),
        '',
        PARAM_RAW
    ));
    $settings->add(new admin_setting_configtextarea(
        'local_ai_programming_assistant/safety_boundaries_en',
        get_string('safety_boundaries_en', 'local_ai_programming_assistant'),
        get_string('safety_boundaries_en_desc', 'local_ai_programming_assistant'),
        '',
        PARAM_RAW
    ));

    // ==== 提示词构建模式配置 ====
    $settings->add(new admin_setting_heading('local_ai_programming_assistant/prompt_build_mode_heading',
        get_string('prompt_build_mode_heading', 'local_ai_programming_assistant'),
        get_string('prompt_build_mode_heading_desc', 'local_ai_programming_assistant')
    ));
    $settings->add(new admin_setting_configselect(
        'local_ai_programming_assistant/prompt_build_mode',
        get_string('prompt_build_mode', 'local_ai_programming_assistant'),
        get_string('prompt_build_mode_desc', 'local_ai_programming_assistant'),
        'original',
        [
            'original' => get_string('prompt_build_mode_original', 'local_ai_programming_assistant'),
            'dual' => get_string('prompt_build_mode_dual', 'local_ai_programming_assistant'),
        ]
    ));

    // ==== 策略模式提示词配置 ====
    $settings->add(new admin_setting_heading('local_ai_programming_assistant/strategy_prompts_heading',
        get_string('strategy_prompts_heading', 'local_ai_programming_assistant'),
        get_string('strategy_prompts_heading_desc', 'local_ai_programming_assistant')
    ));

    // 自由对话组合策略提示词（求助类型 + 策略指令 + 回答要求）
    $settings->add(new admin_setting_configtextarea(
        'local_ai_programming_assistant/strategy_prompt_free',
        get_string('strategy_prompt_free', 'local_ai_programming_assistant'),
        get_string('strategy_prompt_free_desc', 'local_ai_programming_assistant'),
        '',
        PARAM_RAW
    ));

    // 题目分析组合策略提示词（求助类型 + 策略指令 + 回答要求）
    $settings->add(new admin_setting_configtextarea(
        'local_ai_programming_assistant/strategy_prompt_question_analysis',
        get_string('strategy_prompt_question_analysis', 'local_ai_programming_assistant'),
        get_string('strategy_prompt_question_analysis_desc', 'local_ai_programming_assistant'),
        '',
        PARAM_RAW
    ));

    // 思路整理组合策略提示词（求助类型 + 策略指令 + 回答要求）
    $settings->add(new admin_setting_configtextarea(
        'local_ai_programming_assistant/strategy_prompt_idea_organization',
        get_string('strategy_prompt_idea_organization', 'local_ai_programming_assistant'),
        get_string('strategy_prompt_idea_organization_desc', 'local_ai_programming_assistant'),
        '',
        PARAM_RAW
    ));

    // 代码调试组合策略提示词（求助类型 + 策略指令 + 回答要求）
    $settings->add(new admin_setting_configtextarea(
        'local_ai_programming_assistant/strategy_prompt_code_debugging',
        get_string('strategy_prompt_code_debugging', 'local_ai_programming_assistant'),
        get_string('strategy_prompt_code_debugging_desc', 'local_ai_programming_assistant'),
        '',
        PARAM_RAW
    ));

    // ==== 提示词过滤配置 ====
    $settings->add(new admin_setting_heading('local_ai_programming_assistant/filterheading',
        get_string('filterheading', 'local_ai_programming_assistant'),
        get_string('filterheadingdesc', 'local_ai_programming_assistant')
    ));

    // 输入拦截列表
    $settings->add(new admin_setting_configtextarea(
        'local_ai_programming_assistant/inputblocklist',
        get_string('inputblocklist', 'local_ai_programming_assistant'),
        get_string('inputblocklistdesc', 'local_ai_programming_assistant'),
        '',
        PARAM_RAW
    ));

    // 输入净化规则
    $settings->add(new admin_setting_configtextarea(
        'local_ai_programming_assistant/inputsanitize',
        get_string('inputsanitize', 'local_ai_programming_assistant'),
        get_string('inputsanitizedesc', 'local_ai_programming_assistant'),
        '',
        PARAM_RAW
    ));

    // 输出拦截列表
    $settings->add(new admin_setting_configtextarea(
        'local_ai_programming_assistant/outputblocklist',
        get_string('outputblocklist', 'local_ai_programming_assistant'),
        get_string('outputblocklistdesc', 'local_ai_programming_assistant'),
        '',
        PARAM_RAW
    ));

    // 拦截规则
    $settings->add(new admin_setting_configtextarea(
        'local_ai_programming_assistant/interceptionrules',
        get_string('interceptionrules', 'local_ai_programming_assistant'),
        get_string('interceptionrulesdesc', 'local_ai_programming_assistant'),
        '',
        PARAM_RAW
    ));

    // ==== 知识库（RAG）配置 ====
    $settings->add(new admin_setting_heading('local_ai_programming_assistant/kb_heading',
        get_string('knowledgebase_heading', 'local_ai_programming_assistant'),
        get_string('knowledgebase_heading_desc', 'local_ai_programming_assistant')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ai_programming_assistant/kb_enabled',
        get_string('knowledgebase_enable', 'local_ai_programming_assistant'),
        get_string('knowledgebase_enable_desc', 'local_ai_programming_assistant'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_programming_assistant/kb_max_context_chunks',
        get_string('kb_max_context_chunks', 'local_ai_programming_assistant'),
        get_string('kb_max_context_chunks_desc', 'local_ai_programming_assistant'),
        '3',
        PARAM_INT
    ));

    // 知识库管理页面的链接
    $kbmanageurl = new moodle_url('/local/ai_programming_assistant/kb_manage.php');
    $settings->add(new admin_setting_heading(
        'local_ai_programming_assistant/kb_manage_heading',
        get_string('knowledgebase_manage_title', 'local_ai_programming_assistant'),
        get_string('knowledgebase_manage_desc', 'local_ai_programming_assistant') . ' ' .
        html_writer::link($kbmanageurl,
            get_string('knowledgebase_manage_button', 'local_ai_programming_assistant'),
            ['class' => 'btn btn-sm btn-secondary ml-2'])
    ));

    // 知识库管理页面（独立管理页面入口）
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_ai_programming_assistant_kb_manage',
        get_string('knowledgebase_manage_title', 'local_ai_programming_assistant'),
        new moodle_url('/local/ai_programming_assistant/kb_manage.php'),
        'local/ai_programming_assistant:config'
    ));
}