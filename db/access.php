<?php
/**
 * AI 编程助手 - 权限定义
 *
 * 定义插件使用的权限能力：
 * - use: 允许使用 AI 助手（学生默认拥有）
 * - viewknowledgebase: 允许查看知识库（学生、教师和管理员默认拥有）
 * - viewstats: 允许查看统计信息（教师和管理员默认拥有）
 * - config: 允许配置插件（管理员默认拥有）
 *
 * @author zfl
 * @package    local_ai_programming_assistant
 */
defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/ai_programming_assistant:use' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'student' => CAP_ALLOW,
        ],
    ],
    'local/ai_programming_assistant:viewknowledgebase' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'student' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
    'local/ai_programming_assistant:viewstats' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        // 使用 CONTEXT_COURSE 而非 CONTEXT_MODULE，确保：
        // 1. quiz_stats.php 在 COURSE 上下文检查时，教师/编辑教师角色能正常通过
        // 2. 在 MODULE 上下文检查时，通过父链继承 COURSE 级角色分配也能通过
        // 3. 全局统计页面（stats/free_stats）在 SYSTEM 上下文检查时
        //    仅有 SYSTEM 级角色（manager/admin）能通过，符合全校数据的访问限制
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
    'local/ai_programming_assistant:config' => [
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
];