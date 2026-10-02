<?php
/**
 * AI 编程助手 - 速率限制类
 *
 * 使用 Moodle 锁工厂实现原子操作的速率限制检查，
 * 确保每分钟内每个用户的请求次数不超过配置上限。
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */

namespace local_ai_programming_assistant;

defined('MOODLE_INTERNAL') || die();

class rate_limiter {

    /** @var int 速率限制锁超时时间（秒） */
    const RATE_LIMIT_LOCK_TIMEOUT = 2;

    /** @var \cache|null 速率限制缓存实例（请求级复用） */
    private static ?\cache $ratecache = null;

    /**
     * 重置所有静态缓存（用于 PHP-FPM 常驻进程或单元测试）
     */
    public static function reset(): void {
        self::$ratecache = null;
    }

    /**
     * 检查速率限制（滑动窗口算法，防止并发绕过）
     *
     * 使用 Moodle 锁工厂确保 read-then-write 操作的原子性，
     * 避免并发请求同时读取相同计数导致限制被绕过。
     *
     * @return array|null 超过限制时返回错误数组，否则返回 null
     */
    public static function check_rate_limit(): ?array {
        global $USER;

        if (empty($USER->id) || isguestuser()) {
            return null;
        }

        // 速率限制设为 0 或负值表示不限制
        $ratelimit = (int) config_manager::get_config_cached('ratelimit_per_minute', 15);
        if ($ratelimit < 1) {
            return null;
        }

        if (self::$ratecache === null) {
            self::$ratecache = \cache::make('local_ai_programming_assistant', 'ratelimit');
        }
        $cachekey = 'ratelimit_' . $USER->id;

        $now = time();
        $windowstart = $now - 60;

        $lockkey = 'ratelimit_lock_' . $USER->id;
        $lock = null;
        try {
            $lockfactory = \core\lock\lock_config::get_lock_factory('local_ai_programming_assistant');
            $lock = $lockfactory->get_lock($lockkey, self::RATE_LIMIT_LOCK_TIMEOUT);
        } catch (\Throwable $e) {
            config_manager::debug_log('rate_limiter: lock factory unavailable, degrading to lock-free mode', $e->getMessage());
            $lock = null;
        }

        try {
            $calldata = self::$ratecache->get($cachekey);

            if ($calldata === false || !is_array($calldata)) {
                // 首次请求，记录当前请求的时间戳
                self::$ratecache->set($cachekey, ['timestamps' => [$now]]);
                return null;
            }

            // 滑动窗口：移除 60 秒前的过期时间戳
            $timestamps = $calldata['timestamps'] ?? [];
            if (!empty($timestamps)) {
                // O(1) 早退：如果最新的时间戳仍在窗口外，全部过期，直接重置
                if ($timestamps[count($timestamps) - 1] < $windowstart) {
                    self::$ratecache->set($cachekey, ['timestamps' => [$now]]);
                    return null;
                }
                // 使用 array_filter 移除过期时间戳（数组很小，不超过 15 个元素，线性扫描更简单高效）
                $timestamps = array_values(array_filter($timestamps, fn($ts) => $ts >= $windowstart));
            }

            if (count($timestamps) >= $ratelimit) {
                return [
                    'result' => 'error',
                    'message' => get_string('error_rate_limit', 'local_ai_programming_assistant', $ratelimit),
                    // ratelimited 标志：供 manager 识别限流结果，直接返回友好提示，
                    // 不经过 map_user_facing_error 降级为通用错误，也不记入失败统计。
                    'ratelimited' => true,
                ];
            }

            // 记录当前请求时间戳
            $timestamps[] = $now;
            self::$ratecache->set($cachekey, ['timestamps' => $timestamps]);

            return null;
        } finally {
            if ($lock) {
                $lock->release();
            }
        }
    }
}