<?php
/**
 * AI 编程助手 - AI 后端通信适配器
 *
 * 负责与不同 AI 后端进行通信：
 * 1. Moodle 核心 AI 子系统 (core_ai)
 * 2. AI 工具管理器 (ai_manager)
 * 3. 直接调用 OpenAI 兼容 API (direct)
 * 包含非流式和流式两种调用方式。
 *
 * @author zfl
 * @package    local_ai_programming_assistant
 */
namespace local_ai_programming_assistant;
defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

class ai_backend {

    /** 模型名称到 thinking 参数格式的映射配置 */
    private const MODEL_THINKING_CONFIG = [
        'qwen' => ['type' => 'enable_thinking', 'enabled' => true, 'disabled' => false],
        'deepseek' => ['type' => 'thinking_object', 'enabled' => ['type' => 'enabled'], 'disabled' => ['type' => 'disabled']],
    ];

    /** @var string 用户输入的消息 */
    private string $message;

    /** @var string 助手名称（用于对话格式化） */
    private string $assistantname;

    /** @var string API 端点 URL（请求级缓存） */
    private ?string $endpoint = null;

    /** @var string API 密钥（请求级缓存） */
    private ?string $apikey = null;

    /** @var string AI 模型名称（请求级缓存） */
    private ?string $model = null;

    /** @var int 最大 token 数（请求级缓存） */
    private ?int $maxtokens = null;

    /** @var float 温度参数（请求级缓存） */
    private ?float $temperature = null;

    /** @var bool 是否启用 thinking 模式（请求级缓存） */
    private ?bool $enableThinking = null;

    /** @var bool 是否启用 SSL 验证（请求级缓存） */
    private ?bool $usessl = null;

    /** @var array|null 多端点解析上下文（strategy/index/endpoint index） */
    private ?array $endpointctx = null;

    /** @var bool 静态调试日志守卫，避免重复记录 SSL 调试消息 */
    private static bool $ssldebuglogged = false;

    /**
     * @param string $message 用户输入的消息
     * @param string $assistantname 助手名称
     */
    public function __construct(string $message, string $assistantname) {
        $this->message = $message;
        $this->assistantname = $assistantname;
    }

    /**
     * 懒加载 direct 后端所需的所有配置（请求级缓存）
     *
     * 优先从多端点配置中解析活动端点；若未配置多端点或选择 "默认单端点"，
     * 则回退到 settings.php 中原有 apiendpoint/apikey/model 配置，保持向后兼容。
     */
    private function load_direct_config(): void {
        if ($this->endpoint !== null) {
            return;
        }
        $config = config_manager::get_plugin_config();

        // 1) 尝试解析多端点配置
        $resolved = config_manager::resolve_active_endpoint();
        if ($resolved !== null) {
            $this->endpoint = config_manager::normalize_endpoint($resolved['endpoint']);
            $this->apikey = $resolved['apikey'] !== '' ? $resolved['apikey'] : trim((string)($config->apikey ?? ''));
            $this->model = $resolved['model'] !== '' ? $resolved['model'] : (string)($config->model ?? '');
            $this->endpointctx = [
                'strategy' => $resolved['strategy'],
                'index' => $resolved['index'],
            ];
        } else {
            // 2) 回退到原有单端点配置
            $this->endpoint = config_manager::normalize_endpoint((string)($config->apiendpoint ?? ''));
            $this->apikey = trim((string)($config->apikey ?? ''));
            $this->model = (string)($config->model ?? '');
            $this->endpointctx = null;
        }

        $this->maxtokens = (int)($config->maxtokens ?? 0);
        $this->temperature = (float)($config->temperature ?? 0);
        $this->enableThinking = !empty($config->enable_thinking);
        $this->usessl = stripos($this->endpoint, 'https://') === 0;
        if (!$this->usessl && !self::$ssldebuglogged) {
            self::$ssldebuglogged = true;
            config_manager::debug_log('SSL verification disabled for non-HTTPS endpoint', ['endpoint' => $this->endpoint]);
        }
    }

    /**
     * 根据模型名称应用 thinking 参数到载荷
     *
     * @param array $payload 请求载荷（引用传递）
     * @param string $model 模型名称
     * @param bool $enableThinking 是否启用 thinking
     */
    private function apply_thinking_params(array &$payload, string $model, bool $enableThinking): void {
        $state = $enableThinking ? 'enabled' : 'disabled';
        foreach (self::MODEL_THINKING_CONFIG as $pattern => $config) {
            if (stripos($model, $pattern) !== false) {
                if ($config['type'] === 'enable_thinking') {
                    $payload['enable_thinking'] = $config[$state];
                } else if ($config['type'] === 'thinking_object') {
                    $payload['thinking'] = $config[$state];
                }
                return;
            }
        }
    }

    /**
     * 从消息数组构建完整的载荷结果
     *
     * 统一处理配置加载、max_tokens 归一化、payload 组装、thinking 参数应用、
     * 流式标记和调试日志。
     *
     * @param array $messages 消息数组
     * @param bool $stream 是否启用流式响应
     * @return array|null 载荷数据，配置不完整时返回 null
     */
    private function build_payload_result(array $messages, bool $stream): ?array {
        $this->load_direct_config();

        if (empty($this->endpoint) || empty($this->apikey) || empty($this->model)) {
            return null;
        }

        $maxtokens = $this->maxtokens;
        if ($maxtokens < 1) {
            $maxtokens = 4096;
        } else if ($maxtokens < 2048) {
            $maxtokens = 2048;
        }

        // 在发送到 AI 后端前统一规范化所有消息格式，确保 API 兼容性
        $normalizedmessages = [];
        foreach ($messages as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $normalizedmessages[] = prompt_builder::normalize_message($msg);
        }

        $payload = [
            'model' => $this->model,
            'messages' => $normalizedmessages,
            'max_tokens' => $maxtokens,
            'temperature' => $this->temperature,
        ];

        if ($this->enableThinking) {
            $payload['max_tokens'] = min((int) ($maxtokens * 3), 32768);
        }
        $this->apply_thinking_params($payload, $this->model, $this->enableThinking);

        if ($stream) {
            $payload['stream'] = true;
        }

        if (config_manager::is_debug_enabled()) {
            $this->log_payload_stats($payload, $this->model, (int)($payload['max_tokens'] ?? $maxtokens));
        }

        return [
            'payload' => $payload,
            'endpoint' => $this->endpoint,
            'apikey' => $this->apikey,
            'model' => $this->model,
            'enableThinking' => $this->enableThinking,
        ];
    }

    /**
     * 直接调用 OpenAI 兼容 API 创建补全（使用完整消息数组）
     *
     * 用于连续对话场景，直接使用 prompt_builder 构建的完整消息数组。
     * 当多端点使用 priority 策略时，请求失败会自动切换到下一个端点重试。
     *
     * @param array $messages 完整的消息数组（包含 system, user, assistant 角色）
     * @return array 包含 result 和 message 的数组
     */
    public function create_completion_direct_with_messages(array $messages): array {
        // 验证消息数组不为空
        if (empty($messages)) {
            config_manager::debug_log('create_completion_direct_with_messages: empty messages array');
            return ['result' => 'error', 'message' => get_string('error_empty_messages_array', 'local_ai_programming_assistant')];
        }

        // 验证至少包含一条有效的用户消息
        if (!prompt_builder::has_valid_user_message($messages)) {
            config_manager::debug_log('create_completion_direct_with_messages: no user message found');
            return ['result' => 'error', 'message' => get_string('error_no_valid_user_message', 'local_ai_programming_assistant')];
        }

        $result = $this->build_payload_result($messages, false);
        if ($result === null) {
            return ['result' => 'error', 'message' => get_string('error_config_incomplete', 'local_ai_programming_assistant')];
        }
        // 显式解构，避免 extract() 污染当前作用域（安全 + 可读性）
        $payload = $result['payload'];
        $endpoint = $result['endpoint'];
        $apikey = $result['apikey'];
        $model = $result['model'];

        $postdata = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
        if ($postdata === false || empty($postdata)) {
            $jsonError = json_last_error_msg();
            config_manager::debug_log('create_completion_direct_with_messages: json_encode failed', [
                'error' => $jsonError,
                'messages_count' => count($messages),
                'payload_keys' => array_keys($payload),
            ]);
            return ['result' => 'error', 'message' => get_string('error_json_encode', 'local_ai_programming_assistant') . ': ' . $jsonError];
        }

        // priority 失败切换循环：最多尝试 N 个端点
        $maxattempts = 1;
        if ($this->endpointctx !== null && ($this->endpointctx['strategy'] ?? '') === 'priority') {
            $total = count(config_manager::parse_endpoint_list());
            $maxattempts = max(1, $total);
        }
        $lastresponse = '';
        $lasthttpcode = 0;
        $lastcurlerrno = 0;
        $lastcurlerror = '';

        for ($attempt = 1; $attempt <= $maxattempts; $attempt++) {
            $opts = $this->build_direct_curl_opts($postdata, true, $apikey, $endpoint);

            $ch = curl_init();
            curl_setopt_array($ch, $opts);
            try {
                $response = curl_exec($ch);
                $errno = curl_errno($ch);
                $error = curl_error($ch);
                $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            } finally {
                curl_close($ch);
            }

            $lastresponse = $response;
            $lasthttpcode = $httpcode;
            $lastcurlerrno = $errno;
            $lastcurlerror = $error;

            // 传输层错误（网络/超时/5xx）：按 retryable 规则尝试切换端点
            if ($errno !== CURLE_OK || $httpcode < 200 || $httpcode >= 300) {
                if ($attempt < $maxattempts && $this->is_retryable_failure($errno, $httpcode, $error)) {
                    $switched = $this->advance_to_next_endpoint_for_failover();
                    if ($switched) {
                        $endpoint = $this->endpoint;
                        // advance_to_next_endpoint_for_failover 已在 $this->apikey/$this->model 内处理
                        // "空则回退到全局"的逻辑，此处直接取新值
                        $apikey = $this->apikey;
                        $model = $this->model;
                        // 重建载荷：切换端点后 model 可能不同，postdata 必须同步
                        $payload['model'] = $model;
                        $postdata = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
                        if ($postdata === false || empty($postdata)) {
                            break;
                        }
                        config_manager::debug_log('create_completion_direct_with_messages: failover to next endpoint', [
                            'attempt' => $attempt,
                            'errno' => $errno,
                            'httpcode' => $httpcode,
                        ]);
                        continue;
                    }
                }
                break;
            }

            // 2xx 响应：需要进一步检查响应体中的 error 字段
            // 有些上游会返回 HTTP 200 + {"error": "..."}，此时需要按可重试错误处理
            if (!empty($response)) {
                $decoded = @json_decode($response, true);
                if (is_array($decoded) && isset($decoded['error'])) {
                    $errCode = is_array($decoded['error'])
                        ? (int)($decoded['error']['code'] ?? 0)
                        : 0;
                    // 401/403 鉴权错误通常是配置问题，不重试；
                    // 429/5xx 或 400 类错误（如模型不匹配）可尝试切换下一端点
                    $retryableBody = !in_array($errCode, [401, 403], true)
                        && ($errCode === 0 || $errCode === 429 || $errCode >= 400);
                    if ($attempt < $maxattempts && $retryableBody) {
                        $switched = $this->advance_to_next_endpoint_for_failover();
                        if ($switched) {
                            $endpoint = $this->endpoint;
                            $apikey = $this->apikey;
                            $model = $this->model;
                            $payload['model'] = $model;
                            $postdata = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
                            if ($postdata === false || empty($postdata)) {
                                // 切换端点后重新编码载荷失败，记录明确日志避免错误诊断误导
                                config_manager::debug_log('create_completion_direct_with_messages: json_encode failed on API error failover', [
                                    'attempt' => $attempt,
                                    'json_error' => json_last_error_msg(),
                                    'err_code' => $errCode,
                                ]);
                                break;
                            }
                            config_manager::debug_log('create_completion_direct_with_messages: failover on API error payload', [
                                'attempt' => $attempt,
                                'httpcode' => $httpcode,
                                'err_code' => $errCode,
                            ]);
                            continue;
                        }
                    }
                }
            }

            // 成功退出（非 error payload 的 2xx）
            break;
        }

        if ($lastcurlerrno !== CURLE_OK) {
            config_manager::debug_log('create_completion_direct_with_messages: curl error', [
                'errno' => $lastcurlerrno,
                'error' => $lastcurlerror,
            ]);
            // cURL 错误消息可能包含端点 URL、连接详情等，仅记服务端日志
            return ['result' => 'error', 'message' => get_string('error_api_connection', 'local_ai_programming_assistant', '')];
        }

        // 验证响应不为空
        if (empty($lastresponse)) {
            config_manager::debug_log('create_completion_direct_with_messages: empty response from API');
            return ['result' => 'error', 'message' => get_string('error_empty_response_retry', 'local_ai_programming_assistant')];
        }

        $decoded = json_decode($lastresponse, true);
        if (!is_array($decoded)) {
            config_manager::debug_log('create_completion_direct_with_messages: invalid JSON response', [
                'httpcode' => $lasthttpcode,
                'first_200' => mb_substr($lastresponse, 0, 200, 'UTF-8'),
            ]);
            return ['result' => 'error', 'message' => get_string('error_api_response', 'local_ai_programming_assistant')];
        }

        if (isset($decoded['error'])) {
            $errormsg = is_array($decoded['error'])
                ? ($decoded['error']['message'] ?? get_string('error_api_error', 'local_ai_programming_assistant'))
                : $decoded['error'];
            // API 原始错误消息可能包含上游服务堆栈、内部路径等，仅记服务端日志
            config_manager::debug_log('create_completion_direct_with_messages: API error', [
                'httpcode' => $lasthttpcode,
                'msg' => is_string($errormsg) ? mb_substr($errormsg, 0, 500, 'UTF-8') : '',
            ]);
            return ['result' => 'error', 'message' => get_string('error_api_error', 'local_ai_programming_assistant')];
        }

        if (!isset($decoded['choices'][0]['message']['content'])) {
            config_manager::debug_log('create_completion_direct_with_messages: no content in response', [
                'httpcode' => $lasthttpcode,
            ]);
            return ['result' => 'error', 'message' => get_string('error_api_response', 'local_ai_programming_assistant')];
        }

        $content = $decoded['choices'][0]['message']['content'];
        $content = prompt_filter::filter_ai_response($content);

        $finishreason = $decoded['choices'][0]['finish_reason'] ?? '';

        return [
            'result' => 'success',
            'message' => $content,
            'model' => $decoded['model'] ?? $model,
            'finish_reason' => $finishreason,
            'truncated' => ($finishreason === 'length'),
        ];
    }

    /**
     * 判断请求失败是否属于可重试的瞬时错误（用于 priority 策略的自动切换）
     *
     * 仅对网络错误、5xx 服务器错误、429 限流错误进行重试；
     * 对于 4xx 客户端错误（401/403/422 等）或业务错误，不进行重试，避免无限循环。
     *
     * @param int $curlerrno curl_errno（CURLE_OK 表示 cURL 层面成功）
     * @param int $httpcode HTTP 状态码
     * @param string $errormsg 错误消息
     * @return bool 是否可重试
     */
    private function is_retryable_failure(int $curlerrno, int $httpcode, string $errormsg = ''): bool {
        if ($curlerrno !== CURLE_OK) {
            // 网络/连接级错误均视为可重试
            return true;
        }
        if ($httpcode === 0) {
            return true;
        }
        if ($httpcode >= 500 && $httpcode < 600) {
            return true;
        }
        if ($httpcode === 429) {
            return true;
        }
        return false;
    }

    /**
     * 尝试基于 priority 策略切换到下一个端点（如存在）
     *
     * @return bool 是否成功切换到下一个可用端点
     */
    private function advance_to_next_endpoint_for_failover(): bool {
        if ($this->endpointctx === null || ($this->endpointctx['strategy'] ?? '') !== 'priority') {
            return false;
        }
        $endpoints = config_manager::parse_endpoint_list();
        if (count($endpoints) < 2) {
            return false;
        }
        $currentIdx = (int)($this->endpointctx['index'] ?? 0);
        $nextIdx = $currentIdx + 1;
        if (!isset($endpoints[$nextIdx])) {
            return false;
        }
        $ep = $endpoints[$nextIdx];
        // 重置请求级缓存字段，让下一次 load/build 重新装载
        $this->endpoint = config_manager::normalize_endpoint($ep['endpoint']);
        // 与 load_direct_config() 保持一致：端点未配置 apikey 时回退到全局 apikey，
        // 避免发送空的 Authorization 头导致鉴权失败。
        $config = config_manager::get_plugin_config();
        $this->apikey = $ep['apikey'] !== '' ? $ep['apikey'] : trim((string)($config->apikey ?? ''));
        $this->model = $ep['model'] !== '' ? $ep['model'] : (string)($config->model ?? '');
        $this->usessl = stripos($this->endpoint, 'https://') === 0;
        $this->endpointctx['index'] = $nextIdx;

        config_manager::debug_log('advance_to_next_endpoint_for_failover: switched endpoint', [
            'from_index' => $currentIdx,
            'to_index' => $nextIdx,
            'endpoint' => $this->endpoint,
        ]);
        return true;
    }

    /**
     * 记录载荷统计信息（调试日志）
     *
     * 注意：调用方 build_payload_result() 已对所有消息执行 normalize_message()，
     * 因此此处可直接使用 $msg['content']，无需重复规范化。
     *
     * @param array $payload 请求载荷（消息已规范化）
     * @param string $model 模型名称
     * @param int $maxtokens 最大 token 数
     */
    private function log_payload_stats(array $payload, string $model, int $maxtokens): void {
        $totalchars = 0;
        foreach ($payload['messages'] as $msg) {
            $totalchars += mb_strlen($msg['content'] ?? '', 'UTF-8');
        }
        $estimatedtokens = (int)ceil($totalchars / 4);
        config_manager::debug_log('Payload estimated tokens', [
            'model' => $model,
            'messages' => count($payload['messages']),
            'total_chars' => $totalchars,
            'estimated_tokens' => $estimatedtokens,
            'max_tokens' => $maxtokens,
        ]);
    }

    /**
     * 构建 direct 后端 cURL 选项数组
     *
     * @param string $postdata JSON 编码的请求体
     * @param bool   $blocking 是否为阻塞模式（true=等待完整响应，false=流式）
     * @param string $apikey API 密钥
     * @param string $endpoint API 端点地址
     * @return array cURL 选项数组
     */
    private function build_direct_curl_opts(string $postdata, bool $blocking, string $apikey, string $endpoint): array {
        // 统一使用类属性缓存的 $this->usessl，避免重复计算
        // fallback：若 $this->usessl 尚未初始化（理论上 load_direct_config() 已先执行），则从 endpoint 判断
        $usessl = $this->usessl ?? (stripos($endpoint, 'https://') === 0);
        $opts = [
            \CURLOPT_URL => $endpoint,
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => $postdata,
            \CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apikey,
            ],
            \CURLOPT_RETURNTRANSFER => $blocking,
            \CURLOPT_CONNECTTIMEOUT => 30,
            \CURLOPT_TIMEOUT => 180,
            \CURLOPT_SSL_VERIFYPEER => $usessl,
            \CURLOPT_SSL_VERIFYHOST => $usessl ? 2 : 0,
        ];

        if (!$blocking) {
            $opts[\CURLOPT_RETURNTRANSFER] = false;
            $opts[\CURLOPT_BUFFERSIZE] = 4096;
            $opts[\CURLOPT_TIMEOUT] = 300;
        }

        return $opts;
    }

    /**
     * 构建三级提示词文本（公共实现，消除 create_completion_core_ai 和 create_completion_ai_manager 的重复）
     *
     * @param string $systemprompt 系统提示词
     * @param string $contextprompt 上下文提示词
     * @param string $usermessage 用户消息
     * @return string 拼接后的完整提示词文本
     */
    private function build_prompt_text(string $systemprompt, string $contextprompt, string $usermessage): string {
        $promptparts = [$systemprompt];
        if (!empty($contextprompt)) {
            $promptparts[] = $contextprompt;
            // 只有当用户消息不为空时才添加确认消息
            if (!empty($usermessage)) {
                $confirmMsg = get_string('context_confirmation', 'local_ai_programming_assistant');
                $promptparts[] = $confirmMsg;
            }
        }
        if (!empty($usermessage)) {
            $promptparts[] = $usermessage;
        }
        return implode("\n\n", $promptparts);
    }

    /**
     * 通过 Moodle 核心 AI 子系统 (core_ai) 创建补全
     *
     * 采用三级提示词架构：SystemPrompt + ContextPrompt + UserMessage 拼接为 prompttext，
     * 历史对话已嵌入 ContextPrompt 中，不再需要独立的 conversationcontext。
     *
     * @param string $systemprompt 系统提示词（系统级约束 + 试题信息）
     * @param string $contextprompt 上下文提示词（问题类型策略、历史对话、代码、测试结果等）
     * @param string $usermessage 用户当前消息（学生输入的内容）
     * @param object $context Moodle 上下文对象
     * @param array  $_conversationcontext 保留参数（不再使用，历史已嵌入 contextprompt）
     * @param string $assistantname 助手名称
     * @param string $username 用户名称
     * @return array 包含 result 和 message 的数组
     */
    public function create_completion_core_ai(
        string $systemprompt,
        string $contextprompt,
        string $usermessage = '',
        ?object $context = null,
        array $_conversationcontext = [],
        string $assistantname = '',
        string $username = ''
    ): array {
        global $USER;

        // 防御：context 为 null 时回退到系统上下文，避免 $context->id 空指针
        if ($context === null) {
            $context = \context_system::instance();
        }

        try {
            $manager = \core\di::get(\core_ai\manager::class);

            $prompttext = $this->build_prompt_text($systemprompt, $contextprompt, $usermessage);

            $action = new \core_ai\aiactions\generate_text(
                contextid: $context->id,
                userid: (int)$USER->id,
                prompttext: $prompttext,
            );

            $response = $manager->process_action($action);
            if (!$response->get_success()) {
                // core_ai 错误代码和消息可能包含内部实现细节，仅记服务端日志
                config_manager::debug_log('create_completion_core_ai: API error', [
                    'errorcode' => $response->get_errorcode(),
                    'errormessage' => $response->get_errormessage(),
                ]);
                return [
                    'result' => 'error',
                    'message' => get_string('error_api_error', 'local_ai_programming_assistant'),
                ];
            }

            $responsedata = $response->get_response_data();
            $content = '';
            if (is_array($responsedata) && isset($responsedata['generatedcontent'])) {
                $content = $responsedata['generatedcontent'];
            }
            $content = prompt_filter::filter_ai_response($content);

            $finishreason = '';
            if (is_array($responsedata) && isset($responsedata['finish_reason'])) {
                $finishreason = $responsedata['finish_reason'];
            }

            return [
                'result' => 'success',
                'message' => $content,
                'model' => $responsedata['model'] ?? '',
                'finish_reason' => $finishreason,
                'truncated' => ($finishreason === 'length'),
            ];
        } catch (\Throwable $e) {
            // 异常消息可能包含 SQL/路径等敏感信息，仅记服务端日志
            config_manager::debug_log('create_completion_core_ai: exception', [
                'msg' => $e->getMessage(),
                'class' => get_class($e),
            ]);
            return [
                'result' => 'error',
                'message' => get_string('error_api_error', 'local_ai_programming_assistant'),
            ];
        }
    }

    /**
     * 通过 AI 工具管理器 (local_ai_manager) 创建补全
     *
     * 采用三级提示词架构：SystemPrompt + ContextPrompt + UserMessage 拼接为 prompttext。
     *
     * @param string $systemprompt 系统提示词（系统级约束 + 试题信息）
     * @param string $contextprompt 上下文提示词（问题类型策略、历史对话、代码、测试结果等）
     * @param string $usermessage 用户当前消息（学生输入的内容）
     * @param object $context Moodle 上下文对象
     * @param array  $_unused 保留参数（不再使用）
     * @return array 包含 result 和 message 的数组
     */
    public function create_completion_ai_manager(
        string $systemprompt,
        string $contextprompt,
        string $usermessage = '',
        ?object $context = null,
        array $_unused = []
    ): array {
        // 防御：context 为 null 时回退到系统上下文，避免 $context->id 空指针
        if ($context === null) {
            $context = \context_system::instance();
        }

        try {
            $purpose = config_manager::get_config_cached('ai_manager_purpose');
            if (empty($purpose)) {
                $purpose = 'feedback';
            }

            $prompttext = $this->build_prompt_text($systemprompt, $contextprompt, $usermessage);

            $options = [
                'itemid' => $context->id,
            ];

            $ainstance = new \local_ai_manager\manager($purpose);
            $result = $ainstance->perform_request(
                $prompttext,
                'local_ai_programming_assistant',
                $context->id,
                $options,
            );

            if ($result->get_code() !== 200) {
                // ai_manager 错误消息可能包含内部实现细节，仅记服务端日志
                config_manager::debug_log('create_completion_ai_manager: API error', [
                    'code' => $result->get_code(),
                    'errormessage' => $result->get_errormessage(),
                ]);
                return [
                    'result' => 'error',
                    'message' => get_string('error_api_error', 'local_ai_programming_assistant'),
                ];
            }

            $content = (string) $result->get_content();
            $content = prompt_filter::filter_ai_response($content);

            $model = '';
            if (method_exists($result, 'get_model')) {
                $model = (string) $result->get_model();
            }

            return [
                'result' => 'success',
                'message' => $content,
                'model' => $model,
                'finish_reason' => '',
                'truncated' => false,
            ];
        } catch (\Throwable $e) {
            // 异常消息可能包含 SQL/路径等敏感信息，仅记服务端日志
            config_manager::debug_log('create_completion_ai_manager: exception', [
                'msg' => $e->getMessage(),
                'class' => get_class($e),
            ]);
            return [
                'result' => 'error',
                'message' => get_string('error_api_error', 'local_ai_programming_assistant'),
            ];
        }
    }

    /**
     * 通过流式输出获取 AI 反馈（使用完整消息数组）
     *
     * 当多端点使用 priority 策略时，请求失败会自动切换到下一个端点重试。
     *
     * @param array $messages 完整的消息数组
     * @param callable $on_complete 流式完成时回调
     * @param callable $on_error 出错时回调
     */
    public function stream_create_completion_direct_with_messages(
        array $messages,
        callable $on_complete,
        callable $on_error
    ): void {
        if (empty($messages)) {
            $on_error(get_string('error_empty_messages_array', 'local_ai_programming_assistant'));
            return;
        }

        // 验证至少包含一条有效的用户消息
        if (!prompt_builder::has_valid_user_message($messages)) {
            config_manager::debug_log('stream_create_completion_direct_with_messages: no user message found');
            $on_error(get_string('error_no_valid_user_message', 'local_ai_programming_assistant'));
            return;
        }

        $attempt = 0;
        $lastException = null;
        $tryNext = function () use (&$attempt, $messages, $on_complete, $on_error, &$lastException): bool {
            try {
                $attempt++;

                // 每次尝试前重新构建 payload（因为切换端点后 endpoint/apikey/model 可能已更新）
                $payloadresult = $this->build_payload_result($messages, true);
                if ($payloadresult === null) {
                    $on_error(get_string('direct_config_incomplete', 'local_ai_programming_assistant'));
                    return false;
                }

                // 在 build_payload_result 之后确定 priority 策略（此时 endpointctx 已通过 load_direct_config 初始化）
                $isPriority = ($this->endpointctx !== null && ($this->endpointctx['strategy'] ?? '') === 'priority');
                $maxattempts = 1;
                if ($isPriority) {
                    $total = count(config_manager::parse_endpoint_list());
                    $maxattempts = max(1, $total);
                }

                if ($attempt > $maxattempts) {
                    return false;
                }

                // 包装 on_complete/on_error：仅当本次尝试真正成功才调用 on_complete，
                // 失败时由包装逻辑决定是否重试。
                $finalResult = null;
                $finalError = null;
                $contentFlushed = false;
                $innerComplete = function ($result) use (&$finalResult) { $finalResult = $result; };
                $innerError = function ($error) use (&$finalError) { $finalError = $error; };

                $this->execute_stream_sse($payloadresult, $innerComplete, $innerError, $contentFlushed);

                if ($finalResult !== null) {
                    $on_complete($finalResult);
                    return false;
                }
                if ($finalError !== null) {
                    // priority 策略下尝试切换到下一个端点并重试。
                    // 注意：若已向客户端推送过 token/thinking（$contentFlushed），
                    // 流式响应是 append-only 的，重试会把"部分内容 + 完整内容"拼接给客户端，
                    // 产生重复/错乱文本，因此禁止 failover，直接上报错误。
                    if ($isPriority && $attempt < $maxattempts && !$contentFlushed) {
                        $switched = $this->advance_to_next_endpoint_for_failover();
                        if ($switched) {
                            config_manager::debug_log('stream_create_completion_direct_with_messages: failover to next endpoint', [
                                'attempt' => $attempt,
                                'error' => mb_substr((string)$finalError, 0, 200, 'UTF-8'),
                            ]);
                            return true; // 继续下一轮尝试
                        }
                    }
                    $on_error($finalError);
                    return false;
                }
                // 异常退出：on_complete / on_error 均未触发
                $on_error(get_string('erroroccurred', 'local_ai_programming_assistant'));
                return false;
            } catch (\Throwable $e) {
                $lastException = $e;
                config_manager::debug_log('stream_create_completion_direct_with_messages: exception in retry loop', [
                    'class' => get_class($e),
                    'message' => $e->getMessage(),
                ]);
                return false;
            }
        };

        // 驱动重试循环
        try {
            while ($tryNext()) {
                // 继续尝试，直到返回 false
            }
        } catch (\Throwable $e) {
            // 兜底保护：防止闭包内未捕获的异常传播到外层
            config_manager::debug_log('stream_create_completion_direct_with_messages: uncaught exception', [
                'class' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            $on_error(get_string('erroroccurred', 'local_ai_programming_assistant'));
            return;
        }

        // 如果最后一次尝试因异常退出但未触发 on_error，则补充通知
        if ($lastException !== null) {
            $on_error(get_string('erroroccurred', 'local_ai_programming_assistant'));
        }
    }

    /**
     * 执行 SSE 流式请求（公共实现）
     *
     * 从 stream_create_completion_direct_with_messages 中提取的公共流式处理逻辑。
     *
     * @param array|null $payloadresult build_direct_payload_from_messages() 的返回值
     * @param callable $on_complete 流式完成时回调
     * @param callable $on_error 出错时回调
     * @param bool|null &$contentflushed 输出引用：是否已向客户端推送过 token/thinking 内容
     *                 （供外层 failover 决策使用：已输出内容时禁止重试，避免重复/拼接内容）
     */
    private function execute_stream_sse(?array $payloadresult, callable $on_complete, callable $on_error, ?bool &$contentflushed = null): void {
        $starttime = microtime(true);

        if ($payloadresult === null) {
            $on_error(get_string('direct_config_incomplete', 'local_ai_programming_assistant'));
            return;
        }

        $payload = $payloadresult['payload'];
        $endpoint = $payloadresult['endpoint'];
        $apikey = $payloadresult['apikey'];
        $model = $payloadresult['model'];
        $enableThinking = $payloadresult['enableThinking'];

        $postdata = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
        if ($postdata === false || empty($postdata)) {
            $jsonError = json_last_error_msg();
            config_manager::debug_log('execute_stream_sse: json_encode failed', [
                'error' => $jsonError,
                'payload_keys' => array_keys($payload),
            ]);
            $on_error(get_string('error_json_encode', 'local_ai_programming_assistant') . ': ' . $jsonError);
            return;
        }

        config_manager::apply_time_limit();
        ignore_user_abort(true);

        $ch = curl_init();
        $opts = $this->build_direct_curl_opts($postdata, false, $apikey, $endpoint);
        curl_setopt_array($ch, $opts);

        $buffer = '';
        $curlerror = null;
        $pendingevents = '';
        $tokenbuffer = '';
        $thinkingbuffer = '';
        $now = microtime(true);
        $tokenFlushTime = $now;
        $thinkingFlushTime = $now;
        $lastflush = $now;
        $contentflushed = false;

        $parser = new sse_parser($enableThinking);

        $build_sse_event = function (string $eventtype, string $content): string {
            // 容忍非法 UTF-8：AI 返回的 token 含非法字节时 json_encode 返回 false，
            // 会导致输出空 data 而丢失 token；此处忽略非法字节并兜底为空串。
            $json = json_encode(['content' => $content], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
            if ($json === false) {
                $json = '{"content":""}';
            }
            return "event: {$eventtype}\ndata: {$json}\n\n";
        };

        $flush = function () use (&$pendingevents, &$tokenbuffer, &$thinkingbuffer, &$tokenFlushTime, &$thinkingFlushTime, &$contentflushed, $build_sse_event): void {
            if (connection_aborted()) {
                return;
            }
            if ($tokenbuffer !== '') {
                $pendingevents .= $build_sse_event('token', $tokenbuffer);
                $tokenbuffer = '';
                $tokenFlushTime = microtime(true);
            }
            if ($thinkingbuffer !== '') {
                $pendingevents .= $build_sse_event('thinking', $thinkingbuffer);
                $thinkingbuffer = '';
                $thinkingFlushTime = microtime(true);
            }
            if ($pendingevents === '') {
                return;
            }
            echo $pendingevents;
            $pendingevents = '';
            // 已向客户端推送内容：记录标记，供外层 failover 判断（已输出内容时禁止重试）
            $contentflushed = true;
            self::flush_output_buffers();
        };

        $buffered_on_token = function (string $token) use (&$pendingevents, &$tokenbuffer, &$tokenFlushTime, $build_sse_event): void {
            $tokenbuffer .= $token;
            $now = microtime(true);
            if (mb_strlen($tokenbuffer, 'UTF-8') >= 10 || ($now - $tokenFlushTime) >= 0.03) {
                $pendingevents .= $build_sse_event('token', $tokenbuffer);
                $tokenbuffer = '';
                $tokenFlushTime = $now;
            }
        };

        $buffered_on_thinking = function (string $token) use (&$pendingevents, &$thinkingbuffer, &$thinkingFlushTime, $build_sse_event): void {
            $thinkingbuffer .= $token;
            $now = microtime(true);
            if (mb_strlen($thinkingbuffer, 'UTF-8') >= 50 || ($now - $thinkingFlushTime) >= 0.1) {
                $pendingevents .= $build_sse_event('thinking', $thinkingbuffer);
                $thinkingbuffer = '';
                $thinkingFlushTime = $now;
            }
        };

        $parser->set_callbacks($buffered_on_token, $buffered_on_thinking);

        $flushThresholdBytes = 1024;
        $lastFlushTime = microtime(true);
        $flushInterval = 0.1;

        curl_setopt($ch, \CURLOPT_WRITEFUNCTION, function ($ch, $data) use (
            &$buffer, $parser, &$pendingevents, &$flushThresholdBytes, &$lastFlushTime, $flushInterval, $flush
        ) {
            if (connection_aborted()) {
                return 0;
            }

            $buffer .= $data;

            // 防御性硬限制：上游持续发送不包含 \n\n 分隔符的数据会导致 buffer 无限增长
            // 触发阈值：maxcontentlength(1MB) * 4 = 4MB，与 sse_parser 尺寸约束策略同源
            // （sse_parser 默认 maxcontentlength=1048576，字段级截断上限亦为该值）
            if (strlen($buffer) > 4194304) {
                config_manager::debug_log('execute_stream_sse: SSE buffer exceeded 4MB hard limit', [
                    'buffer_len' => strlen($buffer),
                ]);
                return 0; // 返回 0 通知 cURL 中止传输
            }

            while (true) {
                $pos = strpos($buffer, "\n\n");

                if ($pos !== false) {
                    $sepPos = $pos;
                    $sepLen = 2;
                } else {
                    $posCr = strpos($buffer, "\r\n\r\n");
                    if ($posCr !== false) {
                        $sepPos = $posCr;
                        $sepLen = 4;
                    } else {
                        break;
                    }
                }

                $event = substr($buffer, 0, $sepPos);
                $buffer = substr($buffer, $sepPos + $sepLen);

                $parser->parse_event($event);
            }

            $now = microtime(true);
            if (strlen($pendingevents) >= $flushThresholdBytes || ($now - $lastFlushTime) >= $flushInterval) {
                $flush();
                $lastFlushTime = $now;
            }

            return strlen($data);
        });

        curl_setopt($ch, \CURLOPT_NOPROGRESS, false);

        $lastKeepalive = microtime(true);
        $keepaliveInterval = 15;

        $flushcallback = function ($ch) use ($flush, &$lastflush, &$lastKeepalive, $keepaliveInterval) {
            if (connection_aborted()) {
                return 1;
            }
            $now = microtime(true);
            if (($now - $lastflush) >= 0.015) {
                $flush();
                $lastflush = $now;
            }
            if (($now - $lastKeepalive) >= $keepaliveInterval) {
                echo ": keepalive\n\n";
                self::flush_output_buffers();
                $lastKeepalive = $now;
            }
            return 0;
        };

        if (defined('CURLOPT_XFERINFOFUNCTION')) {
            curl_setopt($ch, \CURLOPT_XFERINFOFUNCTION, function ($ch, $dltotal, $dlnow) use ($flushcallback) {
                return $flushcallback($ch);
            });
        } else {
            curl_setopt($ch, \CURLOPT_PROGRESSFUNCTION, function ($ch, $dltotal, $dlnow, $ultotal, $ulnow) use ($flushcallback) {
                return $flushcallback($ch);
            });
        }

        if (config_manager::is_debug_enabled()) {
            config_manager::debug_log('execute_stream_sse: starting curl_exec to ' . $endpoint);
        }
        try {
            curl_exec($ch);

            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlerrno = curl_errno($ch);
            $curlcontenttype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $curlsize = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
            if ($curlerrno) {
                $curlerror = curl_error($ch);
            }

            if (config_manager::is_debug_enabled()) {
                config_manager::debug_log('execute_stream_sse: curl_exec completed', [
                    'httpcode' => $httpcode,
                    'curlerrno' => $curlerrno,
                    'curlerror' => $curlerror ?? '',
                    'content_type' => $curlcontenttype ?? '',
                    'size_download' => $curlsize,
                    'fullcontent_len' => strlen($parser->get_full_content()),
                    'buffer_len' => strlen($buffer),
                    'finish_reason' => $parser->get_finish_reason(),
                ]);
            }

            $parser->parse_remaining_buffer($buffer);

            if ($parser->try_parse_json_fallback($buffer)) {
                if (config_manager::is_debug_enabled()) {
                    config_manager::debug_log('execute_stream_sse: fallback to non-streaming response', [
                        'content_len' => strlen($parser->get_full_content()),
                        'thinking_len' => strlen($parser->get_thinking_content()),
                    ]);
                }
            }

            $flush();

            if (empty($curlerror) && $httpcode >= 200 && $httpcode < 300 && $parser->has_content()) {
                $this->handle_stream_success($parser, $model, $enableThinking, $starttime, $on_complete);
            } else if ($curlerror) {
                // cURL 错误消息可能包含内部端点 URL、连接详情等，仅记服务端日志
                config_manager::debug_log('execute_stream_sse: curl error', [
                    'errno' => $curlerrno,
                    'error' => $curlerror,
                ]);
                $on_error(get_string('error_api_connection', 'local_ai_programming_assistant', ''));
            } else if ($httpcode < 200 || $httpcode >= 300) {
                $on_error($this->build_http_error_message($httpcode, $buffer, $endpoint));
            } else {
                $this->handle_stream_empty($parser, $httpcode, $buffer, $on_error);
            }
        } catch (\Throwable $e) {
            // 异常消息可能包含敏感信息，仅记服务端日志
            config_manager::debug_log('execute_stream_sse: curl_exec threw exception', ['msg' => $e->getMessage()]);
            $flush();
            $on_error(get_string('error_api_connection', 'local_ai_programming_assistant', ''));
        } finally {
            curl_close($ch);
        }
    }

    /**
     * 处理流式响应成功结果
     */
    private function handle_stream_success(
        sse_parser $parser, string $model, bool $enableThinking,
        float $starttime, callable $on_complete
    ): void {
        $filteredcontent = prompt_filter::filter_ai_response($parser->get_full_content());
        $filteredthinking = '';
        if ($enableThinking && !empty($parser->get_thinking_content())) {
            $filteredthinking = prompt_filter::filter_ai_response($parser->get_thinking_content());
        }
        if (config_manager::is_debug_enabled()) {
            config_manager::debug_log('handle_stream_success: success', [
                'filtered_len' => strlen($filteredcontent),
                'thinking_len' => strlen($filteredthinking),
            ]);
        }
        $responsetime = (int)((microtime(true) - $starttime) * 1000);
        $on_complete([
            'success' => true,
            'message' => $filteredcontent,
            'thinking_content' => $filteredthinking,
            'model' => $model,
            'finish_reason' => $parser->get_finish_reason(),
            'truncated' => ($parser->get_finish_reason() === 'length'),
            'responsetime' => $responsetime,
        ]);
    }

    /**
     * 构建 HTTP 错误消息
     *
     * 安全说明：API 返回的错误详情（$errordetail）可能包含内部端点 URL、
     * 上游服务堆栈、API key 片段等敏感信息，仅记入服务端日志，
     * 不拼接到返回给前端的错误消息中。
     */
    private function build_http_error_message(int $httpcode, string $buffer, string $endpoint): string {
        $errordetail = '';
        if (!empty($buffer)) {
            $decoded = json_decode($buffer, true);
            if (is_array($decoded)) {
                $errordetail = $decoded['error']['message'] ?? $decoded['error'] ?? $decoded['message'] ?? '';
            }
            if (config_manager::is_debug_enabled()) {
                config_manager::debug_log('stream_create_completion_direct_with_messages: HTTP error response', [
                    'buffer_preview' => mb_substr($buffer, 0, 500, 'UTF-8'),
                ]);
            }
        }
        // 详细错误信息仅记录到服务端日志，不发送到前端
        if (!empty($errordetail) && is_string($errordetail)) {
            config_manager::debug_log('HTTP error detail from API', [
                'endpoint' => $endpoint,
                'httpcode' => $httpcode,
                'error_detail' => mb_substr($errordetail, 0, 500, 'UTF-8'),
            ]);
        } else {
            config_manager::debug_log('HTTP error from API', [
                'endpoint' => $endpoint,
                'httpcode' => $httpcode,
            ]);
        }
        // 前端只显示 HTTP 状态码，不包含 API 原始错误详情
        return get_string('error_http_status', 'local_ai_programming_assistant', $httpcode);
    }

    /**
     * 处理流式响应为空的情况
     */
    private function handle_stream_empty(
        sse_parser $parser, int $httpcode, string $buffer, callable $on_error
    ): void {
        if (config_manager::is_debug_enabled()) {
            config_manager::debug_log('stream_create_completion_direct_with_messages: empty response', [
                'httpcode' => $httpcode,
                'buffer_preview' => mb_substr($buffer, 0, 500, 'UTF-8'),
                'thinking_len' => strlen($parser->get_thinking_content()),
            ]);
        }
        if ($parser->get_thinking_content() !== '' && !$parser->has_content()) {
            $on_error(get_string('thinking_too_long', 'local_ai_programming_assistant'));
        } else {
            $errdetail = '';
            if ($httpcode >= 200 && $httpcode < 300) {
                $errdetail = ' (HTTP ' . $httpcode . ')';
            }
            $on_error(get_string('error_empty_response', 'local_ai_programming_assistant') . $errdetail);
        }
    }

    // ===================== 静态工具方法 =====================

    /**
     * 刷新所有 PHP 输出缓冲区
     */
    private static function flush_output_buffers(): void {
        $maxlevel = ob_get_level();
        for ($i = 0; $i < $maxlevel; $i++) {
            @ob_flush();
        }
        flush();
    }

}