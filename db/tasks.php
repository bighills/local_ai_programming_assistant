<?php
/**
 * AI 编程助手 - 定时任务定义
 *
 * @author zfl
 * @package    local_ai_programming_assistant
 */
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'local_ai_programming_assistant\task\cleanup_task',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '2',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
];