<?php
/**
 * AI 编程助手 - 外部服务定义
 *
 * 注册可通过 AJAX 调用的外部 Web 服务函数。
 *
 * @author zfl
 * @package    local_ai_programming_assistant
 */
defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_ai_programming_assistant_send_message' => [
        'classname' => 'local_ai_programming_assistant\external\send_message',
        'description' => 'Send a message to the AI programming assistant and get a response.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/ai_programming_assistant:use',
    ],
    'local_ai_programming_assistant_get_conversation_history' => [
        'classname' => 'local_ai_programming_assistant\external\get_conversation_history',
        'description' => 'Get conversation history for a specific strategy mode.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/ai_programming_assistant:use',
    ],
    'local_ai_programming_assistant_create_new_conversation' => [
        'classname' => 'local_ai_programming_assistant\external\create_new_conversation',
        'description' => 'Create a new conversation session for the current strategy mode.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/ai_programming_assistant:use',
    ],
    'local_ai_programming_assistant_get_embedded_questions' => [
        'classname' => 'local_ai_programming_assistant\external\get_embedded_questions',
        'description' => 'Get embedded questions (tiny_embedquestion) on the current page for the question selector.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/ai_programming_assistant:use',
    ],
];