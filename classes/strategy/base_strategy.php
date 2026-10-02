<?php
/**
 * 策略基类 - 提供各策略共享的辅助方法
 *
 * @author zfl
 * @package    local_ai_programming_assistant
 */
namespace local_ai_programming_assistant\strategy;

use local_ai_programming_assistant\config_manager;
use local_ai_programming_assistant\prompt_filter;

defined('MOODLE_INTERNAL') || die();

/**
 * 操作策略抽象基类
 *
 * 提供获取提示词和标签的通用实现，子类只需实现配置键名和上下文构建方法。
 */
abstract class base_strategy implements action_strategy_interface {

    /**
     * 获取策略的提示词文本
     *
     * 优先从插件配置中读取自定义提示词，如果未配置则使用默认语言字符串。
     * 通过 config_manager 获取配置，复用请求级缓存，与代码库其余部分保持一致。
     *
     * @return string 提示词文本
     */
    public function get_prompt(): string {
        $prompt = config_manager::get_config_cached($this->get_prompt_setting_key());
        if ($prompt === null || $prompt === '') {
            $prompt = get_string($this->get_default_prompt_string_key(), 'local_ai_programming_assistant');
        }
        return $prompt;
    }

    /**
     * 获取策略的显示标签
     *
     * @return string 标签文本
     */
    public function get_label(): string {
        return get_string($this->get_label_string_key(), 'local_ai_programming_assistant');
    }

    /**
     * 获取策略标签的语言字符串键名（由子类实现）
     *
     * @return string 语言字符串键名
     */
    abstract protected function get_label_string_key(): string;

    /**
     * 构建仅包含题目文本的上下文字符串
     *
     * 供 question_analysis 和 idea_organization 策略复用。
     *
     * @param array $pagecontext 页面上下文数据
     * @return string 格式化后的上下文字符串
     */
    protected static function build_question_only_context(array $pagecontext): string {
        $parts = [];

        $questiontext = isset($pagecontext['questiontext']) ? (string)$pagecontext['questiontext'] : '';
        if ($questiontext !== '') {
            $cleantext = prompt_filter::clean_question_text($questiontext);
            $parts[] = "{$cleantext}";
        }

        if (empty($parts)) {
            $parts[] = get_string('question_content_not_found', 'local_ai_programming_assistant');
        }
        return "\n" . implode("\n\n", $parts) . "\n\n";
    }

    /**
     * 构建包含所有可用字段的完整页面上下文
     *
     * 包含题目文本、学生代码、编程语言和测试结果等所有可用字段。
     *
     * @param array $pagecontext 页面上下文数据
     * @return string 格式化的上下文字符串
     */
    protected static function build_full_context(array $pagecontext): string {
        $parts = [];

        $questiontext = isset($pagecontext['questiontext']) ? (string)$pagecontext['questiontext'] : '';
        if ($questiontext !== '') {
            $cleantext = prompt_filter::clean_question_text($questiontext);
            $parts[] = "{$cleantext}";
        } else {
            $parts[] = get_string('question_content_not_found', 'local_ai_programming_assistant');
        }
        $studentanswer = isset($pagecontext['studentanswer']) ? (string)$pagecontext['studentanswer'] : '';
        if ($studentanswer !== '') {
            $parts[] = "{$studentanswer}";
        }
        $programminglanguage = isset($pagecontext['programminglanguage']) ? (string)$pagecontext['programminglanguage'] : '';
        if ($programminglanguage !== '') {
            $parts[] = get_string('programming_language_label', 'local_ai_programming_assistant', $programminglanguage);
        }
        $testresults = isset($pagecontext['testresults']) ? (string)$pagecontext['testresults'] : '';
        if ($testresults !== '') {
            $formatted = prompt_filter::format_test_results_from_json($testresults);
            $parts[] = "{$formatted}";
        }

        return "\n" . implode("\n\n", $parts) . "\n\n";
    }
}