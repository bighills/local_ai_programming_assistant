<?php
/**
 * 思路整理解策略 - 帮助学生整理编程解题思路
 *
 * 仅发送题目文本给 AI，不包含学生代码或测试结果，
 * 通过引导性问题和步骤提示帮助学生构建解题框架。
 *
 * @author zfl
 * @package    local_ai_programming_assistant
 */
namespace local_ai_programming_assistant\strategy;
defined('MOODLE_INTERNAL') || die();

/**
 * 思路整理策略
 *
 * 仅发送题目文本，帮助学生理清解题方向和思考框架。
 */
class idea_organization_strategy extends base_strategy {

    public function get_prompt_setting_key(): string {
        return 'strategy_prompt_idea_organization';
    }

    public function get_default_prompt_string_key(): string {
        return 'default_prompt_idea_organization';
    }

    protected function get_label_string_key(): string {
        return 'strategy_idea_organization';
    }

    /**
     * 构建仅包含题目文本的上下文
     */
    public function build_context(array $pagecontext): string {
        return self::build_question_only_context($pagecontext);
    }
}