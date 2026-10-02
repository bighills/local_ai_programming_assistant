<?php
/**
 * AI 编程助手 - 提示词过滤器
 *
 * 提供多层内容过滤机制：
 * 1. 拦截规则：匹配特定关键词/正则后直接返回预设回复（不调用 AI）
 * 2. 输入拦截列表：阻止包含特定内容的用户消息发送给 AI
 * 3. 输入净化规则：替换用户消息中的敏感关键词后再发送给 AI
 * 4. 输出拦截列表：替换 AI 回复中的敏感内容后再显示给用户
 *
 * @author zfl
 * @package    local_ai_programming_assistant
 */
namespace local_ai_programming_assistant;
defined('MOODLE_INTERNAL') || die();

class prompt_filter {

    /** @var array|null 已解析的拦截规则缓存 */
    private static ?array $parsedinterceptionrules = null;

    /** @var string[] PCRE 正则分隔符列表 */
    private const PCRE_DELIMITERS = ['/', '#', '~', '!', '|', '@', '%', '`'];

    /** @var int 解析状态：空闲 */
    private const PARSE_STATE_IDLE = 0;
    /** @var int 解析状态：正在收集 trigger */
    private const PARSE_STATE_TRIGGER = 1;
    /** @var int 解析状态：正在收集 response */
    private const PARSE_STATE_RESPONSE = 2;

    /** @var array<string, array>|null 已解析的替换规则缓存（configkey => [line1, line2, ...]） */
    private static ?array $parsedreplacementrules = null;

    /** @var array<string, bool> 正则安全性检查结果缓存（pattern => safe） */
    private static array $regexsafecache = [];

    /**
     * 重置所有静态缓存（用于 PHP-FPM 常驻进程或单元测试）
     */
    public static function reset(): void {
        self::$parsedinterceptionrules = null;
        self::$parsedreplacementrules = null;
        self::$regexsafecache = [];
        self::$inlinekeypatterns = null;
        self::$cachedebug = null;
    }

    /** @var bool|null 请求级 debug 配置缓存（check_interception_rules 使用） */
    private static ?bool $cachedebug = null;

    /**
     * 读取插件配置项（委托给 config_manager 的请求级缓存）
     *
     * 复用 config_manager 已缓存的配置对象，消除 prompt_filter 独立的 get_config() 调用，
     * 避免同一请求中第三次 DB 查询。
     *
     * @param string $name 配置项名称
     * @return string 配置值（空字符串表示未设置）
     */
    private static function get_config_value(string $name): string {
        return (string) config_manager::get_config_cached($name, '');
    }

    /**
     * 正则操作前的公共准备：安全性检查 + subject 长度限制 + 时间限制重置
     *
     * @param string $pattern 正则表达式
     * @param string $subject 目标字符串
     * @param string $caller  调用方名称（用于日志）
     * @return array{0: bool, 1: string} [是否安全, 截断后的 subject]
     */
    private static function safe_regex_prepare(string $pattern, string $subject, string $caller): array {
        if (!self::is_regex_safe($pattern)) {
            debugging("[ai_programming_assistant] {$caller}: pattern rejected as unsafe: " . $pattern, DEBUG_DEVELOPER);
            return [false, $subject];
        }
        return [true, mb_substr($subject, 0, 100000, 'UTF-8')];
    }

    private static function safe_preg_replace(string $pattern, string $replace, string $subject): string {
        list($safe, $subject) = self::safe_regex_prepare($pattern, $subject, 'safe_preg_replace');
        if (!$safe) {
            return $subject;
        }
        $result = @preg_replace($pattern, $replace, $subject);
        if ($result === null) {
            $err = error_get_last();
            debugging('[ai_programming_assistant] preg_replace failed: ' . ($err['message'] ?? 'unknown') . ' pattern: ' . $pattern, DEBUG_DEVELOPER);
            return $subject;
        }
        return $result;
    }

    private static function safe_preg_match(string $pattern, string $subject): bool {
        list($safe, $subject) = self::safe_regex_prepare($pattern, $subject, 'safe_preg_match');
        if (!$safe) {
            return false;
        }
        $result = @preg_match($pattern, $subject);
        if ($result === false) {
            $err = error_get_last();
            debugging('[ai_programming_assistant] preg_match failed: ' . ($err['message'] ?? 'unknown') . ' pattern: ' . $pattern, DEBUG_DEVELOPER);
            return false;
        }
        return $result === 1;
    }

    /**
     * 验证正则表达式是否安全
     *
     * 防止 ReDoS 攻击：
     * - 检查正则表达式语法是否正确
     * - 限制正则表达式长度
     * - 检查是否包含危险的嵌套量词
     *
     * @param string $pattern 正则表达式（包含分隔符和修饰符）
     * @return bool 是否安全
     */
    private static function is_regex_safe(string $pattern): bool {
        if (isset(self::$regexsafecache[$pattern])) {
            return self::$regexsafecache[$pattern];
        }
        $result = self::is_regex_safe_internal($pattern);
        self::$regexsafecache[$pattern] = $result;
        return $result;
    }

    private static function is_regex_safe_internal(string $pattern): bool {
        // 使用 strlen（字节长度）而非 mb_strlen：PCRE 按字节处理，
        // 且字节级限制比字符级限制更严格，对 ReDoS 防御更有效
        if (strlen($pattern) > 512) {
            return false;
        }

        // 使用空字符串测试正则语法：既能验证语法正确性，又不会触发 ReDoS
        $result = @preg_match($pattern, '');
        if ($result === false) {
            return false;
        }
        // 检查正则引擎错误（如 backtrack limit、recursion limit 等）
        $pregError = preg_last_error();
        if ($pregError !== PREG_NO_ERROR) {
            return false;
        }

        $delimiter = $pattern[0] ?? '/';
        $lastDelimPos = strrpos($pattern, $delimiter);
        if ($lastDelimPos === false || $lastDelimPos === 0) {
            return false;
        }
        // 使用 substr 而非 mb_substr：strrpos 返回字节位置，substr 与之匹配
        $body = substr($pattern, 1, $lastDelimPos - 1);

        if (self::contains_nested_quantifiers($body)) {
            return false;
        }

        $largeRangePattern = '/\{(\d+),(\d*)\}/';
        if (preg_match_all($largeRangePattern, $body, $matches)) {
            foreach ($matches[1] as $idx => $minStr) {
                $min = (int) $minStr;
                $maxStr = $matches[2][$idx];
                $max = $maxStr === '' ? PHP_INT_MAX : (int) $maxStr;
                if ($max - $min > 100) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function contains_nested_quantifiers(string $body): bool {
        // 使用正则检测嵌套量词：分组 (...) 内部包含量词 (*+?) 且分组本身被量词修饰
        // 如：(a+)+ 或 (a{2,})+ 等模式
        // 先移除转义序列和字符类，防止误报（合并两次替换为一次遍历）
        $cleaned = preg_replace(['/\\\\./', '/\[[^\]]*\]/'], ['', ''], $body);
        // 检测嵌套量词：(...) 内含 *、+、? 量词，且分组外部紧跟量词
        // 如 (a+)+ 或 (a{2,})+ 等 — 分组内部有量词，分组外部也有量词
        // [^()]*[*+?] 无需使用排除字符类，因为转义序列和字符类已在上方被移除
        // 旧正则 (?:[^|*+?])[*+?] 使用排除字符类，遗漏了 (a+)+ 这类量词紧跟量词的模式
        if (preg_match('/\([^()]*[*+?][^()]*\)[*+?{]/', $cleaned)) {
            return true;
        }
        // 检测 {...} 量词形式：(...){m,n}
        if (preg_match('/\([^()]*\)\{\d/', $cleaned)) {
            return true;
        }
        return false;
    }

    /**
     * 获取已解析的替换规则（请求级缓存，避免重复 explode）
     *
     * @param string $configkey 配置项名称
     * @return array 规则行数组
     */
    private static function get_parsed_replacement_rules(string $configkey): array {
        self::$parsedreplacementrules ??= [];
        if (!isset(self::$parsedreplacementrules[$configkey])) {
            $raw = self::get_config_value($configkey);
            if (empty($raw)) {
                self::$parsedreplacementrules[$configkey] = [];
            } else {
                $lines = explode("\n", $raw);
                // 预分类：每行预先判断是正则还是字面量，避免 apply_replacement_rules 中重复分类
                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    if (empty($trimmed)) {
                        continue;
                    }
                    $isregex = self::is_regex_pattern($trimmed);
                    $pattern = null;
                    if ($isregex) {
                        $pattern = @preg_match($trimmed, '') === false ? null : $trimmed;
                    }
                    self::$parsedreplacementrules[$configkey][] = [
                        'trimmed' => $trimmed,
                        'isregex' => $isregex,
                        'pattern' => $pattern,
                    ];
                }
            }
        }
        return self::$parsedreplacementrules[$configkey];
    }

    /**
     * 判断一行配置文本是否为 PCRE 正则模式
     *
     * 规则：以 PCRE 定界符开头，且有匹配的结束定界符和可选修饰符。
     * 与直接将所有文本传给 safe_preg_replace/safe_preg_match 不同，
     * 此方法仅做结构判断，不编译验证（编译验证由 safe_* 函数内部完成）。
     *
     * @param string $line 单行配置文本
     * @return bool 是否为正则模式
     */
    private static function is_regex_pattern(string $line): bool {
        $firstChar = $line[0];
        if (!in_array($firstChar, self::PCRE_DELIMITERS, true)) {
            return false;
        }
        // 结构检测：定界符开头 → 任意内容 → 定界符 → 可选修饰符
        return (bool)preg_match(
            '/^' . preg_quote($firstChar, '/') . '.+' . preg_quote($firstChar, '/') . '[imsuxADSUXJ]*$/',
            $line
        );
    }

    /**
     * 通用替换规则应用方法
     *
     * 从指定配置项读取规则（每行一条规则），逐行应用到字符串。
     * 支持正则模式（以 / 或其它 PCRE 定界符开头）和字面量模式。
     * 字面量模式支持 search|replace 格式，默认替换值为 '***'。
     *
     * @param string $configkey 配置项名称
     * @param string $subject 要处理的字符串
     * @return string 处理后的字符串
     */
    private static function apply_replacement_rules(string $configkey, string $subject): string {
        $lines = self::get_parsed_replacement_rules($configkey);
        if (empty($lines)) {
            return $subject;
        }

        foreach ($lines as $entry) {
            $line = $entry['trimmed'];

            if ($entry['isregex']) {
                if ($entry['pattern'] !== null) {
                    $subject = self::safe_preg_replace($entry['pattern'], '***', $subject);
                }
            } else {
                // 字面量模式：search|replace 格式（首个 | 为分隔符）
                // 注意：搜索词本身包含 | 时需转义为 \| 或改用正则模式
                $parts = explode('|', $line, 2);
                $search = trim($parts[0]);
                $replace = isset($parts[1]) ? trim($parts[1]) : '***';
                if (!empty($search)) {
                    $subject = str_ireplace($search, $replace, $subject);
                }
            }
        }

        return $subject;
    }

    /**
     * 占位符替换 - 将提示词中的占位符替换为实际的页面上下文值
     *
     * 注意：{studentanswer} 的截断和净化已由 prompt_builder::get_prompt_info() 提前处理，
     * 此处直接使用预处理后的值，避免重复的 truncate_code + sanitize_source_code 调用。
     *
     * 防御纵深：对所有学生内容类占位符（questiontext、studentanswer、testresults、
     * programminglanguage）执行 escape_prompt_markup_tags()，防止管理员在自定义提示词
     * 模板中用 XML 标签包装占位符（如 <student_code>{studentanswer}</student_code>）
     * 时，学生内容中包含 </student_code> 等闭合序列而"逃逸"出数据包装。
     *
     * @param string $prompt 包含占位符的提示词模板
     * @param array  $pagecontext 页面上下文数据（studentanswer 已预处理）
     * @return string 替换后的提示词
     */
    public static function apply_placeholder_substitution(string $prompt, array $pagecontext): string {
        $testresults = $pagecontext['testresults'] ?? '';

        $replacements = [
            '{questiontext}' => self::escape_prompt_markup_tags(
                self::clean_question_text($pagecontext['questiontext'] ?? '')
            ),
            '{studentanswer}' => self::escape_prompt_markup_tags(
                (string)($pagecontext['studentanswer'] ?? '')
            ),
            // 先格式化再转义：escape_prompt_markup_tags 的正则已处理 JSON 转义的 \/
            // 形式（json_encode 将 / 转义为 \/），因此无论 format_test_results_from_json
            // 输出纯文本还是回退到 JSON，标记标签闭合序列都能被正确转义为实体。
            '{testresults}' => self::escape_prompt_markup_tags(
                self::format_test_results_from_json($testresults)
            ),
            '{programminglanguage}' => self::escape_prompt_markup_tags(
                (string)($pagecontext['programminglanguage'] ?? '')
            ),
        ];
        return str_replace(array_keys($replacements), array_values($replacements), $prompt);
    }

    /**
     * 过滤用户输入消息
     *
     * 执行两步过滤：
     * 1. 检查输入拦截列表：如果消息匹配拦截规则，则阻止发送
     * 2. 执行输入净化：替换消息中的敏感关键词
     *
     * @param string $message 用户输入的原始消息
     * @return array 包含 filtered（是否被拦截）、message（处理后的消息）、reason（拦截原因）
     */
    public static function filter_user_message(string $message): array {
        // 第一步：检查输入拦截列表（仅在配置了拦截规则时执行）
        $blockedlines = self::get_parsed_replacement_rules('inputblocklist');
        if (!empty($blockedlines)) {
            foreach ($blockedlines as $entry) {
                // 注意：get_parsed_replacement_rules() 存储的键为 'trimmed'（与 apply_replacement_rules 保持一致）
                // 此前误用 'raw' 导致关键词模式的输入拦截列表完全失效（stripos 接收 null 始终返回 false）
                $line = $entry['trimmed'];

                if ($entry['isregex']) {
                    if ($entry['pattern'] !== null && self::safe_preg_match($entry['pattern'], $message)) {
                        $reason = get_string('filter_blocked_regex', 'local_ai_programming_assistant');
                        return ['filtered' => true, 'message' => $message, 'reason' => $reason];
                    }
                } else {
                    if ($line !== '' && stripos($message, $line) !== false) {
                        $reason = get_string('filter_blocked_keyword', 'local_ai_programming_assistant');
                        return ['filtered' => true, 'message' => $message, 'reason' => $reason];
                    }
                }
            }
        }

        // 第二步：执行输入净化规则
        $message = self::apply_replacement_rules('inputsanitize', $message);

        return ['filtered' => false, 'message' => $message, 'reason' => ''];
    }

    /**
     * 过滤 AI 回复内容
     *
     * 根据输出拦截列表替换 AI 回复中的敏感关键词或正则匹配内容。
     * 同时将知识库引用【来源：文件名】或【参考：文件名】替换为可点击的 HTML 链接。
     *
     * @param string $response AI 返回的原始回复
     * @return string 过滤后的回复
     */
    public static function filter_ai_response(string $response): string {
        if (config_manager::is_debug_enabled()) {
            config_manager::debug_log('prompt_filter: filter_ai_response - start', [
                'input_preview' => mb_substr($response, 0, 200, 'UTF-8'),
                'input_length' => strlen($response),
            ]);
        }
        $result = self::apply_replacement_rules('outputblocklist', $response);
        // 将知识库引用【来源：文件名】转换为可点击的 HTML 链接
        $result = kb_service::render_citation_links($result);
        if (config_manager::is_debug_enabled()) {
            config_manager::debug_log('prompt_filter: filter_ai_response - end', [
                'output_preview' => mb_substr($result, 0, 200, 'UTF-8'),
                'output_length' => strlen($result),
                'changed' => ($result !== $response),
            ]);
        }
        return $result;
    }

    /**
     * 解析单行内的所有 key:value 对
     *
     * 支持 trigger: xxx mode: regex response: yyy 内联格式。
     *
     * @param string $line 要解析的行
     * @param array $keywords 要匹配的关键字列表
     * @return array 解析结果，包含 ['values' => ['key' => 'value'], 'remaining' => '剩余字符串']
     */
    /** @var array|null 请求级缓存：预编译的 parse_inline_keys 正则模式 */
    private static ?array $inlinekeypatterns = null;

    /**
     * 获取预编译的 inline key 解析模式（请求级缓存）
     *
     * @return array{pattern: string, needles: array} pattern 和对应的序列化 needle 数组
     */
    private static function get_inline_key_patterns(): array {
        if (self::$inlinekeypatterns !== null) {
            return self::$inlinekeypatterns;
        }
        $keywords = ['trigger', 'mode', 'response'];
        self::$inlinekeypatterns = [
            'pattern' => '/^(' . implode('|', $keywords) . '):\s*/',
            'needles' => array_map(fn($k) => ' ' . $k . ':', $keywords),
        ];
        return self::$inlinekeypatterns;
    }

    private static function parse_inline_keys(string $line, array $keywords): array {
        $defaultkeywords = ['trigger', 'mode', 'response'];
        // 当使用默认 keywords 时，使用预编译模式避免每次构建正则
        if ($keywords === $defaultkeywords) {
            $p = self::get_inline_key_patterns();
            $pattern = $p['pattern'];
            $needles = $p['needles'];
        } else {
            // 非默认 keywords 时动态构建（理论上不应发生）
            $pattern = '/^(' . implode('|', $keywords) . '):\s*/';
            $needles = array_map(fn($k) => ' ' . $k . ':', $keywords);
        }

        $remaining = $line;
        $values = [];

        while (preg_match($pattern, $remaining, $m)) {
            $key = $m[1];
            $valuestart = strlen($m[0]);
            $rest = substr($remaining, $valuestart);

            $nextkeypos = strlen($rest);
            foreach ($needles as $nk) {
                $needlePos = strpos($rest, $nk);
                if ($needlePos !== false && $needlePos < $nextkeypos) {
                    $nextkeypos = $needlePos;
                }
            }

            $values[$key] = trim(substr($rest, 0, $nextkeypos));
            $remaining = trim(substr($rest, $nextkeypos));
        }

        return ['values' => $values, 'remaining' => $remaining];
    }

    /**
     * 获取已解析的拦截规则（带请求级缓存）
     *
     * 将原始配置字符串解析为结构化数组并缓存，避免每次调用重复解析。
     *
     * @return array 解析后的规则数组，每项包含 ['trigger', 'response', 'mode']
     */
    private static function get_parsed_interception_rules(): array {
        if (self::$parsedinterceptionrules !== null) {
            return self::$parsedinterceptionrules;
        }

        $raw = self::get_config_value('interceptionrules');
        if (empty($raw)) {
            self::$parsedinterceptionrules = [];
            return [];
        }

        $ruleblocks = preg_split('/\n\s*---\s*\n/', "\n" . trim($raw) . "\n");
        $result = [];

        foreach ($ruleblocks as $block) {
            $block = trim($block);
            if (empty($block)) {
                continue;
            }
            $entry = self::parse_rule_block($block);
            if ($entry !== null) {
                $result[] = $entry;
            }
        }

        self::$parsedinterceptionrules = $result;
        return $result;
    }

    /**
     * 解析单个拦截规则块
     *
     * 使用状态机模式解析 trigger/mode/response 三元组。
     *
     * @param string $block 规则块文本
     * @return array|null 解析后的规则，解析失败返回 null
     */
    private static function parse_rule_block(string $block): ?array {
        $lines = explode("\n", $block);
        $trigger = '';
        $triggertokens = []; // 收集独立 trigger，避免用 | 拼接后 explode 将包含 | 的 keyword trigger 错误拆分
        $response = '';
        $mode = 'keyword';
        $state = self::PARSE_STATE_IDLE;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^---\s*$/', $trimmed) || empty($trimmed)) {
                // 在 response 状态下，空行保留为换行
                if ($state === self::PARSE_STATE_RESPONSE) {
                    $response .= "\n";
                }
                continue;
            }

            // 检查是否包含内联 key:value 对
            $parsed = self::parse_inline_keys($trimmed, ['trigger', 'mode', 'response']);
            $values = $parsed['values'];

            if ($state === self::PARSE_STATE_IDLE) {
                // 空闲状态：等待 trigger 或直接出现的 trigger:response 行
                if (isset($values['trigger'])) {
                    $trigger = $values['trigger'];
                    $triggertokens[] = $values['trigger'];
                    $state = self::PARSE_STATE_TRIGGER;
                }
                if (isset($values['mode'])) {
                    $mode = $values['mode'];
                }
                if (isset($values['response'])) {
                    $response = $values['response'];
                    $state = self::PARSE_STATE_RESPONSE;
                }
            } elseif ($state === self::PARSE_STATE_TRIGGER) {
                // Trigger 状态：收集多行 trigger 直到遇到 mode:/response:
                if (self::should_end_trigger($trigger, $trimmed) && preg_match('/^(mode|response):\s*/', $trimmed)) {
                    $state = self::PARSE_STATE_IDLE;
                    // 重新解析当前行
                    if (isset($values['mode'])) {
                        $mode = $values['mode'];
                    }
                    if (isset($values['response'])) {
                        $response = $values['response'];
                        $state = self::PARSE_STATE_RESPONSE;
                    }
                } else {
                    // 多行 trigger：收集到数组，避免用 | 拼接后 explode 将包含 | 字符的 trigger 错误拆分
                    $triggertokens[] = $trimmed;
                }
            } elseif ($state === self::PARSE_STATE_RESPONSE) {
                // Response 状态：收集多行 response
                if (preg_match('/^(trigger|mode):\s*/', $trimmed)) {
                    // 新规则段开始，结束当前规则
                    break;
                }
                $response .= "\n" . $trimmed;
            }
        }

        $trigger = trim($trigger);
        $response = trim($response);

        if (empty($trigger) || empty($response)) {
            $debug = (bool)self::get_config_value('debug');
            if ($debug) {
                $reason = empty($trigger) ? 'empty trigger' : 'empty response';
                debugging('[ai_programming_assistant] interception rule skipped: ' . $reason
                    . ' trigger="' . s($trigger) . '" mode=' . $mode, DEBUG_DEVELOPER);
            }
            return null;
        }

        $entry = ['response' => $response, 'mode' => $mode];
        if ($mode === 'regex') {
            $firstchar = $trigger[0] ?? '';
            if (!in_array($firstchar, self::PCRE_DELIMITERS, true)) {
                $pattern = '~' . $trigger . '~';
            } else {
                $pattern = $trigger;
            }
            $compiled = @preg_match($pattern, '') === false ? null : $pattern;
            $entry['trigger'] = $trigger;
            $entry['pattern'] = $compiled;
        } else {
            // 使用独立的 $triggertokens 数组，避免 explode('|', $trigger) 将 trigger 文本中合法 | 字符错误拆分
            $entry['triggers'] = array_values(array_filter($triggertokens, fn($v) => trim($v) !== ''));
        }
        return $entry;
    }

    /**
     * 判断多行 trigger 是否应该结束
     *
     * @param string $currenttrigger 当前已累积的 trigger 文本
     * @param string $_unused 下一行文本（当前实现未使用，保留参数以备未来扩展）
     * @return bool 是否应结束 trigger 收集
     */
    private static function should_end_trigger(string $currenttrigger, string $_unused): bool {
        $firstchar = $currenttrigger[0] ?? '';
        if (in_array($firstchar, self::PCRE_DELIMITERS, true)) {
            // 正则模式：检查定界符是否闭合，空字符串时访问 [-1] 会产生 Warning
            $len = strlen($currenttrigger);
            if ($len === 0) {
                return true;
            }
            return $currenttrigger[$len - 1] === $firstchar;
        }
        // 关键词模式：遇到新 key 时结束
        return true;
    }

    /**
     * 检查拦截规则
     *
     * 解析配置中的拦截规则，如果用户消息匹配某条规则的触发条件，
     * 则直接返回预设回复，跳过 AI 调用。
     *
     * @param string $message 用户输入的消息
     * @return string|null 匹配时返回预设回复，不匹配返回 null
     */
    public static function check_interception_rules($message): ?string {
        // 强制类型转换：非字符串类型调用方传入时，避免后续 strlen/stripos 产生 PHP Warning
        $message = (string)$message;
        $rules = self::get_parsed_interception_rules();
        if (empty($rules)) {
            return null;
        }

        if (self::$cachedebug === null) {
            self::$cachedebug = (bool)self::get_config_value('debug');
        }
        $debug = self::$cachedebug;

        if ($debug) {
            debugging('[ai_programming_assistant] interception check: message_len=' . strlen($message)
                . ' rules_count=' . count($rules), DEBUG_DEVELOPER);
        }

        foreach ($rules as $idx => $rule) {
            $matched = false;

            if ($rule['mode'] === 'regex') {
                if (isset($rule['pattern']) && $rule['pattern'] !== null) {
                    if (self::safe_preg_match($rule['pattern'], $message)) {
                        $matched = true;
                    }
                }
            } else {
                $triggers = $rule['triggers'] ?? [];
                foreach ($triggers as $kw) {
                    $kw = trim($kw);
                    if (empty($kw)) {
                        continue;
                    }
                    if (stripos($message, $kw) !== false) {
                        $matched = true;
                        break;
                    }
                }
            }

            if ($matched) {
                if ($debug) {
                    $triggerlabel = $rule['trigger'] ?? implode('|', $rule['triggers'] ?? []);
                    debugging('[ai_programming_assistant] interception match: rule=' . $idx
                        . ' trigger="' . $triggerlabel . '" mode=' . $rule['mode'], DEBUG_DEVELOPER);
                }
                return $rule['response'];
            }
        }

        if ($debug) {
            debugging('[ai_programming_assistant] interception no match: message_preview="'
                . mb_substr($message, 0, 100, 'UTF-8') . '"', DEBUG_DEVELOPER);
        }

        return null;
    }

    /**
     * 统一的内容过滤入口（消除 stream_message.php 与 completion.php 之间的重复）
     *
     * 组合执行：拦截规则检查 → 输入净化过滤。
     * 引用传递 $message，过滤后内容直接回写。
     *
     * @param string $message 用户输入消息（引用传递，过滤后内容回写至此变量）
     * @return string|null 被拦截时返回拦截响应内容，未拦截返回 null
     */
    public static function filter_content(string &$message): ?string {
        $interception = self::check_interception_rules($message);
        if ($interception !== null) {
            return self::filter_ai_response($interception);
        }

        $processed = self::filter_user_message($message);
        if ($processed['filtered']) {
            return $processed['reason'];
        }

        $message = $processed['message'];
        return null;
    }

    /**
     * 获取学生源代码中可能的敏感信息的净化版本
     *
     * @param string $sourcecode 学生源代码
     * @return string 净化后的源代码
     */
    public static function sanitize_source_code(string $sourcecode): string {
        return self::apply_replacement_rules('inputsanitize', $sourcecode);
    }

    /** @var string 正则：匹配 prompt_builder 中用作数据包装的 XML 标签（开/闭）
     *               \\\\? 匹配可选的反斜杠（用于处理 json_encode 将 / 转义为 \/ 的情况） */
    private const PROMPT_MARKUP_TAGS_REGEX =
        '#<\\\\?/?(?:student_input|student_code|conversation_history|test_results)>#';

    /**
     * 转义学生提交内容中出现的提示词标记标签
     *
     * prompt_builder 将学生数据包装在 <student_code>、<conversation_history>、
     * <test_results>、<student_input> 等 XML 标签中，并在系统提示词中告知 AI
     * 这些标签内的文本仅为数据、不作为指令执行。
     *
     * 若学生在消息/代码中写入 </student_code> 等闭合标签，可"逃逸"出数据包装，
     * 使后续注入内容被 AI 误认为标签外的指令（prompt injection 的标签逃逸变体）。
     *
     * 本方法仅转义与已知标记标签名精确匹配的 <...> 序列（转换为 HTML 实体），
     * 不影响代码中合法的 < > 字符（如比较运算、模板语法）。
     *
     * 正则同时匹配 JSON 转义的 \/ 形式（json_encode 将 / 转义为 \/），
     * 确保 format_test_results_from_json() 回退到 json_encode 输出时，
     * <\/test_results> 等序列也能被正确转义。
     *
     * @param string $content 学生提交的原始内容
     * @return string 转义后的内容
     */
    public static function escape_prompt_markup_tags(string $content): string {
        if ($content === '') {
            return '';
        }
        return preg_replace_callback(
            self::PROMPT_MARKUP_TAGS_REGEX,
            static fn(array $m): string => str_replace(['<', '>'], ['&lt;', '&gt;'], $m[0]),
            $content
        );
    }

    /**
     * 清理题目文本，移除 HTML 标签并解码实体
     *
     * Moodle 的题目文本通常包含 HTML 格式（如 <p>、<strong> 等），
     * 直接发送给 AI 会增加 token 消耗且不利于理解。
     * 参考 coderunner_ai get_question_description() 的实现。
     *
     * 关键优化：保留换行符以维持编程题目的格式结构，
     * 只压缩水平空白（空格、制表符），并将多个空行折叠为一个。
     *
     * @param string $text 原始题目文本（可能包含 HTML）
     * @return string 清理后的纯文本
     */
    public static function clean_question_text(string $text): string {
        if (empty($text)) {
            return '';
        }
        // 第一步：规范化 UTF-8 编码，移除无效字节序列（防止 html_entity_decode/strip_tags 行为异常）
        // 使用 mb_convert_encoding 的自转换技巧：无效字节会被替换或丢弃
        if (function_exists('mb_convert_encoding')) {
            $oldsub = @ini_set('mbstring.substitute_character', 'none');
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            if ($oldsub !== false) {
                @ini_set('mbstring.substitute_character', $oldsub);
            }
        }
        // 解码 HTML 实体（如 &amp; → &）。
        // 注意：不能对纯文本调用 strip_tags()。questiontext 来自前端 innerText（已是纯文本），
        // 编程题文本中的 i<n、List<T>、while(i<n) 等字面 < 会被 strip_tags 误判为未闭合标签，
        // 导致 < 之后的全部内容被删除（实测 '判断 i<n 时循环' → '判断 i'）。
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // 将 Windows/旧 Mac 换行符统一为 Unix 格式
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // 保留每行行首空白（代码缩进），仅压缩其他连续水平空白为单个空格。
        // 编程题题目内容可能包含代码框架（如填空题型），缩进是代码语义的一部分，不能去掉。
        $lines = explode("\n", $text);
        foreach ($lines as $index => $line) {
            if (preg_match('/^([ \t]+)(.*)$/', $line, $matches)) {
                $lines[$index] = $matches[1] . preg_replace('/[ \t]+/', ' ', $matches[2]);
            } else {
                $lines[$index] = preg_replace('/[ \t]+/', ' ', $line);
            }
        }
        $text = implode("\n", $lines);
        // 将连续多个空行折叠为一个空行（保留段落结构）
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    /**
     * 截断超长源代码，减少 token 消耗
     *
     * 当代码超过指定长度时，截断并添加提示信息。
     * 参考 coderunner_ai prompt_builder 的代码截断策略。
     *
     * @param string $code     原始源代码
     * @param int    $maxlen   最大字符数（默认 5000）
     * @return string 截断后的代码
     */
    public static function truncate_code(string $code, int $maxlen = 5000): string {
        // 显式 UTF-8 编码参数：防止依赖 php.ini mbstring.internal_encoding 配置导致截断字符错位
        $codelen = mb_strlen($code, 'UTF-8');
        if ($codelen <= $maxlen) {
            return $code;
        }
        return mb_substr($code, 0, $maxlen, 'UTF-8')
            . "\n\n" . get_string('code_truncated', 'local_ai_programming_assistant', ['maxlen' => $maxlen, 'codelen' => $codelen]);
    }

    /**
     * 格式化测试结果为可读文本
     *
     * 将测试结果数组结构化为清晰的 Markdown 格式，便于 AI 理解。
     * 参考 coderunner_ai prompt_builder::format_test_results() 的实现。
     *
     * @param array $testresults 解析后的测试结果数组
     * @return string 格式化后的文本（无法识别格式时回退到 JSON 输出）
     */
    public static function format_test_results(array $testresults): string {
        if (empty($testresults)) {
            return '';
        }

        // 处理原始错误输出
        if (isset($testresults['error_raw'])) {
            return get_string('testresult_error_raw', 'local_ai_programming_assistant') . "\n" . $testresults['error_raw'];
        }

        $output = '';

        if (isset($testresults['outcome'])) {
            $output .= get_string('testresult_outcome', 'local_ai_programming_assistant', $testresults['outcome']) . "\n";
        }
        if (isset($testresults['isPrecheck']) && $testresults['isPrecheck']) {
            $output .= get_string('testresult_precheck', 'local_ai_programming_assistant') . "\n";
        }
        if (isset($testresults['errorMessage'])) {
            $output .= get_string('testresult_error_msg', 'local_ai_programming_assistant', $testresults['errorMessage']) . "\n";
            return $output;
        }
        if (isset($testresults['summary'])) {
            $output .= get_string('testresult_summary', 'local_ai_programming_assistant', $testresults['summary']) . "\n";
        }

        if (isset($testresults['rows']) && is_array($testresults['rows'])) {
            $output .= "\n" . get_string('testresult_cases', 'local_ai_programming_assistant') . "\n";
            $headers = $testresults['headers'] ?? [];
            if (!empty($headers)) {
                $output .= '| ' . implode(' | ', $headers) . " |\n";
                $output .= '|' . str_repeat('---|', count($headers)) . "\n";
            }
            foreach ($testresults['rows'] as $row) {
                $output .= '| ' . implode(' | ', array_map(function ($cell) {
                    return str_replace(["\n", '|'], [' ', '\|'], (string) $cell);
                }, $row)) . " |\n";
            }
        }

        if (isset($testresults['isCorrect']) && is_array($testresults['isCorrect'])) {
            $correct = $testresults['isCorrect'];
            $passed = count(array_filter($correct));
            $total = count($correct);
            $output .= "\n" . get_string('testresult_passed', 'local_ai_programming_assistant',
                (object)['passed' => $passed, 'total' => $total]) . "\n";
        }

        // 优化：未能识别任何已知字段时，回退到 JSON 输出，避免数据静默丢失
        if ($output === '' && !empty($testresults)) {
            $encoded = json_encode($testresults, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($encoded !== false) {
                return get_string('testresult_unrecognized', 'local_ai_programming_assistant') . "\n{$encoded}";
            }
        }

        return $output;
    }

    /**
     * 解析测试结果 JSON 并格式化为可读文本
     *
     * 便捷方法：直接传入 JSON 字符串，内部解析后调用 format_test_results()。
     * 如果 format_test_results() 未匹配到已知结构，回退到原始 JSON 字符串输出。
     * 避免调用方每次手动 json_decode。
     *
     * @param string $testresultsjson 测试结果 JSON 字符串
     * @return string 格式化后的文本
     */
    public static function format_test_results_from_json(string $testresultsjson): string {
        if (trim($testresultsjson) === '') {
            return '';
        }
        $decoded = json_decode($testresultsjson, true);
        if (!is_array($decoded)) {
            // 非 JSON 格式，作为原始文本返回
            return $testresultsjson;
        }
        $formatted = self::format_test_results($decoded);
        // 优化：未能识别任何已知结构时回退到原始 JSON，避免数据静默丢失
        if (empty($formatted)) {
            return $testresultsjson;
        }
        return $formatted;
    }

    /**
     * 净化对话历史消息数组
     *
     * 对对话历史中的每条消息内容进行过滤，确保不包含敏感信息。
     * 使用 prompt_builder::normalize_message() 统一处理多种输入格式，
     * 确保仅处理规范的 ['role', 'content'] 格式。
     *
     * @param array $messages 消息数组（支持多种输入格式，内部统一规范化）
     * @return array 净化后的消息数组（仅含 ['role', 'content'] 规范格式）
     */
    public static function sanitize_conversation_history(array $messages): array {
        $sanitized = [];
        foreach ($messages as $msg) {
            if (!is_array($msg)) {
                continue;
            }

            // 使用统一规范化方法处理多种输入格式
            $normalized = prompt_builder::normalize_message($msg);
            $role = $normalized['role'];
            $content = $normalized['content'];

            // 保留原始元数据：已在 get_filtered_history() 中完成净化的消息跳过重复执行
            $was_sanitized = !empty($msg['sanitized']);

            // 对用户消息执行输入净化
            if ($role === 'user' && !$was_sanitized) {
                $content = self::apply_replacement_rules('inputsanitize', $content);
            }

            $sanitized[] = [
                'role' => $role,
                'content' => $content,
            ];
        }
        return $sanitized;
    }

    /**
     * 高效提取字符串的前 N 行
     *
     * 使用 strpos 定位换行符位置，避免对大文本全量 explode。
     * 如果文本不足 N 行则原样返回（已去除末尾换行）。
     * 参考 coderunner_ai prompt_builder::extract_first_lines() 的实现。
     *
     * @param string $content  输入文本
     * @param int    $maxlines 最多提取的行数（默认 3）
     * @return string 提取的前 N 行（每行前缩进 2 空格），超过 N 行时末尾追加 ' …'
     */
    public static function extract_first_lines(string $content, int $maxlines = 3): string {
        if ($content === '') {
            return '';
        }
        $result = '';
        $pos = 0;
        $linecount = 0;
        $len = strlen($content);
        $maxlines = max(1, $maxlines);

        while ($pos < $len && $linecount < $maxlines) {
            $next = strpos($content, "\n", $pos);
            if ($next === false) {
                $next = $len;
            }
            $linelen = $next - $pos;
            if ($linelen > 0) {
                // $pos/$linelen 均为字节偏移（strlen/strpos 语义），必须用 substr 而非 mb_substr。
                // 否则多字节文本中字节位置会被误当作字符位置，导致行内容错位/丢失。
                $result .= '  ' . substr($content, $pos, $linelen) . "\n";
            }
            $linecount++;
            $pos = $next + 1;
        }

        if ($pos >= $len) {
            return rtrim($result, "\n\r");
        }

        return rtrim($result, "\n\r") . ' …';
    }
}