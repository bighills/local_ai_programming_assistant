<?php
/**
 * AI 编程助手 - 流式响应控制器
 *
 * 封装 stream_message.php 中的核心业务逻辑，
 * 委托给 manager 类处理 AI 请求，本类专注于流式响应的控制和回调处理。
 *
 * @package    local_ai_programming_assistant
 */
namespace local_ai_programming_assistant;

defined('MOODLE_INTERNAL') || die();

class stream_controller {

    /** @var stream_handler SSE 流处理器 */
    private stream_handler $handler;

    /** @var object 插件配置 */
    private object $pluginconfig;

    /** @var manager AI 对话管理器 */
    private manager $manager;

    /** @var int 用户 ID */
    private int $userid;

    /** @var int 对话会话 ID */
    private int $conversationid;

    /**
     * @param array $input 解码后的 JSON 输入
     * @param object $pluginconfig 插件配置
     * @param stream_handler $handler SSE 流处理器（stream_message.php 保证始终传入有效实例）
     */
    public function __construct(array $input, object $pluginconfig, stream_handler $handler) {
        global $USER;
        $this->userid = (int)($USER->id ?? 0);
        $this->pluginconfig = $pluginconfig;
        $this->handler = $handler;

        // 提取参数和解码历史
        $params = request_handler::extract_params($input);
        $history = request_handler::decode_history($params['history']);

        // 解析上下文（权限检查已在 stream_message.php 中完成，此处不再重复 require_capability）
        $context = helper::resolve_context_from_id($params['contextid']);

        // 创建 manager 实例
        $this->manager = new manager($context, $this->userid, $params, $history);
        $this->conversationid = $params['conversationid'] ?? 0;
    }

    /**
     * 执行流式响应流程
     *
     * handler 始终由 stream_message.php 传入，此处不再重复检查 null，
     * 消除死代码分支。
     */
    public function execute(): void {
        $onsuccess = $this->build_success_handler();
        $onerror = $this->build_error_handler();
        $onmetadata = $this->build_metadata_handler();

        try {
            $this->manager->stream_request_ai($onsuccess, $onerror, $onmetadata);
        } catch (\Throwable $e) {
            error_log('[ai_programming_assistant] stream_request_ai error: ' . $e->getMessage());
            if (!$this->handler->is_done()) {
                $onerror(get_string('erroroccurred', 'local_ai_programming_assistant'));
            }
        }

        $this->handler->mark_done();
    }

    /**
     * 构建成功回调
     */
    private function build_success_handler(): callable {
        $handler = $this->handler;

        return function($response) use ($handler) {
            $handler->mark_done();

            if (!empty($response['error'])) {
                $handler->send_sse('error', ['message' => $response['error']]);
                $handler->send_sse('done', ['success' => false, 'feedbackid' => 0]);
                return;
            }

            $handler->send_sse('complete', [
                'success' => $response['success'],
                'feedbackid' => 0,
                'message' => $response['message'] ?? '',
                'thinking_content' => $response['thinking_content'] ?? '',
                'model' => $response['model'] ?? '',
                'finish_reason' => $response['finish_reason'] ?? 'stop',
                'truncated' => $response['truncated'] ?? false,
                'multiturn' => !empty($response['multiturn']),
                'conversationid' => $response['conversationid'] ?? 0,
            ]);
            $handler->send_sse('done', ['success' => true, 'feedbackid' => 0]);
        };
    }

    /**
     * 构建错误回调
     */
    private function build_error_handler(): callable {
        $handler = $this->handler;

        return function($error) use ($handler) {
            $handler->mark_done();
            $handler->send_sse('error', ['message' => $error]);
            $handler->send_sse('done', ['success' => false, 'feedbackid' => 0]);
        };
    }

    /**
     * 构建知识库元数据回调
     *
     * 在流式开始前发送 fileid→filename 映射，供前端生成准确的知识库链接。
     */
    private function build_metadata_handler(): callable {
        $handler = $this->handler;

        return function(array $files) use ($handler) {
            if (!empty($files)) {
                $handler->send_sse('kb_metadata', ['files' => $files]);
            }
        };
    }

    /**
     * 获取 manager 实例
     */
    public function get_manager(): manager {
        return $this->manager;
    }
}