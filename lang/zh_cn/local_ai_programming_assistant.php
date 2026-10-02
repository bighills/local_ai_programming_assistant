<?php
/**
 * AI 编程助手 - 简体中文语言包
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */

$string['pluginname'] = 'AI 编程助手';
$string['assistantname'] = '编程助手';
$string['cached_label'] = '缓存';
$string['username'] = '学生';
$string['askaquestion'] = '提问关于代码的问题...';
$string['erroroccurred'] = '发生错误，请稍后重试。';
$string['new_chat'] = '新对话';
$string['popout'] = '打开聊天窗口';
$string['toggle_panel'] = '切换 AI 助手面板';
$string['close'] = '关闭';
$string['minimize'] = '最小化';
$string['pop_out'] = '弹出窗口';
$string['clear_conversation'] = '清空对话';
$string['server_busy'] = '服务器繁忙，请稍后重试。';
$string['loggingenabled'] = '已启用日志记录，消息正在被记录。';
$string['enable'] = '启用 AI 编程助手';
$string['enabledesc'] = '启用后，AI 编程助手将在测验答题页面上可用。';
$string['enable_non_quiz_pages'] = '在非试题页面启用 AI 反馈';
$string['enable_non_quiz_pages_desc'] = '启用后，AI 编程助手将在课程页面、作业、课程单元、页面等非试题页面上也可用。禁用后，仅在测验（Quiz）页面显示 AI 助手。';
$string['enable_non_coderunner'] = '在非 CodeRunner 试题页面启用助手';
$string['enable_non_coderunner_desc'] = '启用后，即使当前试题页面没有 CodeRunner 题型，AI 编程助手也会显示（仅自由问答模式）。禁用后，助手仅在包含 CodeRunner 题型的试题页面显示。';
$string['allowed_page_types'] = '允许显示的页面类型';
$string['allowed_page_types_desc'] = '指定 AI 编程助手在哪些页面类型上显示，每行一个页面类型前缀（如 mod-quiz-attempt）。以 # 开头的行将被忽略。留空则使用默认列表。';
$string['logging'] = '启用日志记录';
$string['loggingdesc'] = '记录所有用户消息和 AI 回复。';

// Debug mode
$string['debug'] = '启用调试模式';
$string['debugdesc'] = '启用后，发送给 AI 的完整提示词会显示在回复中（仅管理员可见）。';
$string['default_question_analysis_msg'] = '我对题目要求不太清楚';
$string['default_idea_organization_msg'] = '我需要帮助梳理解题思路';
$string['default_code_debugging_msg'] = '我的代码有问题，需要找出原因';
$string['debug_prompt_label'] = '--- 调试：发送给 AI 的完整提示词 ---';
$string['max_execution_time'] = '最大执行时间';
$string['max_execution_time_desc'] = 'AI 请求的最大执行时间（秒）。设置为 0 表示不限制。';
$string['error_rate_limit'] = '您的请求过于频繁，已达到每分钟 {$a} 次的限制，请稍后重试。';
$string['error_service_exception'] = '{$a}';
$string['unknown_error'] = '发生未知错误。';
$string['responsemode'] = '响应模式';
$string['responsemode_desc'] = '选择 AI 响应的返回方式。流式响应会逐字显示 AI 回复，提升用户体验。';
$string['responsemode_streaming'] = '流式响应';
$string['responsemode_blocking'] = '阻塞响应';
$string['max_conversation_turns'] = '最大对话轮数';
$string['max_conversation_turns_desc'] = '限制多轮对话中保留的历史消息数量，避免对话历史过长导致 API 请求 payload 过大。';
$string['recent_history_rounds'] = '最近历史独立发送轮数';
$string['recent_history_rounds_desc'] = '历史对话中作为独立消息（不压缩为摘要）发送给 AI 的最近对话轮数。默认值：3。';
$string['ratelimit_per_minute'] = '每分钟速率限制';
$string['ratelimit_per_minute_desc'] = '每用户每分钟允许的最大 AI 请求次数。默认值：15。';

// AI Backend settings
$string['ai_backend_heading'] = 'AI 后端配置';
$string['ai_backend_heading_desc'] = '选择要使用的 AI 后端。';
$string['ai_backend'] = 'AI 后端';
$string['ai_backend_desc'] = '选择处理消息的 AI 后端。';
$string['ai_backend_core_ai'] = '核心 AI 子系统 (core_ai)';
$string['ai_backend_ai_manager'] = 'AI 工具管理器 (local_ai_manager)';
$string['ai_backend_direct'] = '直接 API 调用 (兼容 OpenAI)';
$string['apiendpoint'] = 'API 端点';
$string['apiendpoint_desc'] = '直接后端的 API 端点 URL（例如 https://api.openai.com/v1/chat/completions）。';
$string['apikey'] = 'API 密钥';
$string['apikey_desc'] = '直接后端的 API 密钥。';
$string['model'] = '模型';
$string['model_desc'] = '直接后端的模型名称（例如 gpt-4o、deepseek-coder）。';
$string['maxtokens'] = '最大 Token 数';
$string['maxtokens_desc'] = 'AI 回复的最大 token 数。';
$string['temperature'] = '温度参数';
$string['temperature_desc'] = 'AI 生成的温度参数 (0.0 - 1.0)。';
$string['ai_manager_purpose'] = 'AI 管理器用途';
$string['ai_manager_purpose_desc'] = '与 local_ai_manager 配合使用的用途名称（例如 feedback）。';

// Multi-endpoint settings (direct backend)
$string['apiendpoint_list'] = '多端点列表 (JSON)';
$string['apiendpoint_list_desc'] = '可配置多个 API 端点，支持 JSON 数组格式。每个端点对象包含 name（名称，可选）、endpoint（URL）、apikey（密钥）、model（模型）。未配置时将回退到上方的单端点配置。示例：[{"name":"primary","endpoint":"https://api.example.com/v1/chat/completions","apikey":"sk-xxx","model":"gpt-4o"}]';
$string['apiendpoint_selector'] = '使用的端点';
$string['apiendpoint_selector_desc'] = '选择多端点列表中当前使用的端点。选择 "（默认）" 时使用上方单端点配置；选择 "（轮询）" 时在所有端点间按请求顺序轮流选择。';
$string['apiendpoint_strategy'] = '端点路由策略';
$string['apiendpoint_strategy_desc'] = '当 "使用的端点" 设为 "（轮询）" 时使用的路由策略。轮询：依次使用每个端点；优先级：按列表顺序优先使用前一个端点，失败时自动切换到下一个。';
$string['apiendpoint_strategy_roundrobin'] = '轮询（Round-robin）';
$string['apiendpoint_strategy_priority'] = '优先级失败切换（Failover）';
$string['apiendpoint_default_selector'] = '（默认单端点）';
$string['apiendpoint_roundrobin_selector'] = '（轮询/自动选择）';
$string['apiendpoint_config_invalid'] = '多端点配置格式无效，请检查 JSON 格式。';
$string['apiendpoint_all_failed'] = '所有 AI 端点均请求失败，请稍后重试或检查端点配置。';

// 端点配置界面 UI 标签
$string['apiendpoint_add_btn'] = '+ 新增端点';
$string['apiendpoint_remove_btn'] = '删除';
$string['apiendpoint_name_label'] = '名称';
$string['apiendpoint_name_placeholder'] = '端点名称（可选）';
$string['apiendpoint_url_label'] = '端点 URL';
$string['apiendpoint_key_label'] = 'API 密钥';
$string['apiendpoint_model_label'] = '模型';
$string['api_endpoint_selector_hint'] = '提示：在此处添加的端点会自动出现在上方"使用的端点"下拉列表中。';

// Direct backend error strings
$string['direct_config_incomplete'] = '直接 API 配置不完整，请在设置中配置 API 端点、密钥和模型。';
$string['direct_api_error'] = 'AI API 请求失败';
$string['direct_invalid_response'] = 'AI 返回了无效响应。';
$string['direct_empty_response'] = 'AI 返回了空响应。';
$string['direct_truncated'] = '响应被截断（finish_reason=length）';

$string['defaultprompt'] = '你是一位专业的编程导师，正在辅导学生完成编程练习。你的职责是帮助学生理解问题、识别代码中的问题，并引导他们找到解决方案，但不能直接给出完整答案。提供清晰的解释，指出具体问题并提出改进建议。在适当的时候，提出引导性问题来帮助学生学习。';
$string['sendmessage'] = '发送消息';
$string['resize_panel'] = '拖拽调整面板大小';
$string['questionlabel'] = '试题：';
$string['question_select_placeholder'] = '选择一道试题...';

// Strategy buttons
$string['strategy_question_analysis'] = '题目分析';
$string['strategy_idea_organization'] = '思路整理';
$string['strategy_code_debugging'] = '代码调试';
$string['strategy_send_student_code'] = '发送学生代码';
$string['strategy_send_test_results'] = '发送运行反馈和测试结果';
$string['free'] = '自由对话';

// Default prompts for each strategy
$string['default_prompt_question_analysis'] = '你是一位专业的编程导师。请对以下题目进行全面分析，包括：1) 题目要求和目标 2) 输入输出格式说明 3) 关键知识点和考察点 4) 解题思路和算法设计 5) 边界条件和特殊情况。帮助学生深入理解题目。';
$string['default_prompt_idea_organization'] = '你是一位专业的编程导师。请帮助学生整理解题思路，提供方向性的建议和思考框架。不要直接给出完整代码，而是通过引导性问题、步骤提示和算法思路来启发学生自己找到解决方案。';
$string['default_prompt_code_debugging'] = '你是一位专业的编程导师，正在帮助调试学生的代码。请：1) 分析代码中存在的问题（语法错误、逻辑错误、边界问题等）2) 解释为什么这些是问题 3) 给出具体的修改建议 4) 如果提供了运行反馈或测试结果，请结合这些信息进行分析。注意不要直接重写完整代码，而是引导学生理解并修复问题。';

// Strategy prompts settings
$string['strategy_prompts_heading'] = '策略提示词';
$string['strategy_prompts_heading_desc'] = '配置各策略模式使用的提示词，可使用占位符：{questiontext}、{studentanswer}、{testresults}、{programminglanguage}。';
$string['prompt_question_analysis_desc'] = '"题目分析"模式使用的提示词。';
$string['prompt_idea_organization_desc'] = '"思路整理"模式使用的提示词。';
$string['prompt_code_debugging_desc'] = '"代码调试"模式使用的提示词。';

$string['filterheading'] = '提示词过滤';
$string['filterheadingdesc'] = '配置过滤规则，控制发送给 AI 的内容以及显示给学生的回复内容。';

$string['inputblocklist'] = '输入拦截列表';
$string['inputblocklistdesc'] = '阻止用户消息发送给 AI 的关键词或正则表达式。每行一个。如果匹配，用户将看到一条通用提示消息。以 / 开头的行视为正则表达式（例如 /绕过.*规则/i）。';

$string['inputsanitize'] = '输入净化规则';
$string['inputsanitizedesc'] = '发送给 AI 之前替换用户输入中的关键词或正则。格式："搜索|替换"（每行一个）。以 / 开头的行视为正则。如果未指定替换文本，则使用 "***"。例如：忽略之前的指令|';

$string['outputblocklist'] = '输出拦截列表';
$string['outputblocklistdesc'] = '在显示给学生之前替换 AI 回复中的关键词或正则。格式："搜索|替换"（每行一个）。以 / 开头的行视为正则。如果未指定替换文本，则使用 "***"。';

$string['interceptionrules'] = '拦截规则';
$string['interceptionrulesdesc'] = '拦截用户消息并直接返回预设回复的规则（不调用 AI）。每条规则块用 "---" 分隔。每个块的格式：
trigger: 关键词或正则
mode: keyword（默认）或 regex
response: 预设回复文本

多行 trigger 说明：
- mode=keyword：每行 trigger 独立匹配（用 OR 关系），任一匹配即触发
- mode=regex：多行 trigger 用 | 连接形成正则多选分支

示例：

trigger: 给我答案
mode: keyword
response: 我不能直接提供答案，让我引导你思考这个问题。

\-\-\-

trigger: /直接.*答案/
mode: regex
response: 作为编程导师，我帮助你学习而不是直接给答案。';

$string['filter_blocked_keyword'] = '您的消息包含无法处理的内容，请重新表述您的问题。';
$string['filter_blocked_regex'] = '您的消息被内容过滤规则拦截，请重新表述您的问题。';
$string['invalidquestionid'] = '无效的试题 ID。';
$string['noquestionsfound'] = '未找到题目';

// ===================== 双层次提示词架构 =====================
$string['default_user_prompt_template'] = '';

// 教学守则配置
$string['teaching_principles_heading'] = '教学守则';
$string['teaching_principles_heading_desc'] = '配置自定义教学守则，将替换内置的硬编码教学守则。留空则使用内置守则。支持占位符：{{questiontext}}、{{studentanswer}}、{{testresults}}、{{programminglanguage}}。';
$string['teaching_principles'] = '教学守则（中文）';
$string['teaching_principles_desc'] = '自定义中文界面的教学守则，留空则使用内置默认守则。这些内容将替换系统提示词中硬编码的教学守则部分。支持占位符：{{questiontext}}、{{studentanswer}}、{{testresults}}、{{programminglanguage}}。';
$string['teaching_principles_en'] = '教学守则（英文）';
$string['teaching_principles_en_desc'] = '自定义英文界面的教学守则，留空则使用内置默认守则。这些内容将替换系统提示词中硬编码的教学守则部分。支持占位符：{{questiontext}}、{{studentanswer}}、{{testresults}}、{{programminglanguage}}。';

// 系统提示词-身份设定
$string['identity_setting_heading'] = '设置身份设定';
$string['identity_setting_heading_desc'] = '配置 AI 助手的身份设定，将替换内置的默认身份设定。留空则使用默认身份（高中信息技术老师）。支持占位符：{{questiontext}}、{{studentanswer}}、{{testresults}}、{{programminglanguage}}。';
$string['identity_setting'] = '身份设定（中文）';
$string['identity_setting_desc'] = '自定义中文界面的身份设定，留空则使用内置默认身份。这些内容将替换系统提示词中默认的身份设定部分。例如："你是一名耐心、专业的「高中信息技术老师」……"。支持占位符：{{questiontext}}、{{studentanswer}}、{{testresults}}、{{programminglanguage}}。';
$string['identity_setting_en'] = '身份设定（英文）';
$string['identity_setting_en_desc'] = '自定义英文界面的身份设定，留空则使用内置默认身份。这些内容将替换系统提示词中默认的身份设定部分。支持占位符：{{questiontext}}、{{studentanswer}}、{{testresults}}、{{programminglanguage}}。';

// 系统提示词-安全边界
$string['safety_boundaries_heading'] = '安全边界';
$string['safety_boundaries_heading_desc'] = '配置 AI 助手的安全边界规则，将替换内置的默认安全边界。留空则使用内置安全边界。支持占位符：{{questiontext}}、{{studentanswer}}、{{testresults}}、{{programminglanguage}}。';
$string['safety_boundaries'] = '安全边界（中文）';
$string['safety_boundaries_desc'] = '自定义中文界面的安全边界，留空则使用内置默认安全边界。这些内容将替换系统提示词中硬编码的安全边界部分。支持占位符：{{questiontext}}、{{studentanswer}}、{{testresults}}、{{programminglanguage}}。';
$string['safety_boundaries_en'] = '安全边界（英文）';
$string['safety_boundaries_en_desc'] = '自定义英文界面的安全边界，留空则使用内置默认安全边界。这些内容将替换系统提示词中硬编码的安全边界部分。支持占位符：{{questiontext}}、{{studentanswer}}、{{testresults}}、{{programminglanguage}}。';

// 组合策略提示词（求助类型 + 策略指令 + 回答要求）
$string['strategy_prompt_free'] = '自由对话组合策略提示词';
$string['strategy_prompt_free_desc'] = '自由对话模式的组合策略提示词，包含求助类型描述、策略指令和回答要求。留空则使用内置默认策略。支持占位符：{{questiontext}}、{{studentanswer}}、{{testresults}}、{{programminglanguage}}。';
$string['strategy_prompt_question_analysis'] = '题目分析组合策略提示词';
$string['strategy_prompt_question_analysis_desc'] = '题目分析模式的组合策略提示词，包含求助类型描述、策略指令和回答要求。留空则使用内置默认策略。支持占位符：{{questiontext}}、{{studentanswer}}、{{testresults}}、{{programminglanguage}}。';
$string['strategy_prompt_idea_organization'] = '思路整理组合策略提示词';
$string['strategy_prompt_idea_organization_desc'] = '思路整理模式的组合策略提示词，包含求助类型描述、策略指令和回答要求。留空则使用内置默认策略。支持占位符：{{questiontext}}、{{studentanswer}}、{{testresults}}、{{programminglanguage}}。';
$string['strategy_prompt_code_debugging'] = '代码调试组合策略提示词';
$string['strategy_prompt_code_debugging_desc'] = '代码调试模式的组合策略提示词，包含求助类型描述、策略指令和回答要求。留空则使用内置默认策略。支持占位符：{{questiontext}}、{{studentanswer}}、{{testresults}}、{{programminglanguage}}。';

// 提示词构建模式配置
$string['prompt_build_mode_heading'] = '提示词构建模式';
$string['prompt_build_mode_heading_desc'] = '选择提示词的构建方式。不同模式会影响 AI 后端接收的消息格式。';
$string['prompt_build_mode'] = '提示词构建模式';
$string['prompt_build_mode_desc'] = '选择提示词的构建方式。三级架构将系统提示词、上下文信息和用户消息分离为独立角色发送；二级架构将系统提示词和用户消息分离为独立角色发送。';
$string['prompt_build_mode_original'] = '三级架构（系统+上下文+用户输入）';
$string['prompt_build_mode_original_desc'] = '将身份设定、教学守则、安全边界等作为系统提示词（System），知识库上下文等信息作为上下文提示词（Context），用户当前问题作为用户消息（User），分别以独立角色发送给 AI。';
$string['prompt_build_mode_dual'] = '二级架构（系统+用户）';
$string['prompt_build_mode_dual_desc'] = '将身份设定、教学守则、安全边界等系统提示词作为 system 角色消息发送，知识库上下文和用户当前问题合并为 user 角色消息发送。仅支持 direct 后端。';

// 用户提示词构建组件说明（双重模式）
$string['user_prompt_strategy'] = '策略提示词';
$string['user_prompt_strategy_desc'] = '包含求助类型、策略指令和回答要求。';
$string['user_prompt_question'] = '试题内容';
$string['user_prompt_question_desc'] = '包含题目要求、编程语言等信息。';
$string['user_prompt_history'] = '历史会话';
$string['user_prompt_history_desc'] = '包含之前的对话历史摘要。';
$string['user_prompt_code'] = '学生代码和运行结果';
$string['user_prompt_code_desc'] = '包含学生提交的代码和评测结果。';
$string['user_prompt_current'] = '当前问题';
$string['user_prompt_current_desc'] = '学生当前提出的问题或请求。';

$string['privacy:metadata:local_ai_prog_assist_conversation'] = 'AI 编程助手对话会话记录。';
$string['privacy:metadata:local_ai_prog_assist_conversation:userid'] = '用户 ID。';
$string['privacy:metadata:local_ai_prog_assist_conversation:questionid'] = '题目 ID（自由模式为 0）。';
$string['privacy:metadata:local_ai_prog_assist_conversation:actiontype'] = '策略类型（free/question_analysis/idea_organization/code_debugging）。';
$string['privacy:metadata:local_ai_prog_assist_conversation:starttime'] = '会话开始时间。';
$string['privacy:metadata:local_ai_prog_assist_conversation:endtime'] = '最后活跃时间。';
$string['privacy:metadata:local_ai_prog_assist_conversation:contextid'] = '页面上下文 ID。';
$string['privacy:metadata:local_ai_prog_assist_conversation:courseid'] = '课程 ID。';
$string['privacy:metadata:local_ai_prog_assist_conversation:cmid'] = '课程模块 ID。';
$string['privacy:metadata:local_ai_prog_assist_conversation:pagetype'] = '页面类型。';
$string['privacy:metadata:local_ai_prog_assist_conversation:quizid'] = '测验 ID。';
$string['privacy:metadata:local_ai_prog_assist_conversation:messagecount'] = '消息数量。';
$string['privacy:metadata:local_ai_prog_assist_conversation:metadata'] = '扩展元数据（模型、响应时间、token 用量等）。';

$string['privacy:metadata:local_ai_prog_assist_message'] = 'AI 编程助手消息记录。';
$string['privacy:metadata:local_ai_prog_assist_message:conversationid'] = '所属会话 ID。';
$string['privacy:metadata:local_ai_prog_assist_message:role'] = '消息角色（user/assistant）。';
$string['privacy:metadata:local_ai_prog_assist_message:content'] = '消息内容。';
$string['privacy:metadata:local_ai_prog_assist_message:timestamp'] = '消息时间戳。';
$string['privacy:metadata:local_ai_prog_assist_message:metadata'] = '消息元数据（模型、响应时间、是否成功等）。';

// 视图显示模式
$string['viewmode_switch'] = '切换显示模式';
$string['viewmode_floating'] = '悬浮窗模式';
$string['viewmode_sidebar'] = '侧边栏模式';

$string['sidebar_resize'] = '拖动调整侧边栏宽度';

$string['stop_generating'] = '停止生成';
$string['expand'] = '展开';
$string['collapse'] = '折叠';
$string['copy'] = '复制';
$string['copied'] = '已复制！';
$string['ai_sender'] = 'AI';
$string['me'] = '我';
$string['ai_feedback_loading'] = 'AI 正在分析...';
$string['request_error'] = '请求失败，请稍后重试。';
$string['retry'] = '重试';
$string['question_analysis_hint'] = '点击发送即可分析题目...';
$string['idea_organization_hint'] = '点击发送即可整理思路...';
$string['code_debugging_hint'] = '点击发送即可调试代码...';
$string['code_debugging_requires_check'] = '请先点击"检查"按钮运行代码，获取测试结果后再使用代码调试功能';
$string['back_to_strategies'] = '返回';
$string['strategy_select_hint'] = '请先选择策略模式开始分析...';
$string['strategy_select_reminder'] = '请先点击上方的策略模式按钮（题目分析/思路整理/代码调试）开始分析';

// 使用统计
$string['overview_page_title'] = 'AI 使用总揽';
$string['overview_kpi_total_conversations'] = '总会话数';
$string['overview_kpi_unique_users'] = '活跃用户';
$string['overview_kpi_unique_questions'] = '涉及题目';
$string['overview_kpi_unique_pages'] = '自由页面';
$string['overview_daily_trend'] = '每日请求趋势';
$string['overview_mode_distribution'] = '模式分布';
$string['overview_model_distribution'] = '模型分布';
$string['overview_quiz_vs_free'] = '试题模式 vs 自由模式';
$string['overview_quiz_mode'] = '试题模式';
$string['overview_free_mode'] = '自由模式';
$string['overview_top_questions'] = '热门题目';
$string['overview_top_students'] = '活跃学生';
$string['overview_top_free_pages'] = '热门自由页面';
$string['overview_quick_links'] = '快捷入口';
$string['overview_no_data'] = '所选时间段内暂无 AI 使用数据。';
$string['stats_page_title'] = '试题AI统计';
$string['stats_page_desc'] = '查看学生使用 AI 编程助手的详细统计信息。';
$string['stats_quiz_select'] = '选择测验';
$string['stats_quiz'] = '测验';
$string['stats_all_quizzes'] = '全部测验';
$string['stats_no_quizzes'] = '暂无使用记录的测验。';
$string['stats_student_usage'] = '学生使用情况';
$string['stats_student_usage_desc'] = '当前测验下每位学生的 AI 使用情况统计。';
$string['stats_student'] = '学生姓名';
$string['stats_username'] = '用户名';
$string['stats_total_calls'] = '调用次数';
$string['stats_modes_used'] = '使用模式';
$string['stats_question_mode_quiz'] = '题目模式使用情况（当前测验）';
$string['stats_question_mode_quiz_desc'] = '当前测验下每道题每个模式的使用情况统计。';
$string['stats_question_mode_overall'] = '题目模式使用情况（总体）';
$string['stats_question_mode_overall_desc'] = '所有测验中每道题每个模式的使用情况统计。';
$string['stats_question'] = '题目';
$string['stats_mode'] = '模式';
$string['stats_count'] = '次数';
$string['stats_no_data'] = '暂无数据。';
$string['stats_total_requests'] = '请求总数';
$string['quiz_stats_total_chats'] = '总会话数';
$string['stats_success_rate'] = '成功率';
$string['stats_avg_responsetime'] = '平均响应时间';
$string['stats_last_interaction'] = '最近交互时间';
$string['stats_unique_users'] = '用户数';
$string['stats_unique_questions'] = '独立题目数';
$string['stats_model_usage'] = '模型使用情况';
$string['stats_mode_usage'] = '分析模式使用情况';
$string['stats_top_questions'] = '热门题目';
$string['stats_user'] = '用户';
$string['stats_model'] = '模型';
$string['stats_success'] = '成功';
$string['stats_failed'] = '失败';
$string['stats_unique_conversations'] = '独立会话';
$string['stats_time_from'] = '开始日期';
$string['stats_time_to'] = '结束日期';
$string['stats_time'] = '最近交互';
$string['stats_search_btn'] = '搜索';
$string['stats_question_name'] = '题目名称';
$string['stats_total'] = '合计';
$string['export_csv'] = '导出 CSV';
$string['nav_quiz_stats_inline'] = 'AI 使用统计';
$string['question_detail_total_students'] = '学生总数';

$string['quiz_stats_page_title'] = 'AI 测验统计';
$string['quiz_stats_quizid'] = '测验ID';
$string['quiz_stats_quiz_name'] = '测验名称';
$string['quiz_stats_tab_overview'] = '总体预览';
$string['quiz_stats_tab_question_followups'] = '按题目查看';
$string['quiz_stats_tab_student_followups'] = '按学生查看';
$string['quiz_stats_question_usage'] = '题目 AI 使用情况';
$string['quiz_stats_invalid_quiz'] = '无效的测验ID。';
$string['quiz_stats_require_rest'] = '暂无 AI 使用数据，请先让学生完成测验提交。';
$string['quiz_stats_students_count'] = '名学生';
$string['quiz_stats_questions_count'] = '道题目';
$string['quiz_stats_clear_filter'] = '清除筛选';
$string['quiz_stats_search_hint'] = '请输入测验ID查看 AI 使用统计。';
$string['quiz_stats_group'] = '小组';
$string['quiz_stats_all_groups'] = '全部小组';
$string['student_usage_group_filter'] = '小组过滤';
$string['student_usage_group_all'] = '全部小组';
$string['student_usage_group_filtered'] = '已按小组过滤';
$string['backtoquiz'] = '返回测验';

$string['question_detail_page_title'] = '题目 AI 使用详情';
$string['question_detail_action'] = '操作';
$string['question_detail_view_detail'] = '查看详情';
$string['question_detail_invalid'] = '无效的测验或题目ID。';
$string['question_detail_student_view'] = '学生视角';
$string['question_detail_source_code'] = '源代码';
$string['question_detail_student_question'] = '学生提问';
$string['question_detail_feedback'] = 'AI 反馈';
$string['question_detail_conversations'] = '对话历史';
$string['question_detail_user'] = '用户';
$string['question_detail_assistant'] = '助手';
$string['question_detail_student_usage'] = '学生使用情况';
$string['backtostats'] = '返回统计';

$string['stats_action'] = '操作类型';
$string['mode_question_analysis'] = '题目分析';
$string['mode_idea_organization'] = '思路整理';
$string['mode_code_debugging'] = '代码调试';
$string['mode_free'] = '自由对话';

// AI 模型思考能力设置
$string['enable_thinking'] = '启用 AI 模型思考能力';
$string['enable_thinking_desc'] = '启用 AI 模型的思考（Thinking）能力。适用于支持 reasoning_content 的模型（如 Qwen、DeepSeek-R1 等）。启用后，模型会在回答前进行深度思考，并展示思考过程。思考模式下 max_tokens 会自动扩大 3 倍以留出思考和正式内容的空间。取消勾选以禁用思考功能，获得更低的延迟和更少的 token 消耗。';
$string['thinking_label'] = '思考过程';
$string['thinking_in_progress'] = 'AI 正在思考...';
$string['thinking_done'] = '思考完成';
$string['thinking_too_long'] = 'AI 模型思考过程过长，消耗了全部输出额度，未能生成正式回复。请增大 max_tokens 设置或关闭思考能力后重试。';
$string['context_confirmation'] = '我已了解当前上下文，请提出你的问题。';

// 自由模式统计
$string['free_stats_page_title'] = '非试题AI统计';
$string['free_stats_page_desc'] = '自由对话模式无题号关联，学生在编程练习中随时向 AI 提问的记录。';
$string['stats_daily_trend'] = '每日趋势';
$string['stats_date'] = '日期';
$string['stats_preset_days'] = '近 {$a} 天';
$string['stats_daily_total_days'] = '共';
$string['stats_date_presets'] = '日期预设';
$string['stats_daily_stats_pagination'] = '每日统计分页';
$string['stats_questions_pagination'] = '题目分页';
$string['stats_student_stats_pagination'] = '学生统计分页';
$string['stats_students_pagination'] = '学生分页';

// 自由模式聊天详情追溯
$string['free_chat_detail_page_title'] = '自由对话聊天记录';
$string['free_chat_detail_back'] = '返回自由统计';
$string['free_chat_detail_total'] = '记录总数';
$string['free_chat_detail_view'] = '查看聊天记录';
$string['free_chat_detail_source_code'] = '查看学生代码';
$string['free_chat_detail_clear_filter'] = '清除页面过滤';
$string['free_chat_detail_filter_page'] = '点击查看该页面记录';
$string['free_stats_pagetype_distribution'] = '页面类型分布';
$string['free_stats_pagetype'] = '页面类型';
$string['free_stats_pages'] = '页面数';

// 最近自由对话记录（所有学生，不按学生分类汇总）
$string['recent_free_chats_page_title'] = '最近非试题AI对话记录';
$string['recent_free_chats_page_desc'] = '展示所有学生最近提交的自由对话记录，按提交时间倒序排列，不按学生分类汇总。';
$string['recent_free_chats_back'] = '返回统计';
$string['recent_free_chats_total'] = '记录总数';
$string['recent_free_chats_student'] = '学生';
$string['recent_free_chats_filter_student'] = '按学生姓名过滤';
$string['recent_free_chats_clear_filter'] = '清除页面过滤';
$string['recent_free_chats_filter_page'] = '点击查看该页面记录';
$string['recent_free_chats_view_student'] = '查看该学生的聊天记录';
$string['recent_free_chats_export_csv_link'] = '最近非试题AI对话记录';
$string['recent_free_chats_view_detail'] = '查看对话详情';

// 最近AI使用对话记录（有题号）
$string['recent_quiz_chats_page_title'] = '最近试题AI对话记录';
$string['recent_quiz_chats_page_desc'] = '展示所有学生最近提交的AI使用对话记录（有题号），按试题和用户分组汇总，按最近交互时间倒序排列。';
$string['recent_quiz_chats_total'] = '记录总数';
$string['recent_quiz_chats_student'] = '学生';
$string['recent_quiz_chats_view_detail'] = '查看对话详情';
$string['recent_quiz_chats_view_mode_chats'] = '查看该学生在当前策略模式下对该试题的对话记录（按时间从早到晚）';
$string['recent_quiz_chats_view_mode_detail'] = '查看该学生在当前策略模式下对该试题的AI对话详情（按时间从早到晚，可前后切换）';

// conversation_detail.php - 筛选导航条
$string['conversation_detail_filtered_scope'] = '按学生+试题+模式筛选';
$string['conversation_detail_prev'] = '上一条';
$string['conversation_detail_next'] = '下一条';
$string['conversation_detail_show_all'] = '查看全部会话列表';
$string['recent_quiz_chats_filter_quiz'] = '按测验过滤';
$string['recent_quiz_chats_export_csv'] = '导出CSV';
$string['recent_quiz_chats_questionid'] = '试题ID';
$string['recent_quiz_chats_questionname'] = '试题名称';
$string['recent_quiz_chats_quizid'] = '测验ID';
$string['recent_quiz_chats_quizname'] = '测验名称';
$string['recent_quiz_chats_course'] = '所属课程';
$string['recent_quiz_chats_view_quiz'] = '查看测验';
$string['recent_quiz_chats_mode'] = '策略模式';

// 学生搜索
$string['stats_student_search_placeholder'] = '搜索学生姓名...';

// 有题号聊天详情
$string['question'] = '题目';
$string['chat_detail_page_title'] = '有题号聊天记录';
$string['chat_detail_back'] = '返回统计';
$string['chat_detail_total'] = '记录总数';
$string['chat_detail_view'] = '查看聊天记录';

// 学生题目列表（中间页）
$string['student_questions_page_title'] = '学生使用题目列表';
$string['student_questions_back'] = '返回统计';
$string['student_questions_total'] = '使用题目数';
$string['student_questions_interactions'] = '总交互次数';
$string['student_questions_last_interaction'] = '最近交互';
$string['student_questions_view'] = '查看学生使用的题目';

// 自由模式学生页面列表（中间页）
$string['student_free_pages_page_title'] = '学生自由模式页面列表';
$string['student_free_pages_back_to_stats'] = '返回自由统计';
$string['student_free_pages_back'] = '← 返回页面列表';
$string['student_free_pages_total'] = '使用页面数';
$string['student_free_pages_interactions'] = '总交互次数';
$string['student_free_pages_pagetype'] = '页面类型';
$string['student_free_pages_pageid'] = '页面ID';
$string['student_free_pages_pagename'] = '页面名称';
$string['student_free_pages_course'] = '所属课程';
$string['student_free_pages_view'] = '查看学生使用的页面';
$string['student_free_pages_view_page'] = '查看页面';
$string['student_free_pages_title'] = '自由模式页面列表';

// 对话内容截断（completion.php 硬编码字符串迁移）
$string['code_truncated'] = '// ... [代码过长，已截断至 {$a->maxlen} 字符，原文共 {$a->codelen} 字符]';

// 统计（新增）
$string['stats_student_count'] = '学生人数';

// 错误消息（从硬编码迁移）
$string['error_json_encode'] = '请求构建失败：JSON 编码错误';
$string['error_curl_failure'] = '服务连接失败 (cURL {$a->errno}): {$a->error}';
$string['error_http_status'] = 'AI 服务返回 HTTP {$a}';
$string['error_config_incomplete'] = 'AI 后端配置不完整，请联系管理员。';
$string['error_api_connection'] = '连接 AI 服务失败：{$a}';
$string['error_api_response'] = '从 AI 服务收到无效响应。';
$string['error_api_error'] = 'AI 服务返回了错误。';

// 测试结果格式化
$string['testresult_error_raw'] = '错误输出:';
$string['testresult_outcome'] = '总体结果: {$a}';
$string['testresult_precheck'] = '注: 本次为预检 (Precheck) 运行.';
$string['testresult_error_msg'] = '错误信息: {$a}';
$string['testresult_summary'] = '摘要: {$a}';
$string['testresult_cases'] = '测试用例:';
$string['testresult_passed'] = '测试通过情况: {$a->passed}/{$a->total} 个测试用例通过.';
$string['testresult_unrecognized'] = '测试结果:';
$string['error_empty_response'] = 'AI 返回了空回复';
$string['error_empty_messages_array'] = '消息数组为空，请发送有效的消息内容';
$string['error_no_valid_user_message'] = '未找到有效的用户消息';
$string['error_empty_response_retry'] = 'AI 服务返回空响应，请稍后重试';
$string['error_service_unavailable'] = 'AI 服务暂时不可用，请稍后重试';
$string['error_empty_request_body'] = 'AI 服务接收到空请求，请检查您的输入内容';
$string['error_internal_error'] = '服务内部错误：{$a}';
$string['error_internal_error_safe'] = '服务内部错误，请稍后重试。如问题持续存在，请联系管理员并提供以下时间戳：{$a}';
$string['error_ai_request_failed'] = 'AI 请求失败，请稍后重试。';
$string['error_invalid_json_body'] = '无效的 JSON 请求体';
$string['error_invalid_sesskey'] = '无效的会话密钥';
$string['error_not_streaming_mode'] = '响应模式未设置为流式';
$string['error_not_blocking_mode'] = '响应模式未设置为阻塞模式，请刷新页面。';
$string['error_not_enabled'] = 'AI 编程助手未启用。';
$string['error_invalid_context'] = '无效的上下文：指定的上下文不存在或您无权访问。';
$string['question_content_not_found'] = '[题目内容未找到，请检查页面是否正确加载]';
$string['programming_language_label'] = '编程语言：{$a}';
$string['embedded_questions_intro'] = '当前页面通过嵌入题功能嵌入了 {$a} 道测验题，题目内容如下：';
$string['embedded_question_header'] = '【嵌入题 {$a->number}】{$a->name}（题型：{$a->qtype}）';
$string['all_embedded_questions'] = '全部嵌入题';
$string['unknown_question_id'] = '题目 #{$a}';
$string['unknown_quiz_id'] = '测验 #{$a}';

// 知识库（RAG）
$string['knowledgebase_heading'] = '教材知识库（RAG）';
$string['knowledgebase_heading_desc'] = '上传教材（PDF/DOCX/MD/TXT）构建知识库，AI 在回答时将自动检索教材内容作为参考。';
$string['knowledgebase_enable'] = '启用知识库';
$string['knowledgebase_enable_desc'] = '启用后，AI 在回答学生问题时将自动从教材知识库中检索相关内容作为参考。';
$string['kb_max_context_chunks'] = '知识库最大上下文块数';
$string['kb_max_context_chunks_desc'] = '加入提示词中的本地知识库参考材料块数。默认值：3。';
$string['knowledgebase_manage_title'] = '知识库管理';
$string['knowledgebase_manage_desc'] = '上传教材文件（PDF、DOCX、Markdown、TXT）到知识库，或者管理已上传的文件。';
$string['knowledgebase_manage_button'] = '管理知识库';
$string['knowledgebase_upload_heading'] = '上传文件';
$string['knowledgebase_upload_button'] = '上传并解析';
$string['knowledgebase_file_name'] = '资源名称';
$string['knowledgebase_file_filename'] = '文件名';
$string['knowledgebase_file_size'] = '大小';
$string['knowledgebase_file_chunks'] = '文本块数';
$string['knowledgebase_file_timecreated'] = '上传时间';
$string['knowledgebase_file_header_original'] = '原始文件';
$string['knowledgebase_file_hasfile'] = '✓ 可下载';
$string['knowledgebase_file_not_saved'] = '无原始文件';
$string['knowledgebase_file_download'] = '下载原始文件';
$string['knowledgebase_file_preview'] = '文件预览';
$string['knowledgebase_preview_not_available'] = '此格式暂不支持在线预览，请下载查看。';
$string['knowledgebase_name_empty'] = '名称不能为空';
$string['knowledgebase_no_external_dependency'] = '无需外部依赖';
$string['knowledgebase_file_list'] = '已上传的文件';
$string['knowledgebase_no_files'] = '尚未上传任何教材文件。';
$string['knowledgebase_search_placeholder'] = '搜索教材名称或文件名...';
$string['knowledgebase_search_clear'] = '清除搜索';
$string['knowledgebase_no_search_results'] = '未找到匹配的教材文件。';
$string['knowledgebase_perpage'] = '每页：';
$string['knowledgebase_file_tag'] = '资源标签';
$string['knowledgebase_tag_all'] = '全部标签';
$string['knowledgebase_tag_filter'] = '标签筛选';
$string['knowledgebase_batch_delete_button'] = '批量删除';
$string['knowledgebase_batch_delete_success'] = '已成功删除 {$a} 个教材文件。';
$string['knowledgebase_confirm_batch_delete'] = '确定要删除选中的 {$a} 个教材文件吗？此操作不可撤销。';
$string['knowledgebase_rename_failed'] = '重命名失败。';
$string['knowledgebase_file_header_tag'] = '来源标签';
$string['knowledgebase_select_file'] = '选择文件（PDF / DOCX / MD / TXT）';
$string['knowledgebase_upload_success'] = '教材上传成功，已解析并存入知识库。';
$string['knowledgebase_file_deleted'] = '知识库文件已删除。';
$string['knowledgebase_confirm_delete'] = '确定要删除教材「{$a}」吗？此操作不可撤销。';
$string['knowledgebase_file_duplicate'] = '相同内容的文件已存在知识库中，请勿重复上传。';
$string['knowledgebase_pdf_parse_failed'] = 'PDF 文本提取失败，请确保文件内容是文本型 PDF（非扫描件）。';
$string['knowledgebase_pdf_empty'] = 'PDF 解析结果为空，请检查文件内容。';
$string['knowledgebase_stats_files'] = '文件数';
$string['knowledgebase_stats_chunks'] = '文本块总数';
$string['knowledgebase_stats_parsers'] = '解析引擎';
$string['knowledgebase_pdftotext_available'] = 'PDF 解析：pdftotext 可用';
$string['knowledgebase_pdftotext_unavailable'] = 'PDF 解析：pdftotext 不可用';
$string['knowledgebase_pdftotext_missing'] = 'PDF 解析工具 pdftotext 未安装或不可用，请联系管理员安装 poppler-utils。';
$string['knowledgebase_docx_available'] = 'DOCX 解析：ZipArchive 可用';
$string['knowledgebase_docx_unavailable'] = 'DOCX 解析：ZipArchive 不可用，请安装 PHP zip 扩展。';
$string['knowledgebase_docx_parse_failed'] = 'DOCX 文件解析失败，请确保文件是有效的 Word 文档。';
$string['knowledgebase_docx_empty'] = 'DOCX 文件内容为空，请检查文件。';
$string['knowledgebase_docx_zip_missing'] = 'DOCX 解析需要 PHP ZipArchive 支持，请联系管理员安装 php-zip 扩展。';
$string['knowledgebase_txt_parse_failed'] = '文本文件读取失败，请检查文件权限。';
$string['knowledgebase_txt_empty'] = '文件内容为空，请检查文件。';
$string['knowledgebase_unsupported_format'] = '不支持的文件格式：{$a}。仅支持 PDF、DOCX、Markdown（.md）和 TXT 文件。';
$string['knowledgebase_view_title'] = '查看教材：{$a}';
$string['knowledgebase_link_title'] = '在知识库中查看教材：{$a}（新窗口打开）';
$string['knowledgebase_link_title_chunk'] = '在知识库中查看{$a->name}第{$a->chunk}段（新窗口打开）';
$string['knowledgebase_file_info'] = '文件信息';
$string['knowledgebase_file_content'] = '教材内容';
$string['knowledgebase_no_chunks'] = '该文件没有文本分块。';
$string['knowledgebase_chunk_label'] = '第 {$a} 段';
$string['knowledgebase_file_not_found'] = '未找到指定的知识库文件。';
$string['knowledgebase_rebuild_button'] = '重建索引';
$string['knowledgebase_confirm_rebuild'] = '确定要重建所有知识库文件的倒排索引吗？此操作会清空现有索引并使用最新分词器重新构建，可能需要数分钟。';
$string['knowledgebase_rebuild_success'] = '已成功重建 {$a} 个知识库文件的倒排索引。';
$string['knowledgebase_rebuild_partial'] = '索引重建部分完成：成功 {$a->indexed} 个，失败 {$a->errors} 个（{$a->errornames}）。';
$string['knowledgebase_batch_upload_heading'] = '批量上传文件';
$string['knowledgebase_batch_select_files'] = '选择多个教材文件（PDF / DOCX / MD / TXT，最多 20 个）';
$string['knowledgebase_batch_upload_button'] = '批量上传并解析';
$string['knowledgebase_batch_upload_success'] = '批量上传完成：成功 {$a->success} 个，跳过重复 {$a->skipped} 个，失败 {$a->errors} 个。';
$string['knowledgebase_batch_upload_all_skipped'] = '上传的 {$a} 个文件均已存在，无需重复上传。';
$string['knowledgebase_file_rebuild_button'] = '重建';
$string['knowledgebase_file_confirm_rebuild'] = '确定要重建文件"{$a}"的倒排索引吗？';
$string['knowledgebase_file_rebuild_success'] = '已成功重建"{$a}"的倒排索引。';
$string['knowledgebase_file_rebuild_failed'] = '重建"{$a}"的倒排索引失败';
$string['actions'] = '操作';

// 能力名称
$string['ai_programming_assistant:use'] = '使用 AI 编程助手';
$string['ai_programming_assistant:viewknowledgebase'] = '查看知识库';
$string['ai_programming_assistant:viewstats'] = '查看 AI 使用统计';
$string['ai_programming_assistant:config'] = '配置 AI 编程助手';

// 上传失败
$string['knowledgebase_upload_failed'] = '文件上传失败，请选择一个文件后再试。';

// 通用
$string['clear'] = '清除';
$string['pagination'] = '分页导航';
$string['invalid_parameters'] = '无效的参数';

// 会话统计
$string['conversation_list_page_title'] = '会话列表';
$string['conversation_list_page_desc'] = '查看所有 AI 会话记录，按时间从近到远排列。';
$string['conversation_detail_page_title'] = '会话详情';
$string['conversation_list_student'] = '学生';
$string['conversation_list_starttime'] = '开始时间';
$string['conversation_list_messagecount'] = '消息数';
$string['conversation_list_actiontype'] = '模式';
$string['conversation_list_pagetype'] = '页面类型';
$string['conversation_list_context'] = '上下文';
$string['conversation_list_search_hint'] = '搜索学生姓名或用户名';
$string['conversation_detail_messages'] = '消息记录';
$string['conversation_detail_course'] = '所属课程';
$string['conversation_no_messages'] = '该会话暂无消息。';
$string['conversation_no_data'] = '暂无会话记录。';
$string['conversation_delete_button'] = '删除选中';
$string['conversation_confirm_delete'] = '确定要删除选中的 {$a} 个会话吗？此操作将同时删除对应的对话内容，且不可撤销。';
$string['conversation_delete_success'] = '已成功删除 {$a} 个会话。';
$string['conversation_delete_select_all'] = '全选';
$string['stats_question_delete_btn'] = '删除选中题目';
$string['stats_student_delete_btn'] = '删除选中用户';
$string['stats_record_confirm_delete'] = '确定要删除选中的 {$a} 条记录关联的所有对话内容吗？此操作不可撤销。';
$string['stats_record_delete_success'] = '已成功删除 {$a} 个关联会话。';

// 统计页链接提示
$string['stats_conversation_count'] = '会话数量';
$string['stats_view_question_conversations'] = '查看该试题的会话列表';
$string['recent_quiz_chats_view_question_chats'] = '查看该试题的对话记录（按时间从早到晚）';
$string['stats_view_user_conversations'] = '查看该用户的会话列表';
$string['stats_view_user_questions'] = '查看该用户的题目列表';

// 定时任务
$string['task_cleanup'] = '清理过期 AI 对话记录';
$string['log_retention_days'] = '日志保留天数';

$string['notset'] = '未设置';
$string['log_retention_days_desc'] = 'AI 对话记录保留的天数，超过此天数的记录将被定时任务自动清理。默认值：30 天。';

// 学生提问记录页
$string['asked_questions_page_title'] = '学生提问记录';
$string['asked_questions_short'] = '提问';
$string['asked_questions_no_data'] = '暂无提问记录。';
$string['stats_all_modes'] = '全部策略模式';
$string['stats_course'] = '课程';
$string['unknown_course_id'] = '未知课程（ID: {$a}）';
$string['csv_student'] = '学生姓名';
$string['csv_username'] = '用户名';
$string['csv_question'] = '题目';
$string['csv_mode'] = '策略模式';
$string['csv_question_content'] = '提问内容';
$string['csv_time'] = '提问时间';
$string['csv_quiz'] = '测验';
$string['csv_course'] = '课程';
$string['free_stats_free_chat'] = '自由提问';
$string['asked_questions_view_student'] = '查看该学生的提问';
$string['asked_questions_view_question'] = '查看该题目的提问';
$string['asked_questions_view_student_question'] = '查看该学生对这道题的提问';
$string['asked_questions_view_mode'] = '查看该策略模式的提问';