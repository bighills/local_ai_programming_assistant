<?php
/**
 * AI 编程助手 - 配置管理类
 *
 * 集中管理插件配置的读取和缓存，提供统一的配置访问接口。
 * 从 completion.php 中提取，降低核心类的复杂度。
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */

namespace local_ai_programming_assistant;

defined('MOODLE_INTERNAL') || die();

class config_manager {

    /** @var object|null 插件配置的请求级缓存 */
    private static ?object $pluginconfig = null;

    /** @var array<string, string> 规范化后的端点 URL 缓存 */
    private static array $normalizedendpointcache = [];

    /** @var bool|null 调试开关缓存（请求级） */
    private static ?bool $debugenabled = null;

    /** @var array|null 解析后的多端点缓存（请求级） */
    private static ?array $parsedendpoints = null;

    /** @var int|null 上一次解析的多端点配置哈希（用于检测变更） */
    private static ?int $lastendpointconfighash = null;

    /**
     * 重置所有静态缓存（用于 PHP-FPM 常驻进程或单元测试）
     */
    public static function reset(): void {
        self::$pluginconfig = null;
        self::$normalizedendpointcache = [];
        self::$debugenabled = null;
        self::$parsedendpoints = null;
        self::$lastendpointconfighash = null;
    }

    /**
     * 一次性读取所有插件配置（合并读取，减少 DB 查询）
     *
     * 注意：settings.php 中的默认值仅在管理员保存设置页面后生效。
     * 在全新安装、管理员未访问设置页面前，get_config() 返回 null。
     * 因此这里对关键配置项设置合理的回退默认值，确保插件开箱即用。
     *
     * @return object 插件配置对象
     */
    public static function get_plugin_config(): object {
        if (self::$pluginconfig === null) {
            $config = \get_config('local_ai_programming_assistant');
            if (empty($config)) {
                $config = new \stdClass();
            }
            // 应用 settings.php 中的默认值（仅在 DB 中未配置时生效）
            if (!isset($config->enable)) {
                $config->enable = '1';      // settings.php 默认启用，避免全新安装未保存设置页时插件被误判为禁用
            }
            if (!isset($config->logging)) {
                $config->logging = '1';         // settings.php 默认开启
            }
            self::$pluginconfig = $config;
        }
        return self::$pluginconfig;
    }

    /**
     * 读取单个插件配置项（从已缓存的配置中读取）
     *
     * @param string $name 配置项名称
     * @param mixed $default 默认值
     * @return mixed 配置值
     */
    public static function get_config_cached(string $name, mixed $default = ''): mixed {
        $config = self::get_plugin_config();
        return $config->{$name} ?? $default;
    }

    /**
     * 获取最大对话历史条目数（优先从可配置的 max_conversation_turns 读取）
     *
     * @return int 最大历史条目数，默认 100
     */
    public static function get_max_history_count(): int {
        $turns = (int) self::get_config_cached('max_conversation_turns', 0);
        // 每个对话轮次包含用户消息和 AI 回复 2 条记录
        // 未配置时（0）默认 5 轮（10 条），已配置时尊重管理员设置
        return $turns > 0 ? max(2, $turns * 2) : 10;
    }

    /**
     * 获取作为独立消息发送的最近对话轮数
     *
     * @return int 最近对话轮数，默认 3
     */
    public static function get_recent_history_rounds(): int {
        $rounds = (int) self::get_config_cached('recent_history_rounds', 3);
        return max(1, min(10, $rounds));
    }

    /**
     * 获取加入提示词的本地知识库最大条数
     *
     * @return int 知识库最大条数，默认 3
     */
    public static function get_kb_max_context_chunks(): int {
        $chunks = (int) self::get_config_cached('kb_max_context_chunks', 3);
        return max(1, min(10, $chunks));
    }

    /**
     * 规范化 API 端点 URL（确保以 /chat/completions 结尾）
     *
     * 使用静态缓存避免同一请求中多次执行相同的字符串操作。
     *
     * @param string $endpoint 原始端点 URL
     * @return string 规范化后的端点 URL
     */
    public static function normalize_endpoint(string $endpoint): string {
        if (isset(self::$normalizedendpointcache[$endpoint])) {
            return self::$normalizedendpointcache[$endpoint];
        }
        // 分离查询参数和锚点，仅处理 URL 路径部分
        $baseurl = $endpoint;
        $suffix = '';
        $querypos = strpos($endpoint, '?');
        if ($querypos !== false) {
            $baseurl = substr($endpoint, 0, $querypos);
            $suffix = substr($endpoint, $querypos);
        } else {
            $fragmentpos = strpos($endpoint, '#');
            if ($fragmentpos !== false) {
                $baseurl = substr($endpoint, 0, $fragmentpos);
                $suffix = substr($endpoint, $fragmentpos);
            }
        }
        $normalized = rtrim($baseurl, '/');
        if ($normalized !== '' && !str_ends_with($normalized, '/chat/completions')) {
            $normalized .= '/chat/completions';
        }
        $result = $normalized . $suffix;
        self::$normalizedendpointcache[$endpoint] = $result;
        return $result;
    }

    /**
     * 解析多端点配置列表（JSON 数组）
     *
     * 从 apiendpoint_list 配置读取并校验 JSON。仅保留包含 endpoint 字段的条目。
     * 结果在请求级缓存，配置变更后通过 hash 检测失效。
     *
     * @return array<int, array{name:string, endpoint:string, apikey:string, model:string, raw:array}>
     */
    public static function parse_endpoint_list(): array {
        $config = self::get_plugin_config();
        $raw = (string)($config->apiendpoint_list ?? '');
        // 使用 crc32 生成配置内容 hash，替代不可靠的 strlen()
        // 避免两个内容不同但长度相同的 JSON 配置导致缓存无法正确失效
        $currenthash = crc32($raw);

        if (self::$parsedendpoints !== null && self::$lastendpointconfighash === $currenthash) {
            return self::$parsedendpoints;
        }

        $parsed = [];
        if (trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $endpoint = trim((string)($item['endpoint'] ?? ''));
                    if ($endpoint === '') {
                        continue;
                    }
                    $parsed[] = [
                        'name' => trim((string)($item['name'] ?? '')),
                        'endpoint' => self::normalize_endpoint($endpoint),
                        'apikey' => trim((string)($item['apikey'] ?? '')),
                        'model' => trim((string)($item['model'] ?? '')),
                        'raw' => $item,
                    ];
                }
            } else if (self::is_debug_enabled()) {
                // 仅在调试模式记录，避免污染生产日志
                self::debug_log('parse_endpoint_list: invalid JSON', [
                    'json_error' => json_last_error_msg(),
                    'preview' => mb_substr($raw, 0, 200, 'UTF-8'),
                ]);
            }
        }

        self::$parsedendpoints = $parsed;
        self::$lastendpointconfighash = $currenthash;
        return $parsed;
    }

    /**
     * 构建端点选择器的选项列表（用于 settings.php 的 admin_setting_configselect）
     *
     * 合并 "默认单端点"、"轮询/自动选择" 以及多端点列表中的命名端点。
     *
     * @return array<string, string>
     */
    public static function get_endpoint_selector_options(): array {
        $options = [
            '__default__' => get_string('apiendpoint_default_selector', 'local_ai_programming_assistant'),
        ];
        $endpoints = self::parse_endpoint_list();
        if (!empty($endpoints)) {
            $options['__roundrobin__'] = get_string('apiendpoint_roundrobin_selector', 'local_ai_programming_assistant');
            foreach ($endpoints as $idx => $ep) {
                $label = !empty($ep['name']) ? $ep['name'] : ('endpoint-' . ($idx + 1));
                $shortendpoint = self::shorten_endpoint_for_display($ep['endpoint']);
                $suffix = $shortendpoint !== '' ? ' (' . $shortendpoint . ')' : '';
                $options['ep:' . $idx] = $label . $suffix;
            }
        }
        return $options;
    }

    /**
     * 解析活动端点配置（根据 apiendpoint_selector 返回当前应使用的端点）
     *
     * 返回的配置结构与 ai_backend::load_direct_config() 一致，
     * ai_backend 可直接使用该返回值覆盖默认的 endpoint/apikey/model。
     *
     * @return array{endpoint:string, apikey:string, model:string, strategy:string, index:int}|null
     */
    public static function resolve_active_endpoint(): ?array {
        $config = self::get_plugin_config();
        $selector = (string)($config->apiendpoint_selector ?? '__default__');
        $endpoints = self::parse_endpoint_list();

        // 显式索引选择（ep:N）
        if (preg_match('/^ep:(\d+)$/', $selector, $m)) {
            $idx = (int)$m[1];
            if (isset($endpoints[$idx])) {
                $ep = $endpoints[$idx];
                return [
                    'endpoint' => $ep['endpoint'],
                    'apikey' => $ep['apikey'],
                    'model' => $ep['model'],
                    'strategy' => 'fixed',
                    'index' => $idx,
                ];
            }
            // 索引无效时回退到默认
            self::debug_log('resolve_active_endpoint: invalid endpoint index, fallback to default', [
                'selector' => $selector,
                'count' => count($endpoints),
            ]);
        }

        // 轮询/自动选择
        if ($selector === '__roundrobin__') {
            if (empty($endpoints)) {
                return null;
            }
            $strategy = (string)($config->apiendpoint_strategy ?? 'roundrobin');
            $count = count($endpoints);
            $nextidx = self::fetch_and_bump_round_robin_index($count);
            $ep = $endpoints[$nextidx];
            return [
                'endpoint' => $ep['endpoint'],
                'apikey' => $ep['apikey'],
                'model' => $ep['model'],
                'strategy' => $strategy,
                'index' => $nextidx,
            ];
        }

        // 默认单端点（返回 null，让 ai_backend 使用原有配置）
        return null;
    }

    /**
     * 获取轮询下一个索引（原子自增，跨请求持久化）
     *
     * 优先使用 Moodle Cache API 实现可靠的原子递增，支持分布式环境；
     * 若 Cache 不可用，则回退到 /tmp 文件锁（兼容单机环境）；
     * 最终兜底为随机选择，保证在极端情况下也不阻塞请求。
     *
     * @param int $count 端点数
     * @return int 下一个要使用的索引
     */
    public static function fetch_and_bump_round_robin_index(int $count): int {
        if ($count < 1) {
            return 0;
        }

        // 1. 优先尝试 Moodle Cache API (支持集群/分布式).
        $cacheindex = self::fetch_index_via_cache($count);
        if ($cacheindex !== null) {
            return $cacheindex;
        }

        // 2. 回退: 使用 /tmp 文件锁 (单机).
        $fileindex = self::fetch_index_via_tempfile($count);
        if ($fileindex !== null) {
            return $fileindex;
        }

        // 3. 最终兜底: 随机选择.
        return random_int(0, $count - 1);
    }

    /**
     * 通过 Moodle Cache API 获取轮询索引
     *
     * 使用原子 get/set 模式实现乐观锁：读取->计算->写入。
     * 在高并发下可能出现少量冲突（写失败），此时返回 null 触发回退机制。
     *
     * @param int $count 端点数
     * @return int|null 成功返回索引，失败返回 null
     */
    private static function fetch_index_via_cache(int $count): ?int {
        try {
            $cache = \cache::make('local_ai_programming_assistant', 'round_robin_index');
            $key = 'current_index';

            $current = $cache->get($key);
            if ($current === false || !is_int($current)) {
                $current = 0;
            }

            $next = $current % $count;
            $newvalue = ($next + 1) % $count;

            // 尝试写入新值，如果失败说明有并发竞争，返回 null 让外层处理。
            if ($cache->set($key, $newvalue)) {
                return $next;
            }
            return null;
        } catch (\Throwable $e) {
            self::debug_log('fetch_index_via_cache failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * 通过 /tmp 文件锁获取轮询索引（回退方案）
     *
     * 使用 flock 实现单机原子操作，避免多进程竞争。
     * 使用 $CFG->dataroot 的 MD5 作为命名空间，避免不同 Moodle 实例之间的冲突。
     *
     * @param int $count 端点数
     * @return int|null 成功返回索引，失败返回 null
     */
    private static function fetch_index_via_tempfile(int $count): ?int {
        global $CFG;
        // 使用 dataroot 的 MD5 作为命名空间，确保同一服务器上的多个 Moodle 实例互不干扰
        $sitehash = md5($CFG->dataroot ?? 'default');
        $counterfile = sys_get_temp_dir() . "/ai_programming_assistant_rr_counter_{$sitehash}";
        $fp = @fopen($counterfile, 'c+');
        if ($fp === false) {
            return null;
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                return null;
            }
            $raw = trim((string)fread($fp, 32));
            $current = $raw !== '' && ctype_digit($raw) ? (int)$raw : 0;
            $next = $current % $count;
            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, (string)(($next + 1) % $count));
            fflush($fp);
            flock($fp, LOCK_UN);
            return $next;
        } catch (\Throwable $e) {
            self::debug_log('fetch_index_via_tempfile failed', ['error' => $e->getMessage()]);
            return null;
        } finally {
            fclose($fp);
        }
    }

    /**
     * 为多端点选择器生成简短的端点显示名
     *
     * @param string $endpoint 完整端点 URL
     * @return string 简短显示名（不含协议，最多 40 字符）
     */
    private static function shorten_endpoint_for_display(string $endpoint): string {
        $host = parse_url($endpoint, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }
        $path = parse_url($endpoint, PHP_URL_PATH) ?? '';
        $short = $host;
        if ($path !== '' && $path !== '/chat/completions') {
            $short .= $path;
        }
        if (mb_strlen($short, 'UTF-8') > 40) {
            $short = mb_substr($short, 0, 37, 'UTF-8') . '...';
        }
        return $short;
    }

    /**
     * 应用 PHP 最大执行时间限制
     *
     * 从插件配置读取 max_execution_time，应用到当前脚本。
     * 默认值 0 表示不限制，防止 AI API 响应较慢时产生 504 Gateway Time-out。
     */
    public static function apply_time_limit(): void {
        $timeout = (int) self::get_config_cached('max_execution_time');
        if ($timeout < 1) {
            set_time_limit(0);
        } else {
            set_time_limit($timeout);
        }
    }

    /**
     * 判断是否启用调试模式（带缓存）
     *
     * @return bool
     */
    public static function is_debug_enabled(): bool {
        if (self::$debugenabled !== null) {
            return self::$debugenabled;
        }
        global $CFG;
        $globaldebug = !empty($CFG->debug) && $CFG->debug >= DEBUG_DEVELOPER;
        $plugindebug = !empty(self::get_config_cached('debug'));
        self::$debugenabled = $globaldebug || $plugindebug;
        return self::$debugenabled;
    }

    /**
     * 记录调试日志（仅在 Moodle 开发者调试模式下输出）
     *
     * @param string $message 日志消息
     * @param mixed $data 附加数据（可选）
     */
    public static function debug_log(string $message, $data = null): void {
        if (!self::is_debug_enabled()) {
            return;
        }
        $logmsg = '[ai_programming_assistant] ' . $message;
        if ($data !== null) {
            $logmsg .= ': ' . (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        error_log($logmsg);
    }
}