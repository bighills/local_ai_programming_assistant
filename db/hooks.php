<?php
/**
 * AI 编程助手 - 钩子注册
 *
 * 注册 Moodle Hook 回调，在页面 footer 生成前注入 AI 助手界面。
 *
 * @author zfl
 * @package    local_ai_programming_assistant
 */
defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core\hook\output\before_standard_head_html_generation::class,
        'callback' => \local_ai_programming_assistant\hook_callbacks::class . '::before_standard_head_html_generation',
    ],
    [
        'hook' => \core\hook\output\before_footer_html_generation::class,
        'callback' => \local_ai_programming_assistant\hook_callbacks::class . '::before_footer_html_generation',
    ],
];