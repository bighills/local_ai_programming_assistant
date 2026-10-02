<?php
/**
 * AI 编程助手 - SSE 流式消息处理入口
 *
 * @package    local_ai_programming_assistant
 */

require(__DIR__ . '/../../config.php');

use local_ai_programming_assistant\stream_handler;
use local_ai_programming_assistant\stream_controller;
use local_ai_programming_assistant\completion;
use local_ai_programming_assistant\config_manager;
use local_ai_programming_assistant\helper;

// 启动输出缓冲，防止 PHP 警告/通知污染 SSE 或 JSON 响应
ob_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // 清理缓冲区，防止 headers already sent
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('HTTP/1.1 405 Method Not Allowed');
    header('Allow: POST');
    exit;
}

// 请求体大小限制（512KB），防止恶意用户发送超大 payload。
// 注意：仅依赖 CONTENT_LENGTH 头可被 Transfer-Encoding: chunked 绕过，
// 因此读取实际请求体后再按真实长度校验一次。
$contentlength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentlength > 512 * 1024) {
    stream_handler::http_error(413, 'Request body too large');
}

// 提前读取请求体并解析，避免后续 SSE 头设置后读取失败
$rawbody = file_get_contents('php://input');
if (strlen($rawbody) > 512 * 1024) {
    stream_handler::http_error(413, 'Request body too large');
}
$input = json_decode($rawbody, true);

if (!is_array($input)) {
    stream_handler::http_error(400, get_string('error_invalid_json_body', 'local_ai_programming_assistant'));
}

// 输入字段类型规范化和基本校验：防止 JSON 中混入非预期类型（数组/对象）导致后续函数产生 Warning/TypeError
// - sesskey：必须是字符串（JSON 可能嵌套传数组/对象）
// - contextid：强制 int
// - 其余常用字段：仅做 is_string 规范化，不强制存在（交由 stream_controller 内部校验）
foreach (['sesskey', 'actiontype', 'message', 'assistantname'] as $strkey) {
    if (array_key_exists($strkey, $input) && !is_string($input[$strkey])) {
        if (is_scalar($input[$strkey])) {
            $input[$strkey] = (string)$input[$strkey];
        } else {
            $input[$strkey] = '';
        }
    }
}
foreach (['contextid', 'questionid', 'quizid', 'conversationid', 'cmid'] as $intkey) {
    if (array_key_exists($intkey, $input)) {
        $input[$intkey] = is_scalar($input[$intkey]) ? (int)$input[$intkey] : 0;
    }
}
// pagecontext：必须是数组，非数组时置空（交由 stream_controller 判空）
if (array_key_exists('pagecontext', $input) && !is_array($input['pagecontext'])) {
    $input['pagecontext'] = [];
}
// history：前端以 JSON 字符串发送（lib.js buildRequestData 中 JSON.stringify(history, ['user','message'])），
// 也可能是数组。仅对既非字符串也非数组的非法值置空，交由 request_handler::extract_params 统一规范化，
// 避免流式模式下多轮对话历史被整体丢弃导致 AI 上下文断裂。
if (array_key_exists('history', $input) && !is_string($input['history']) && !is_array($input['history'])) {
    $input['history'] = [];
}

// 认证和权限检查（必须在任何输出之前完成）
$pluginconfig = null;
$ratelimited = false;
$ratelimitmessage = '';
try {
    require_login();

    if (!confirm_sesskey($input['sesskey'] ?? '')) {
        throw new \Exception('Invalid sesskey');
    }

    // 权限检查：在 SSE 输出之前完成，确保无权限时返回干净的 HTTP 403
    $contextid = (int)($input['contextid'] ?? 0);
    $authcontext = helper::resolve_context_from_id($contextid);
    require_capability('local/ai_programming_assistant:use', $authcontext);

    $pluginconfig = config_manager::get_plugin_config();

    if (empty($pluginconfig->enable)) {
        throw new \Exception('Plugin not enabled');
    }

    if (($pluginconfig->responsemode ?? 'streaming') !== 'streaming') {
        throw new \Exception('Not in streaming mode');
    }

    $ratelimitresult = completion::check_rate_limit();
    if ($ratelimitresult !== null) {
        $ratelimited = true;
        $ratelimitmessage = $ratelimitresult['message'];
        throw new \Exception('Rate limit exceeded');
    }

} catch (\Throwable $e) {
    // 认证失败：异常消息可能包含内部状态信息（如配置缺失细节），
    // 仅记入服务端日志，前端统一显示通用错误消息。
    // rate_limit 消息是用户友好的，通过 $ratelimitmessage 单独传递。
    error_log('[ai_programming_assistant] stream_message auth error: ' . $e->getMessage());
    $handler = new stream_handler();
    // 鉴权/限流失败返回对应的非 200 状态码（在发送 SSE 头部之前设置），
    // 使代理层/浏览器能正确识别失败，而不是伪装成 200 成功流。
    http_response_code($ratelimited ? 429 : 403);
    $handler->setup_sse_headers();
    if ($ratelimited) {
        // 速率限制消息是用户友好的，直接发送给前端
        $handler->send_error_response($ratelimitmessage, false);
    } else {
        // 对于已知的认证类错误，返回对应的友好提示；其余统一返回通用错误
        $usermsg = null;
        if ($e instanceof \moodle_exception && $e->errorcode === 'error_invalid_context') {
            $usermsg = get_string('error_invalid_context', 'local_ai_programming_assistant');
        } elseif (strpos($e->getMessage(), 'Invalid sesskey') !== false) {
            $usermsg = get_string('error_invalid_sesskey', 'local_ai_programming_assistant');
        } elseif (strpos($e->getMessage(), 'Plugin not enabled') !== false) {
            $usermsg = get_string('error_not_enabled', 'local_ai_programming_assistant');
        } elseif (strpos($e->getMessage(), 'Not in streaming mode') !== false) {
            $usermsg = get_string('error_not_streaming_mode', 'local_ai_programming_assistant');
        }
        if ($usermsg === null) {
            $usermsg = get_string('erroroccurred', 'local_ai_programming_assistant');
        }
        $handler->send_error_response($usermsg, false);
    }
    exit;
}

// 认证成功，关闭会话锁（由 setup_sse_headers 统一处理），开始流式响应
// 执行流式响应
$handler = new stream_handler();
try {
    $handler->setup_sse_headers();
    $handler->register_shutdown_handler();

    $controller = new stream_controller($input, $pluginconfig, $handler);
    $controller->execute();

} catch (\Throwable $e) {
    // 使用 handler->send_sse 统一输出，确保正确 flush 到客户端；
    // 此前用裸 echo 无法保证缓冲区被刷新，前端可能收不到 error/done 事件。
    // 改用 Throwable 覆盖 Error/TypeError 等，避免依赖 shutdown handler 兜底。
    error_log('[ai_programming_assistant] stream_message error: ' . $e->getMessage());
    $handler->send_error_response(get_string('erroroccurred', 'local_ai_programming_assistant'));
    exit;
}