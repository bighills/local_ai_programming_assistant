<?php
/**
 * 策略工厂类 - 根据操作类型创建对应的策略实例
 *
 * @author zfl
 * @package    local_ai_programming_assistant
 */
namespace local_ai_programming_assistant\strategy;
defined('MOODLE_INTERNAL') || die();

use local_ai_programming_assistant\config_manager;

/**
 * 策略工厂
 *
 * 根据操作类型标识符创建对应的策略实例。
 * 通过硬编码映射关联操作类型与策略类。
 * 支持的操作类型：free（自由问答）、question_analysis（题目分析）、
 * idea_organization（思路整理）、code_debugging（代码调试）。
 */
class strategy_factory {

    /** @var array<string, class-string<action_strategy_interface>>|null 请求级缓存：操作类型 => 类名 */
    private static ?array $strategymap = null;

    /**
     * 构建操作类型 => 类名的映射表（硬编码）
     *
     * 使用硬编码映射而非 FilesystemIterator 扫描目录，原因：
     * 1. 避免每次请求的磁盘 I/O 和正则匹配开销；
     * 2. 策略类型在编译期已知，硬编码更可预测、更安全；
     * 3. 消除 FilesystemIterator 对目录内容的隐式依赖。
     *
     * @return array<string, string>
     */
    private static function build_strategy_map(): array {
        if (self::$strategymap !== null) {
            return self::$strategymap;
        }
        self::$strategymap = [
            'free' => free_strategy::class,
            'question_analysis' => question_analysis_strategy::class,
            'idea_organization' => idea_organization_strategy::class,
            'code_debugging' => code_debugging_strategy::class,
        ];
        return self::$strategymap;
    }

    /**
     * 根据操作类型创建策略实例
     *
     * @param string $actiontype 操作类型标识符
     * @return action_strategy_interface 对应的策略实例
     */
    public static function create(string $actiontype): action_strategy_interface {
        $map = self::build_strategy_map();
        $classname = $map[$actiontype] ?? null;
        if ($classname !== null) {
            return new $classname();
        }
        // 回退到默认自由问答策略
        config_manager::debug_log(
            'strategy_factory: unknown actiontype "' . $actiontype . '", falling back to free_strategy',
            []
        );
        return new free_strategy();
    }

}