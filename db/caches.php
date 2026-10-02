<?php
/**
 * AI 编程助手 - 缓存定义
 *
 * 定义插件使用的 Moodle 缓存区域（ratelimit、stats）及其 TTL。
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    'ratelimit' => [
        'mode'                => cache_store::MODE_APPLICATION,
        'simplekeys'          => true,
        'simpledata'          => true,
        'staticacceleration'  => true,
        'can_use_identical_store' => true,
        'ttl'                 => 70,
    ],
    'stats' => [
        'mode'                => cache_store::MODE_APPLICATION,
        'simplekeys'          => true,
        'simpledata'          => true,
        'staticacceleration'  => true,
        'can_use_identical_store' => true,
        'ttl'                 => 300,
    ],
    'round_robin_index' => [
        'mode'                => cache_store::MODE_APPLICATION,
        'simplekeys'          => true,
        'simpledata'          => true,
        'staticacceleration'  => true,
        'can_use_identical_store' => true,
        // 不设置 ttl 键，缓存条目永不过期，手动管理生命周期
    ],
];