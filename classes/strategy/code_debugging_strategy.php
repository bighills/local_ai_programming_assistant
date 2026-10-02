<?php
/**
 * 代码调试策略 - 分析学生代码中的问题
 *
 * 发送题目文本、学生代码、编程语言，以及可选的测试结果给 AI，
 * 帮助学生定位和修复代码中的语法错误、逻辑错误和边界问题。
 *
 * @author zfl
 * @package    local_ai_programming_assistant
 */
namespace local_ai_programming_assistant\strategy;
defined('MOODLE_INTERNAL') || die();

/**
 * 代码调试策略
 *
 * 分析学生代码中的问题，结合题目要求和测试结果提供调试建议。
 */
class code_debugging_strategy extends base_strategy {

    public function get_prompt_setting_key(): string {
        return 'strategy_prompt_code_debugging';
    }

    public function get_default_prompt_string_key(): string {
        return 'default_prompt_code_debugging';
    }

    protected function get_label_string_key(): string {
        return 'strategy_code_debugging';
    }

    /**
     * 构建包含题目、代码、语言和测试结果的上下文
     *
     * 复用基类的完整上下文构建方法。
     */
    public function build_context(array $pagecontext): string {
        return parent::build_full_context($pagecontext);
    }
}