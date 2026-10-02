<?php
/**
 * 自由问答策略 - 默认的对话助手模式
 *
 * 发送所有可用的页面上下文（题目文本、学生代码、测试结果、编程语言），
 * 允许用户自由提问关于代码的任何问题，并支持多轮对话历史。
 *
 * @author zfl
 * @package    local_ai_programming_assistant
 */
namespace local_ai_programming_assistant\strategy;
defined('MOODLE_INTERNAL') || die();

/**
 * 自由问答策略
 *
 * 默认的对话模式，发送所有可用的页面上下文信息给 AI。
 */
class free_strategy extends base_strategy {

    public function get_prompt_setting_key(): string {
        return 'strategy_prompt_free';
    }

    public function get_default_prompt_string_key(): string {
        return 'defaultprompt';
    }

    protected function get_label_string_key(): string {
        return 'askaquestion';
    }

    /**
     * 构建包含所有可用字段的页面上下文
     */
    public function build_context(array $pagecontext): string {
        return parent::build_full_context($pagecontext);
    }
}