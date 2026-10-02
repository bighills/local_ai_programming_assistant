<?php
/**
 * 操作策略接口
 *
 * 定义每种 AI 助手操作模式（自由问答、题目分析、思路整理、代码调试）的公共接口。
 * 每个策略实现自己的提示词配置键、默认提示词和页面上下文构建逻辑。
 *
 * @author zfl
 * @package    local_ai_programming_assistant
 */
namespace local_ai_programming_assistant\strategy;
defined('MOODLE_INTERNAL') || die();

/**
 * 操作策略接口
 *
 * 每个策略定义自己的提示词和页面上下文构建逻辑。
 */
interface action_strategy_interface {

    /**
     * 获取策略的提示词文本
     * @return string 提示词文本
     */
    public function get_prompt(): string;

    /**
     * 获取该策略提示词的配置项键名
     * @return string 配置项键名
     */
    public function get_prompt_setting_key(): string;

    /**
     * 获取该策略默认提示词的语言字符串键名
     * @return string 语言字符串键名
     */
    public function get_default_prompt_string_key(): string;

    /**
     * 构建页面上下文字符串，用于包含在 AI 提示词中
     *
     * @param array $pagecontext 原始页面上下文数据（题目文本、学生代码、测试结果等）
     * @return string 格式化后的上下文字符串
     */
    public function build_context(array $pagecontext): string;

    /**
     * 获取策略的显示标签（用于前端按钮显示）
     * @return string 策略标签文本
     */
    public function get_label(): string;
}