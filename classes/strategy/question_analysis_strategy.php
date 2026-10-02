<?php
/**
 * 题目分析策略 - 分析编程题目的要求和解题方向
 *
 * 仅发送题目文本给 AI，不包含学生代码或测试结果，
 * 专注于帮助学生理解题目的要求、输入输出格式、关键知识点等。
 *
 * @author zfl
 * @package    local_ai_programming_assistant
 */
namespace local_ai_programming_assistant\strategy;
defined('MOODLE_INTERNAL') || die();

/**
 * 题目分析策略
 *
 * 仅发送题目文本，帮助学生深入理解题目要求。
 */
class question_analysis_strategy extends base_strategy {

    public function get_prompt_setting_key(): string {
        return 'strategy_prompt_question_analysis';
    }

    public function get_default_prompt_string_key(): string {
        return 'default_prompt_question_analysis';
    }

    protected function get_label_string_key(): string {
        return 'strategy_question_analysis';
    }

    /**
     * 构建仅包含题目文本的上下文
     */
    public function build_context(array $pagecontext): string {
        return self::build_question_only_context($pagecontext);
    }
}