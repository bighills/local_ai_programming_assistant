<?php
/**
 * AI 编程助手 - 英文语言包
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */

$string['pluginname'] = 'AI Programming Assistant';
$string['assistantname'] = 'Programming Assistant';
$string['cached_label'] = 'Cached';
$string['username'] = 'Student';
$string['askaquestion'] = 'Ask a question about your code...';
$string['erroroccurred'] = 'An error occurred. Please try again later.';
$string['new_chat'] = 'New chat';
$string['popout'] = 'Open chat window';
$string['toggle_panel'] = 'Toggle AI Assistant panel';
$string['close'] = 'Close';
$string['minimize'] = 'Minimize';
$string['pop_out'] = 'Pop out';
$string['clear_conversation'] = 'Clear conversation';
$string['server_busy'] = 'Server busy, please try again.';
$string['loggingenabled'] = 'Logging is enabled. Messages are being recorded.';
$string['enable'] = 'Enable AI Programming Assistant';
$string['enabledesc'] = 'If enabled, the AI Programming Assistant will be available on quiz pages.';
$string['enable_non_quiz_pages'] = 'Enable AI feedback on non-quiz pages';
$string['enable_non_quiz_pages_desc'] = 'If enabled, the AI Programming Assistant will also be available on non-quiz pages such as course pages, assignments, lessons, and pages. When disabled, the AI assistant will only appear on Quiz pages.';
$string['enable_non_coderunner'] = 'Enable assistant on non-CodeRunner quiz pages';
$string['enable_non_coderunner_desc'] = 'If enabled, the AI Programming Assistant will appear on quiz pages even when no CodeRunner questions are present (free mode only). When disabled, the assistant will only appear on quiz pages that contain CodeRunner questions.';
$string['allowed_page_types'] = 'Allowed page types';
$string['allowed_page_types_desc'] = 'Specify the page types where the AI Programming Assistant should appear, one per line. Each line is a page type prefix (e.g., mod-quiz-attempt). Lines starting with # are ignored. If empty, the default list is used.';
$string['logging'] = 'Enable logging';
$string['loggingdesc'] = 'Log all user messages and AI responses.';

// Debug mode
$string['debug'] = 'Enable debug mode';
$string['debugdesc'] = 'When enabled, the full prompt sent to the AI will be displayed in responses (admin only).';
$string['default_question_analysis_msg'] = 'I am not quite clear about the question requirements';
$string['default_idea_organization_msg'] = 'I need help organizing my problem-solving ideas';
$string['default_code_debugging_msg'] = 'My code has issues and I need to find the cause';
$string['debug_prompt_label'] = '--- DEBUG: Full prompt sent to AI ---';
$string['max_execution_time'] = 'Maximum execution time';
$string['max_execution_time_desc'] = 'Maximum execution time for AI requests in seconds. Set to 0 for no limit.';
$string['error_rate_limit'] = 'Too many requests, please try again later (limit: {$a} requests per minute).';
$string['error_service_exception'] = '{$a}';
$string['unknown_error'] = 'An unknown error occurred.';
$string['responsemode'] = 'Response mode';
$string['responsemode_desc'] = 'Choose how AI responses are delivered. Streaming shows AI replies character by character for better user experience.';
$string['responsemode_streaming'] = 'Streaming';
$string['responsemode_blocking'] = 'Blocking';
$string['max_conversation_turns'] = 'Max conversation turns';
$string['max_conversation_turns_desc'] = 'Limit the number of historical messages kept in multi-turn conversations to avoid oversized API requests.';
$string['recent_history_rounds'] = 'Recent history rounds';
$string['recent_history_rounds_desc'] = 'Number of recent conversation rounds sent as independent messages (not compressed as summary) in the prompt. Default: 3.';
$string['ratelimit_per_minute'] = 'Rate limit per minute';
$string['ratelimit_per_minute_desc'] = 'Maximum number of AI requests allowed per user per minute. Default: 15.';

// AI Backend settings
$string['ai_backend_heading'] = 'AI Backend Configuration';
$string['ai_backend_heading_desc'] = 'Choose which AI backend to use.';
$string['ai_backend'] = 'AI Backend';
$string['ai_backend_desc'] = 'Select the AI backend for processing messages.';
$string['ai_backend_core_ai'] = 'Core AI (core_ai)';
$string['ai_backend_ai_manager'] = 'AI Manager (local_ai_manager)';
$string['ai_backend_direct'] = 'Direct API (OpenAI-compatible)';
$string['apiendpoint'] = 'API Endpoint';
$string['apiendpoint_desc'] = 'The API endpoint URL for direct backend (e.g. https://api.openai.com/v1/chat/completions).';
$string['apikey'] = 'API Key';
$string['apikey_desc'] = 'The API key for direct backend.';
$string['model'] = 'Model';
$string['model_desc'] = 'The model name for direct backend (e.g. gpt-4o, deepseek-coder).';
$string['maxtokens'] = 'Max Tokens';
$string['maxtokens_desc'] = 'Maximum tokens for AI response.';
$string['temperature'] = 'Temperature';
$string['temperature_desc'] = 'Temperature for AI generation (0.0 - 1.0).';
$string['ai_manager_purpose'] = 'AI Manager Purpose';
$string['ai_manager_purpose_desc'] = 'The purpose name to use with local_ai_manager (e.g. feedback).';

// Multi-endpoint settings (direct backend)
$string['apiendpoint_list'] = 'Multi-endpoint List (JSON)';
$string['apiendpoint_list_desc'] = 'Configure multiple API endpoints as a JSON array. Each endpoint object supports name (optional), endpoint (URL), apikey (key), and model. Falls back to the single endpoint above when empty. Example: [{"name":"primary","endpoint":"https://api.example.com/v1/chat/completions","apikey":"sk-xxx","model":"gpt-4o"}]';
$string['apiendpoint_selector'] = 'Active Endpoint';
$string['apiendpoint_selector_desc'] = 'Choose which endpoint from the multi-endpoint list to use. Pick "(Default)" to use the single endpoint above, or "(Round-robin)" to cycle through all endpoints per request.';
$string['apiendpoint_strategy'] = 'Routing Strategy';
$string['apiendpoint_strategy_desc'] = 'Used when Active Endpoint is set to "(Round-robin)". Round-robin cycles through endpoints in order. Priority/Failover uses the first endpoint in the list and falls back to the next on failure.';
$string['apiendpoint_strategy_roundrobin'] = 'Round-robin';
$string['apiendpoint_strategy_priority'] = 'Priority (failover)';
$string['apiendpoint_default_selector'] = '(Default single endpoint)';
$string['apiendpoint_roundrobin_selector'] = '(Round-robin / auto)';
$string['apiendpoint_config_invalid'] = 'Multi-endpoint configuration is invalid. Please check the JSON format.';
$string['apiendpoint_all_failed'] = 'All AI endpoints failed. Please try again later or check the endpoint configuration.';

// Endpoint config UI labels
$string['apiendpoint_add_btn'] = '+ Add Endpoint';
$string['apiendpoint_remove_btn'] = 'Remove';
$string['apiendpoint_name_label'] = 'Name';
$string['apiendpoint_name_placeholder'] = 'Endpoint name (optional)';
$string['apiendpoint_url_label'] = 'Endpoint URL';
$string['apiendpoint_key_label'] = 'API Key';
$string['apiendpoint_model_label'] = 'Model';
$string['api_endpoint_selector_hint'] = 'Tip: Endpoints added here will automatically appear in the "Active Endpoint" dropdown above.';

// Direct backend error strings
$string['direct_config_incomplete'] = 'Direct API configuration is incomplete. Please configure API endpoint, key, and model in settings.';
$string['direct_api_error'] = 'AI API request failed';
$string['direct_invalid_response'] = 'AI returned an invalid response.';
$string['direct_empty_response'] = 'AI returned an empty response.';
$string['direct_truncated'] = 'Response truncated (finish_reason=length)';

$string['defaultprompt'] = 'You are an expert programming tutor assisting a student with a programming exercise. Your role is to help the student understand the problem, identify issues in their code, and guide them toward a solution without giving away the complete answer directly. Provide clear explanations, point out specific issues, and suggest improvements. When appropriate, ask guiding questions to help the student learn.';
$string['sendmessage'] = 'Send message';
$string['resize_panel'] = 'Drag to resize panel';
$string['questionlabel'] = 'Question:';
$string['question_select_placeholder'] = 'Select a question...';

// Strategy buttons
$string['strategy_question_analysis'] = 'Problem Analysis';
$string['strategy_idea_organization'] = 'Idea Organization';
$string['strategy_code_debugging'] = 'Code Debugging';
$string['strategy_send_student_code'] = 'Send student code';
$string['strategy_send_test_results'] = 'Send test results and feedback';
$string['free'] = 'Free Chat';

// Default prompts for each strategy
$string['default_prompt_question_analysis'] = 'You are an expert programming tutor. Please provide a comprehensive analysis of the following problem, including: 1) Problem requirements and objectives 2) Input/output format 3) Key knowledge points 4) Solution approach and algorithm design 5) Edge cases and special conditions. Help the student deeply understand the problem.';
$string['default_prompt_idea_organization'] = 'You are an expert programming tutor. Help the student organize their thoughts and provide directional suggestions and a thinking framework for solving this problem. Do not give away the complete code directly. Instead, use guiding questions, step-by-step hints, and algorithmic insights to inspire the student to find the solution themselves.';
$string['default_prompt_code_debugging'] = 'You are an expert programming tutor helping debug a student\'s code. Please: 1) Analyze the issues in the code (syntax errors, logic errors, edge cases, etc.) 2) Explain why these are problems 3) Provide specific suggestions for fixing them 4) If test results or feedback are provided, incorporate them into your analysis. Guide the student to understand and fix the issues rather than rewriting the entire code.';

// Strategy prompts settings
$string['strategy_prompts_heading'] = 'Strategy Prompts';
$string['strategy_prompts_heading_desc'] = 'Configure the prompts used by each strategy mode. You can use placeholders: {questiontext}, {studentanswer}, {testresults}, {programminglanguage}.';
$string['prompt_question_analysis_desc'] = 'The prompt used in "Problem Analysis" mode.';
$string['prompt_idea_organization_desc'] = 'The prompt used in "Idea Organization" mode.';
$string['prompt_code_debugging_desc'] = 'The prompt used in "Code Debugging" mode.';

$string['filterheading'] = 'Prompt filtering';
$string['filterheadingdesc'] = 'Configure filtering rules to control what content is sent to the AI and what responses are shown to students.';

$string['inputblocklist'] = 'Input block list';
$string['inputblocklistdesc'] = 'Keywords or regex patterns that will block the user message from being sent to the AI. One item per line. If matched, the user will see a generic message instead. Lines starting with / are treated as regex patterns (e.g. /bypass/i).';

$string['inputsanitize'] = 'Input sanitize rules';
$string['inputsanitizedesc'] = 'Keywords or regex patterns to replace in user input before sending to the AI. Format: "search|replace" (one per line). Lines starting with / are treated as regex. If no replace is given, "***" is used. Example: ignore previous instructions|';

$string['outputblocklist'] = 'Output block list';
$string['outputblocklistdesc'] = 'Keywords or regex patterns to replace in AI responses before showing to the student. Format: "search|replace" (one per line). Lines starting with / are treated as regex. If no replace is given, "***" is used.';

$string['interceptionrules'] = 'Interception rules';
$string['interceptionrulesdesc'] = 'Rules that intercept user messages and return a predefined response without calling the AI. Each rule block is separated by "---". Format per block:
trigger: keyword_or_regex
mode: keyword (default) or regex
response: The predefined response text

Multi-line trigger notes:
- mode=keyword: each trigger line is checked independently (OR logic), any match triggers
- mode=regex: multi-line triggers are joined with | to form a regex alternation

Example:
trigger: give me the answer
mode: keyword
response: I cannot provide direct answers. Let me guide you through the problem instead.
---
trigger: /tell me the solution/i
mode: regex
response: As a programming tutor, I help you learn rather than give solutions.';

$string['filter_blocked_keyword'] = 'Your message contains content that cannot be processed. Please rephrase your question.';
$string['filter_blocked_regex'] = 'Your message was blocked by content filtering rules. Please rephrase your question.';
$string['invalidquestionid'] = 'Invalid question ID.';
$string['noquestionsfound'] = 'No questions found';

// ===================== Dual-layer Prompt Architecture =====================
$string['default_user_prompt_template'] = '';

// Teaching principles configuration
$string['teaching_principles_heading'] = 'Teaching Principles';
$string['teaching_principles_heading_desc'] = 'Configure custom teaching principles that will replace the default hardcoded principles. If left empty, the built-in principles will be used. Supports placeholders: {{questiontext}}, {{studentanswer}}, {{testresults}}, {{programminglanguage}}.';
$string['teaching_principles'] = 'Teaching Principles (Chinese)';
$string['teaching_principles_desc'] = 'Custom teaching principles for the Chinese language interface. Leave empty to use the built-in default. These replace the hardcoded teaching principles in the system prompt. Supports placeholders: {{questiontext}}, {{studentanswer}}, {{testresults}}, {{programminglanguage}}.';
$string['teaching_principles_en'] = 'Teaching Principles (English)';
$string['teaching_principles_en_desc'] = 'Custom teaching principles for the English language interface. Leave empty to use the built-in default. These replace the hardcoded teaching principles in the system prompt. Supports placeholders: {{questiontext}}, {{studentanswer}}, {{testresults}}, {{programminglanguage}}.';

// System Prompt - Identity Setting
$string['identity_setting_heading'] = 'Identity Setting';
$string['identity_setting_heading_desc'] = 'Configure the AI assistant\'s identity that will replace the built-in default. Leave empty to use the default identity (high school IT teacher). Supports placeholders: {{questiontext}}, {{studentanswer}}, {{testresults}}, {{programminglanguage}}.';
$string['identity_setting'] = 'Identity Setting (Chinese)';
$string['identity_setting_desc'] = 'Custom identity setting for the Chinese language interface. Leave empty to use the built-in default. These replace the default identity in the system prompt. Supports placeholders: {{questiontext}}, {{studentanswer}}, {{testresults}}, {{programminglanguage}}.';
$string['identity_setting_en'] = 'Identity Setting (English)';
$string['identity_setting_en_desc'] = 'Custom identity setting for the English language interface. Leave empty to use the built-in default. These replace the default identity in the system prompt. Supports placeholders: {{questiontext}}, {{studentanswer}}, {{testresults}}, {{programminglanguage}}.';

// System Prompt - Safety Boundaries
$string['safety_boundaries_heading'] = 'Safety Boundaries';
$string['safety_boundaries_heading_desc'] = 'Configure the AI assistant\'s safety boundaries that will replace the built-in defaults. Leave empty to use built-in safety boundaries. Supports placeholders: {{questiontext}}, {{studentanswer}}, {{testresults}}, {{programminglanguage}}.';
$string['safety_boundaries'] = 'Safety Boundaries (Chinese)';
$string['safety_boundaries_desc'] = 'Custom safety boundaries for the Chinese language interface. Leave empty to use the built-in defaults. These replace the hardcoded safety boundaries in the system prompt. Supports placeholders: {{questiontext}}, {{studentanswer}}, {{testresults}}, {{programminglanguage}}.';
$string['safety_boundaries_en'] = 'Safety Boundaries (English)';
$string['safety_boundaries_en_desc'] = 'Custom safety boundaries for the English language interface. Leave empty to use the built-in defaults. These replace the hardcoded safety boundaries in the system prompt. Supports placeholders: {{questiontext}}, {{studentanswer}}, {{testresults}}, {{programminglanguage}}.';

// Combined Strategy Prompts (Help Type + Strategy Instructions + Response Requirements)
$string['strategy_prompt_free'] = 'Free Chat Combined Strategy Prompt';
$string['strategy_prompt_free_desc'] = 'Combined strategy prompt for Free Chat mode, including help type description, strategy instructions, and response requirements. Leave empty to use the built-in defaults. Supports placeholders: {{questiontext}}, {{studentanswer}}, {{testresults}}, {{programminglanguage}}.';
$string['strategy_prompt_question_analysis'] = 'Problem Analysis Combined Strategy Prompt';
$string['strategy_prompt_question_analysis_desc'] = 'Combined strategy prompt for Problem Analysis mode, including help type description, strategy instructions, and response requirements. Leave empty to use the built-in defaults. Supports placeholders: {{questiontext}}, {{studentanswer}}, {{testresults}}, {{programminglanguage}}.';
$string['strategy_prompt_idea_organization'] = 'Idea Organization Combined Strategy Prompt';
$string['strategy_prompt_idea_organization_desc'] = 'Combined strategy prompt for Idea Organization mode, including help type description, strategy instructions, and response requirements. Leave empty to use the built-in defaults. Supports placeholders: {{questiontext}}, {{studentanswer}}, {{testresults}}, {{programminglanguage}}.';
$string['strategy_prompt_code_debugging'] = 'Code Debugging Combined Strategy Prompt';
$string['strategy_prompt_code_debugging_desc'] = 'Combined strategy prompt for Code Debugging mode, including help type description, strategy instructions, and response requirements. Leave empty to use the built-in defaults. Supports placeholders: {{questiontext}}, {{studentanswer}}, {{testresults}}, {{programminglanguage}}.';

// Prompt build mode configuration
$string['prompt_build_mode_heading'] = 'Prompt Build Mode';
$string['prompt_build_mode_heading_desc'] = 'Choose how prompts are constructed. Different modes affect the message format sent to the AI backend.';
$string['prompt_build_mode'] = 'Prompt Build Mode';
$string['prompt_build_mode_desc'] = 'Choose how prompts are constructed. Three-tier mode sends system prompt, context, and user message as separate roles; Two-tier mode sends system prompt and user message as separate roles.';
$string['prompt_build_mode_original'] = 'Three-Tier Architecture (System + Context + User)';
$string['prompt_build_mode_original_desc'] = 'Sends identity, teaching principles, safety boundaries etc. as System prompt, knowledge base context as Context prompt, and the user current question as User message, each as a separate role.';
$string['prompt_build_mode_dual'] = 'Two-Tier Architecture (System + User)';
$string['prompt_build_mode_dual_desc'] = 'Sends identity, teaching principles, safety boundaries etc. as a system role message, merges knowledge base context and user current question into a user role message. Only supports the direct backend.';

// User prompt components (dual mode)
$string['user_prompt_strategy'] = 'Strategy Prompt';
$string['user_prompt_strategy_desc'] = 'Includes help type, strategy instructions, and response requirements.';
$string['user_prompt_question'] = 'Question Content';
$string['user_prompt_question_desc'] = 'Includes problem requirements, programming language, etc.';
$string['user_prompt_history'] = 'Conversation History';
$string['user_prompt_history_desc'] = 'Includes summaries of previous conversations.';
$string['user_prompt_code'] = 'Student Code and Run Results';
$string['user_prompt_code_desc'] = 'Includes student-submitted code and evaluation results.';
$string['user_prompt_current'] = 'Current Question';
$string['user_prompt_current_desc'] = "The student's current question or request.";

$string['privacy:metadata:local_ai_prog_assist_conversation'] = 'AI programming assistant conversation records.';
$string['privacy:metadata:local_ai_prog_assist_conversation:userid'] = 'The user ID.';
$string['privacy:metadata:local_ai_prog_assist_conversation:questionid'] = 'The question ID (0 for free mode).';
$string['privacy:metadata:local_ai_prog_assist_conversation:actiontype'] = 'The strategy type (free/question_analysis/idea_organization/code_debugging).';
$string['privacy:metadata:local_ai_prog_assist_conversation:starttime'] = 'The conversation start time.';
$string['privacy:metadata:local_ai_prog_assist_conversation:endtime'] = 'The last active time.';
$string['privacy:metadata:local_ai_prog_assist_conversation:contextid'] = 'The page context ID.';
$string['privacy:metadata:local_ai_prog_assist_conversation:courseid'] = 'The course ID.';
$string['privacy:metadata:local_ai_prog_assist_conversation:cmid'] = 'The course module ID.';
$string['privacy:metadata:local_ai_prog_assist_conversation:pagetype'] = 'The page type.';
$string['privacy:metadata:local_ai_prog_assist_conversation:quizid'] = 'The quiz ID.';
$string['privacy:metadata:local_ai_prog_assist_conversation:messagecount'] = 'The message count.';
$string['privacy:metadata:local_ai_prog_assist_conversation:metadata'] = 'Extended metadata (model, response time, token usage, etc.).';

$string['privacy:metadata:local_ai_prog_assist_message'] = 'AI programming assistant message records.';
$string['privacy:metadata:local_ai_prog_assist_message:conversationid'] = 'The parent conversation ID.';
$string['privacy:metadata:local_ai_prog_assist_message:role'] = 'The message role (user/assistant).';
$string['privacy:metadata:local_ai_prog_assist_message:content'] = 'The message content.';
$string['privacy:metadata:local_ai_prog_assist_message:timestamp'] = 'The message timestamp.';
$string['privacy:metadata:local_ai_prog_assist_message:metadata'] = 'The message metadata (model, response time, success flag, etc.).';

// View display modes
$string['viewmode_switch'] = 'Switch display mode';
$string['viewmode_floating'] = 'Floating mode';
$string['viewmode_sidebar'] = 'Sidebar mode';

$string['sidebar_resize'] = 'Drag to adjust sidebar width';

$string['stop_generating'] = 'Stop generating';
$string['expand'] = 'Expand';
$string['collapse'] = 'Collapse';
$string['copy'] = 'Copy';
$string['copied'] = 'Copied!';
$string['ai_sender'] = 'AI';
$string['me'] = 'Me';
$string['ai_feedback_loading'] = 'AI is analyzing...';
$string['request_error'] = 'Request failed, please try again later.';
$string['retry'] = 'Retry';
$string['question_analysis_hint'] = 'Send empty to analyze the current problem...';
$string['idea_organization_hint'] = 'Send empty to organize ideas for this problem...';
$string['code_debugging_hint'] = 'Send empty to debug the current code...';
$string['code_debugging_requires_check'] = 'Please click the "Check" button first to run your code and get test results before using Code Debugging';
$string['back_to_strategies'] = 'Back';
$string['strategy_select_hint'] = 'Please select a strategy mode to start analysis...';
$string['strategy_select_reminder'] = 'Please click the strategy mode button above (Problem Analysis/Idea Organization/Code Debugging) to start analysis';

// Usage statistics
$string['overview_page_title'] = 'AI Usage Overview';
$string['overview_kpi_total_conversations'] = 'Total Conversations';
$string['overview_kpi_unique_users'] = 'Active Users';
$string['overview_kpi_unique_questions'] = 'Questions Used';
$string['overview_kpi_unique_pages'] = 'Free Pages';
$string['overview_daily_trend'] = 'Daily Request Trend';
$string['overview_mode_distribution'] = 'Mode Distribution';
$string['overview_model_distribution'] = 'Model Distribution';
$string['overview_quiz_vs_free'] = 'Quiz Mode vs Free Mode';
$string['overview_quiz_mode'] = 'Quiz Mode';
$string['overview_free_mode'] = 'Free Mode';
$string['overview_top_questions'] = 'Top Questions';
$string['overview_top_students'] = 'Top Students';
$string['overview_top_free_pages'] = 'Top Free Pages';
$string['overview_quick_links'] = 'Quick Links';
$string['overview_no_data'] = 'No AI usage data in the selected period.';
$string['stats_page_title'] = 'AI Usage Statistics';
$string['stats_page_desc'] = 'View detailed statistics on student usage of the AI Programming Assistant.';
$string['stats_quiz_select'] = 'Select Quiz';
$string['stats_quiz'] = 'Quiz';
$string['stats_all_quizzes'] = 'All Quizzes';
$string['stats_no_quizzes'] = 'No quizzes with usage records found.';
$string['stats_student_usage'] = 'Student Usage';
$string['stats_student_usage_desc'] = 'AI usage statistics for each student in the selected quiz.';
$string['stats_student'] = 'Student';
$string['stats_username'] = 'Username';
$string['stats_total_calls'] = 'Total Calls';
$string['stats_modes_used'] = 'Modes Used';
$string['stats_question_mode_quiz'] = 'Question Mode Usage (Current Quiz)';
$string['stats_question_mode_quiz_desc'] = 'Usage statistics by question and mode for the selected quiz.';
$string['stats_question_mode_overall'] = 'Question Mode Usage (Overall)';
$string['stats_question_mode_overall_desc'] = 'Usage statistics by question and mode across all quizzes.';
$string['stats_question'] = 'Question';
$string['stats_mode'] = 'Mode';
$string['stats_count'] = 'Count';
$string['stats_no_data'] = 'No data available.';
$string['stats_total_requests'] = 'Total Requests';
$string['quiz_stats_total_chats'] = 'Total Chats';
$string['stats_success_rate'] = 'Success Rate';
$string['stats_avg_responsetime'] = 'Avg Response Time';
$string['stats_last_interaction'] = 'Last Interaction';
$string['stats_unique_users'] = 'Unique Users';
$string['stats_unique_questions'] = 'Unique Questions';
$string['stats_model_usage'] = 'Model Usage';
$string['stats_mode_usage'] = 'Analysis Mode Usage';
$string['stats_top_questions'] = 'Top Questions';
$string['stats_user'] = 'User';
$string['stats_model'] = 'Model';
$string['stats_success'] = 'Success';
$string['stats_failed'] = 'Failed';
$string['stats_unique_conversations'] = 'Unique Conversations';
$string['stats_time_from'] = 'From Date';
$string['stats_time_to'] = 'To Date';
$string['stats_time'] = 'Last Interaction';
$string['stats_search_btn'] = 'Search';
$string['stats_question_name'] = 'Question Name';
$string['stats_total'] = 'Total';
$string['export_csv'] = 'Export CSV';
$string['nav_quiz_stats_inline'] = 'AI Usage Statistics';
$string['question_detail_total_students'] = 'Total Students';

$string['quiz_stats_page_title'] = 'AI Quiz Statistics';
$string['quiz_stats_quizid'] = 'Quiz ID';
$string['quiz_stats_quiz_name'] = 'Quiz Name';
$string['quiz_stats_tab_overview'] = 'Overview';
$string['quiz_stats_tab_question_followups'] = 'Questions View';
$string['quiz_stats_tab_student_followups'] = 'Students View';
$string['quiz_stats_question_usage'] = 'Question AI Usage';
$string['quiz_stats_invalid_quiz'] = 'Invalid quiz ID.';
$string['quiz_stats_require_rest'] = 'No AI usage data found. Students need to submit quiz attempts first.';
$string['quiz_stats_students_count'] = 'students';
$string['quiz_stats_questions_count'] = 'questions';
$string['quiz_stats_clear_filter'] = 'Clear Filter';
$string['quiz_stats_search_hint'] = 'Please enter a Quiz ID to view AI usage statistics.';
$string['quiz_stats_group'] = 'Group';
$string['quiz_stats_all_groups'] = 'All Groups';
$string['student_usage_group_filter'] = 'Group Filter';
$string['student_usage_group_all'] = 'All Groups';
$string['student_usage_group_filtered'] = 'Filtered by group';
$string['backtoquiz'] = 'Back to Quiz';

$string['question_detail_page_title'] = 'Question AI Usage Detail';
$string['question_detail_action'] = 'Action';
$string['question_detail_view_detail'] = 'View Detail';
$string['question_detail_invalid'] = 'Invalid quiz or question ID.';
$string['question_detail_student_view'] = 'Student View';
$string['question_detail_source_code'] = 'Source Code';
$string['question_detail_student_question'] = 'Student Question';
$string['question_detail_feedback'] = 'AI Feedback';
$string['question_detail_conversations'] = 'Conversation History';
$string['question_detail_user'] = 'User';
$string['question_detail_assistant'] = 'Assistant';
$string['question_detail_student_usage'] = 'Student Usage';
$string['backtostats'] = 'Back to Stats';

$string['stats_action'] = 'Action';
$string['mode_question_analysis'] = 'Problem Analysis';
$string['mode_idea_organization'] = 'Idea Organization';
$string['mode_code_debugging'] = 'Code Debugging';
$string['mode_free'] = 'Free Chat';

// AI model thinking settings
$string['enable_thinking'] = 'Enable AI model thinking';
$string['enable_thinking_desc'] = 'Enable the thinking (reasoning) capability for AI models that support reasoning_content, such as Qwen and DeepSeek-R1. When enabled, the model performs deep reasoning before answering, and the thinking process is displayed. In thinking mode, max_tokens is automatically increased by 3x to accommodate both thinking and response content. Uncheck to disable thinking for better latency and token efficiency.';
$string['thinking_label'] = 'Thinking';
$string['thinking_in_progress'] = 'AI is thinking...';
$string['thinking_done'] = 'Thinking complete';
$string['thinking_too_long'] = 'The AI model thinking process consumed all output tokens and could not generate a response. Please increase max_tokens or disable thinking and try again.';
$string['context_confirmation'] = 'I understand the current context. Please ask your question.';

// Free mode stats
$string['free_stats_page_title'] = 'Free Chat AI Statistics';
$string['free_stats_page_desc'] = 'Free chat mode has no question ID association. Records of students asking AI questions anytime during programming exercises.';
$string['stats_daily_trend'] = 'Daily Trend';
$string['stats_date'] = 'Date';
$string['stats_preset_days'] = 'Last {$a} days';
$string['stats_daily_total_days'] = 'Total days:';
$string['stats_date_presets'] = 'Date presets';
$string['stats_daily_stats_pagination'] = 'Daily stats pagination';
$string['stats_questions_pagination'] = 'Questions pagination';
$string['stats_student_stats_pagination'] = 'Student stats pagination';
$string['stats_students_pagination'] = 'Students pagination';

// Free chat detail traceability
$string['free_chat_detail_page_title'] = 'Free Chat Records';
$string['free_chat_detail_back'] = 'Back to Free Stats';
$string['free_chat_detail_total'] = 'Total Records';
$string['free_chat_detail_view'] = 'View Chat Records';
$string['free_chat_detail_source_code'] = 'View student code';
$string['free_chat_detail_clear_filter'] = 'Clear page filter';
$string['free_chat_detail_filter_page'] = 'Click to view records on this page';
$string['free_stats_pagetype_distribution'] = 'Page Type Distribution';
$string['free_stats_pagetype'] = 'Page Type';
$string['free_stats_pages'] = 'Pages';

// Recent free chats (all students, not grouped by student)
$string['recent_free_chats_page_title'] = 'Recent Free Chat Records';
$string['recent_free_chats_page_desc'] = 'Shows the most recent free chat submissions across all students, sorted by submission time. Not aggregated by student.';
$string['recent_free_chats_back'] = 'Back to Stats';
$string['recent_free_chats_total'] = 'Total Records';
$string['recent_free_chats_student'] = 'Student';
$string['recent_free_chats_filter_student'] = 'Filter by student name';
$string['recent_free_chats_clear_filter'] = 'Clear page filter';
$string['recent_free_chats_filter_page'] = 'Click to view records on this page';
$string['recent_free_chats_view_student'] = 'View this student\'s chat records';
$string['recent_free_chats_export_csv_link'] = 'Recent Free Chat Records';
$string['recent_free_chats_view_detail'] = 'View chat details';

// Recent Quiz Chats (with questionid)
$string['recent_quiz_chats_page_title'] = 'Recent AI Usage Records';
$string['recent_quiz_chats_page_desc'] = 'Shows the most recent AI usage records across all students, grouped by question and user, sorted by last interaction time (newest first).';
$string['recent_quiz_chats_total'] = 'Total Records';
$string['recent_quiz_chats_student'] = 'Student';
$string['recent_quiz_chats_view_detail'] = 'View chat details';
$string['recent_quiz_chats_view_mode_chats'] = 'View this student\'s conversations for this question in this mode (earliest first)';
$string['recent_quiz_chats_view_mode_detail'] = 'View this student\'s AI chat details for this question in this mode (earliest first, with prev/next)';

// conversation_detail.php - 筛选导航条
$string['conversation_detail_filtered_scope'] = 'Filtered (student + question + mode)';
$string['conversation_detail_prev'] = 'Previous';
$string['conversation_detail_next'] = 'Next';
$string['conversation_detail_show_all'] = 'Show all sessions';
$string['recent_quiz_chats_filter_quiz'] = 'Filtered by quiz';
$string['recent_quiz_chats_export_csv'] = 'Export CSV';
$string['recent_quiz_chats_questionid'] = 'Question ID';
$string['recent_quiz_chats_questionname'] = 'Question Name';
$string['recent_quiz_chats_quizid'] = 'Quiz ID';
$string['recent_quiz_chats_quizname'] = 'Quiz Name';
$string['recent_quiz_chats_course'] = 'Course';
$string['recent_quiz_chats_view_quiz'] = 'View quiz';
$string['recent_quiz_chats_mode'] = 'Strategy Mode';

// Student search
$string['stats_student_search_placeholder'] = 'Search student name...';

// Chat detail (with questionid)
$string['question'] = 'Question';
$string['chat_detail_page_title'] = 'Quiz Chat Records';
$string['chat_detail_back'] = 'Back to Stats';
$string['chat_detail_total'] = 'Total Records';
$string['chat_detail_view'] = 'View Chat Records';

// Student questions list (intermediate page)
$string['student_questions_page_title'] = 'Student Questions';
$string['student_questions_back'] = 'Back to Stats';
$string['student_questions_total'] = 'Questions Used';
$string['student_questions_interactions'] = 'Total Interactions';
$string['student_questions_last_interaction'] = 'Last Interaction';
$string['student_questions_view'] = 'View student questions';

// Free mode student pages list (intermediate page)
$string['student_free_pages_page_title'] = 'Student Free Mode Pages';
$string['student_free_pages_back_to_stats'] = 'Back to Free Stats';
$string['student_free_pages_back'] = '← Back to Pages';
$string['student_free_pages_total'] = 'Pages Used';
$string['student_free_pages_interactions'] = 'Total Interactions';
$string['student_free_pages_pagetype'] = 'Page Type';
$string['student_free_pages_pageid'] = 'Page ID';
$string['student_free_pages_pagename'] = 'Page Name';
$string['student_free_pages_course'] = 'Course';
$string['student_free_pages_view'] = 'View student pages';
$string['student_free_pages_view_page'] = 'View page';
$string['student_free_pages_title'] = 'Free Mode Page List';

// General
// Conversation content truncation (migrated from hardcoded strings in completion.php)
$string['code_truncated'] = '// ... [Code too long, truncated to {$a->maxlen} chars, original {$a->codelen} chars]';

// Stats (new)
$string['stats_student_count'] = 'Student count';

// Error messages (migrated from hardcoded strings)
$string['error_json_encode'] = 'Request construction failed: JSON encoding error';
$string['error_curl_failure'] = 'Service connection failed (cURL {$a->errno}): {$a->error}';
$string['error_http_status'] = 'AI service returned HTTP {$a}';
$string['error_config_incomplete'] = 'AI backend configuration is incomplete. Please contact the administrator.';
$string['error_api_connection'] = 'Failed to connect to the AI service: {$a}';
$string['error_api_response'] = 'Invalid response received from the AI service.';
$string['error_api_error'] = 'The AI service returned an error.';

// Test result formatting
$string['testresult_error_raw'] = 'Error output:';
$string['testresult_outcome'] = 'Overall result: {$a}';
$string['testresult_precheck'] = 'Note: This was a Precheck run.';
$string['testresult_error_msg'] = 'Error message: {$a}';
$string['testresult_summary'] = 'Summary: {$a}';
$string['testresult_cases'] = 'Test cases:';
$string['testresult_passed'] = 'Test results: {$a->passed}/{$a->total} test cases passed.';
$string['testresult_unrecognized'] = 'Test results:';
$string['error_empty_response'] = 'AI returned an empty response';
$string['error_empty_messages_array'] = 'Message array is empty. Please send valid message content.';
$string['error_no_valid_user_message'] = 'No valid user message found.';
$string['error_empty_response_retry'] = 'AI service returned an empty response. Please try again later.';
$string['error_service_unavailable'] = 'AI service is temporarily unavailable. Please try again later.';
$string['error_empty_request_body'] = 'AI service received an empty request. Please check your input.';
$string['error_internal_error'] = 'Internal service error: {$a}';
$string['error_internal_error_safe'] = 'Internal service error, please try again later. If the problem persists, contact the administrator with this timestamp: {$a}';
$string['error_ai_request_failed'] = 'AI request failed, please try again later.';
$string['error_invalid_json_body'] = 'Invalid JSON body';
$string['error_invalid_sesskey'] = 'Invalid session key';
$string['error_not_streaming_mode'] = 'Response mode is not set to streaming';
$string['error_not_blocking_mode'] = 'Response mode is not set to blocking. Please refresh the page.';
$string['error_not_enabled'] = 'AI Programming Assistant is not enabled.';
$string['error_invalid_context'] = 'Invalid context: the specified context does not exist or you do not have access.';
$string['question_content_not_found'] = '[Question content not found, please check if the page loaded correctly]';
$string['programming_language_label'] = 'Programming language: {$a}';
$string['embedded_questions_intro'] = 'The current page contains {$a} embedded question(s) (embedded via the embed question feature). Their content is as follows:';
$string['embedded_question_header'] = '[Embedded question {$a->number}] {$a->name} (Question type: {$a->qtype})';
$string['all_embedded_questions'] = 'All embedded questions';
$string['unknown_question_id'] = 'Question #{$a}';
$string['unknown_quiz_id'] = 'Quiz #{$a}';

// Knowledge Base (RAG)
$string['knowledgebase_heading'] = 'Textbook Knowledge Base (RAG)';
$string['knowledgebase_heading_desc'] = 'Upload textbooks (PDF/DOCX/MD/TXT) to build a knowledge base. The AI will automatically retrieve relevant content from the knowledge base when answering.';
$string['knowledgebase_enable'] = 'Enable knowledge base';
$string['knowledgebase_enable_desc'] = 'When enabled, the AI will automatically search the textbook knowledge base for relevant content when answering student questions.';
$string['kb_max_context_chunks'] = 'Max KB context chunks';
$string['kb_max_context_chunks_desc'] = 'Maximum number of knowledge base chunks to include in the prompt. Default: 3.';
$string['knowledgebase_manage_title'] = 'Knowledge Base Management';
$string['knowledgebase_manage_desc'] = 'Upload textbook files (PDF, DOCX, Markdown, TXT) to the knowledge base, or manage existing files.';
$string['knowledgebase_manage_button'] = 'Manage Knowledge Base';
$string['knowledgebase_upload_heading'] = 'Upload Textbook File';
$string['knowledgebase_upload_button'] = 'Upload & Parse';
$string['knowledgebase_file_name'] = 'Textbook Name';
$string['knowledgebase_file_filename'] = 'File Name';
$string['knowledgebase_file_size'] = 'Size';
$string['knowledgebase_file_chunks'] = 'Chunks';
$string['knowledgebase_file_timecreated'] = 'Upload Time';
$string['knowledgebase_file_header_original'] = 'Original File';
$string['knowledgebase_file_hasfile'] = '✓ Download';
$string['knowledgebase_file_not_saved'] = 'Not available';
$string['knowledgebase_file_download'] = 'Download Original File';
$string['knowledgebase_file_preview'] = 'File Preview';
$string['knowledgebase_preview_not_available'] = 'Online preview is not supported for this format. Please download to view.';
$string['knowledgebase_name_empty'] = 'Name cannot be empty';
$string['knowledgebase_no_external_dependency'] = 'No external dependency required';
$string['knowledgebase_file_list'] = 'Uploaded Textbooks';
$string['knowledgebase_no_files'] = 'No textbook files uploaded yet.';
$string['knowledgebase_search_placeholder'] = 'Search textbook name or filename...';
$string['knowledgebase_search_clear'] = 'Clear search';
$string['knowledgebase_no_search_results'] = 'No matching textbook files found.';
$string['knowledgebase_perpage'] = 'Per page:';
$string['knowledgebase_file_tag'] = 'Textbook source tag';
$string['knowledgebase_tag_all'] = 'All tags';
$string['knowledgebase_tag_filter'] = 'Tag filter';
$string['knowledgebase_batch_delete_button'] = 'Batch Delete';
$string['knowledgebase_batch_delete_success'] = 'Successfully deleted {$a} textbook files.';
$string['knowledgebase_confirm_batch_delete'] = 'Are you sure you want to delete the selected {$a} textbook files? This action cannot be undone.';
$string['knowledgebase_rename_failed'] = 'Rename failed.';
$string['knowledgebase_file_header_tag'] = 'Source Tag';
$string['knowledgebase_select_file'] = 'Select Textbook File (PDF / DOCX / MD / TXT)';
$string['knowledgebase_upload_success'] = 'Textbook uploaded successfully, parsed and stored in the knowledge base.';
$string['knowledgebase_file_deleted'] = 'Knowledge base file deleted.';
$string['knowledgebase_confirm_delete'] = 'Are you sure you want to delete the textbook "{$a}"? This action cannot be undone.';
$string['knowledgebase_file_duplicate'] = 'A file with the same content already exists in the knowledge base.';
$string['knowledgebase_pdf_parse_failed'] = 'PDF text extraction failed. Please ensure the file is a text-based PDF (not a scanned document).';
$string['knowledgebase_pdf_empty'] = 'PDF parsing result is empty. Please check the file content.';
$string['knowledgebase_stats_files'] = 'Textbook Files';
$string['knowledgebase_stats_chunks'] = 'Total Chunks';
$string['knowledgebase_stats_parsers'] = 'Parsing Engines';
$string['knowledgebase_pdftotext_available'] = 'PDF parsing: pdftotext available';
$string['knowledgebase_pdftotext_unavailable'] = 'PDF parsing: pdftotext unavailable';
$string['knowledgebase_pdftotext_missing'] = 'The pdftotext tool is not installed or unavailable. Please ask an administrator to install poppler-utils.';
$string['knowledgebase_docx_available'] = 'DOCX parsing: ZipArchive available';
$string['knowledgebase_docx_unavailable'] = 'DOCX parsing: ZipArchive unavailable, please install the PHP zip extension.';
$string['knowledgebase_docx_parse_failed'] = 'DOCX parsing failed. Please ensure the file is a valid Word document.';
$string['knowledgebase_docx_empty'] = 'DOCX file content is empty. Please check the file.';
$string['knowledgebase_docx_zip_missing'] = 'DOCX parsing requires PHP ZipArchive support. Please ask an administrator to install php-zip.';
$string['knowledgebase_txt_parse_failed'] = 'Text file reading failed. Please check file permissions.';
$string['knowledgebase_txt_empty'] = 'File content is empty. Please check the file.';
$string['knowledgebase_unsupported_format'] = 'Unsupported file format: {$a}. Only PDF, DOCX, Markdown (.md) and TXT files are supported.';
$string['knowledgebase_view_title'] = 'View Textbook: {$a}';
$string['knowledgebase_link_title'] = 'View in Knowledge Base: {$a} (opens in new tab)';
$string['knowledgebase_link_title_chunk'] = 'View {$a->name} chunk {$a->chunk} in Knowledge Base (opens in new tab)';
$string['knowledgebase_file_info'] = 'File Information';
$string['knowledgebase_file_content'] = 'Textbook Content';
$string['knowledgebase_no_chunks'] = 'This file has no text chunks.';
$string['knowledgebase_chunk_label'] = 'Chunk {$a}';
$string['knowledgebase_file_not_found'] = 'The specified knowledge base file was not found.';
$string['knowledgebase_rebuild_button'] = 'Rebuild Index';
$string['knowledgebase_confirm_rebuild'] = 'Are you sure you want to rebuild the inverted index for all knowledge base files? This will clear the existing index and rebuild using the latest tokenizer. It may take several minutes.';
$string['knowledgebase_rebuild_success'] = 'Successfully rebuilt inverted index for {$a} knowledge base file(s).';
$string['knowledgebase_rebuild_partial'] = 'Index rebuild partially completed: {$a->indexed} succeeded, {$a->errors} failed ({$a->errornames}).';
$string['knowledgebase_batch_upload_heading'] = 'Batch Upload Textbook Files';
$string['knowledgebase_batch_select_files'] = 'Select multiple files (PDF / DOCX / MD / TXT, up to 20)';
$string['knowledgebase_batch_upload_button'] = 'Batch Upload & Parse';
$string['knowledgebase_batch_upload_success'] = 'Batch upload complete: {$a->success} succeeded, {$a->skipped} duplicates skipped, {$a->errors} failed.';
$string['knowledgebase_batch_upload_all_skipped'] = 'All {$a} uploaded file(s) already exist, no duplicates were added.';
$string['knowledgebase_file_rebuild_button'] = 'Rebuild';
$string['knowledgebase_file_confirm_rebuild'] = 'Are you sure you want to rebuild the inverted index for "{$a}"?';
$string['knowledgebase_file_rebuild_success'] = 'Successfully rebuilt inverted index for "{$a}".';
$string['knowledgebase_file_rebuild_failed'] = 'Failed to rebuild inverted index for "{$a}"';
$string['actions'] = 'Actions';

// Capability names
$string['ai_programming_assistant:use'] = 'Use the AI programming assistant';
$string['ai_programming_assistant:viewknowledgebase'] = 'View Knowledge Base';
$string['ai_programming_assistant:viewstats'] = 'View AI usage statistics';
$string['ai_programming_assistant:config'] = 'Configure the AI programming assistant';

$string['clear'] = 'Clear';
$string['pagination'] = 'Pagination';
$string['invalid_parameters'] = 'Invalid parameters';
$string['knowledgebase_upload_failed'] = 'File upload failed. Please select a file and try again.';

// Conversation stats
$string['conversation_list_page_title'] = 'Conversation List';
$string['conversation_list_page_desc'] = 'View all AI conversations sorted by time (newest first).';
$string['conversation_detail_page_title'] = 'Conversation Detail';
$string['conversation_list_student'] = 'Student';
$string['conversation_list_starttime'] = 'Start Time';
$string['conversation_list_messagecount'] = 'Messages';
$string['conversation_list_actiontype'] = 'Mode';
$string['conversation_list_pagetype'] = 'Page';
$string['conversation_list_context'] = 'Context';
$string['conversation_list_search_hint'] = 'Search student name or username';
$string['conversation_detail_messages'] = 'Messages';
$string['conversation_detail_course'] = 'Course';
$string['conversation_no_messages'] = 'No messages in this conversation.';
$string['conversation_no_data'] = 'No conversations found.';
$string['conversation_delete_button'] = 'Delete Selected';
$string['conversation_confirm_delete'] = 'Are you sure you want to delete the selected {$a} conversation(s)? This will also delete the corresponding messages and cannot be undone.';
$string['conversation_delete_success'] = 'Successfully deleted {$a} conversation(s).';
$string['conversation_delete_select_all'] = 'Select All';
$string['stats_question_delete_btn'] = 'Delete Selected Questions';
$string['stats_student_delete_btn'] = 'Delete Selected Users';
$string['stats_record_confirm_delete'] = 'Are you sure you want to delete all conversations associated with the selected {$a} record(s)? This action cannot be undone.';
$string['stats_record_delete_success'] = 'Successfully deleted {$a} associated conversation(s).';

// Stats page link tooltips
$string['stats_conversation_count'] = 'Conversations';
$string['stats_view_question_conversations'] = 'View conversations for this question';
$string['recent_quiz_chats_view_question_chats'] = 'View conversations for this question (earliest first)';
$string['stats_view_user_conversations'] = 'View conversations for this user';
$string['stats_view_user_questions'] = 'View questions for this user';

// Scheduled tasks
$string['task_cleanup'] = 'Clean up expired AI conversation records';
$string['log_retention_days'] = 'Log retention days';

$string['notset'] = 'Not set';
$string['log_retention_days_desc'] = 'Number of days to retain AI conversation logs. Records older than this will be automatically cleaned up by the scheduled task. Default: 30 days.';

// Asked questions page
$string['asked_questions_page_title'] = 'Student Questions';
$string['asked_questions_short'] = 'Asked';
$string['asked_questions_no_data'] = 'No questions found.';
$string['stats_all_modes'] = 'All strategies';
$string['stats_course'] = 'Course';
$string['unknown_course_id'] = 'Unknown course (ID: {$a})';
$string['csv_student'] = 'Student';
$string['csv_username'] = 'Username';
$string['csv_question'] = 'Question';
$string['csv_mode'] = 'Strategy';
$string['csv_question_content'] = 'Question content';
$string['csv_time'] = 'Asked at';
$string['csv_quiz'] = 'Quiz';
$string['csv_course'] = 'Course';
$string['free_stats_free_chat'] = 'Free chat';
$string['asked_questions_view_student'] = 'View this student\'s questions';
$string['asked_questions_view_question'] = 'View questions for this question';
$string['asked_questions_view_student_question'] = 'View this student\'s questions for this question';
$string['asked_questions_view_mode'] = 'View questions for this strategy';