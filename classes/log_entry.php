<?php
/**
 * AI 编程助手 - 日志记录数据传输对象
 *
 * 将 manager 在阻塞与流式两条路径中收集的日志字段封装为值对象，
 * 交由 feedback_repository::try_log() 写入 conversation + message 表，
 * 消除方法签名膨胀，提高可读性和可维护性。
 *
 * @package    local_ai_programming_assistant
 * @author     zfl
 */

namespace local_ai_programming_assistant;

defined('MOODLE_INTERNAL') || die();

class log_entry {

    /** @var int 用户ID */
    public int $userid = 0;

    /** @var string 用户消息 */
    public string $usermessage = '';

    /** @var string AI 回复内容 */
    public string $airesponse = '';

    /** @var int 试题ID */
    public int $questionid = 0;

    /** @var string 操作类型 */
    public string $actiontype = 'free';

    /** @var string AI 后端类型 */
    public string $ai_backend = '';

    /** @var string AI 模型名称 */
    public string $model = '';

    /** @var int 测验实例ID */
    public int $quizid = 0;

    /** @var bool 是否成功 */
    public bool $success = true;

    /** @var string 错误消息 */
    public string $errormessage = '';

    /** @var int API响应时间（毫秒） */
    public int $responsetime = 0;

    /** @var int 试题用法ID */
    public int $usageid = 0;

    /** @var int 试题插槽号 */
    public int $slot = 0;

    /** @var string 学生答案 */
    public string $studentanswer = '';

    /** @var int 上下文ID */
    public int $contextid = 0;

    /** @var int 课程ID */
    public int $courseid = 0;

    /** @var int 课程模块ID */
    public int $cmid = 0;

    /** @var string 页面类型 */
    public string $pagetype = '';

    /** @var int 对话会话 ID */
    public int $conversationid = 0;

    /** @var array<string, string> 属性类型映射 */
    private const TYPE_MAP = [
        'userid' => 'int', 'questionid' => 'int', 'quizid' => 'int',
        'usageid' => 'int', 'slot' => 'int',
        'responsetime' => 'int', 'contextid' => 'int', 'courseid' => 'int',
        'cmid' => 'int', 'conversationid' => 'int',
        'success' => 'bool',
    ];

    /**
     * 从通用数据数组创建 log_entry 实例 — 工厂方法
     *
     * 消除 manager::build_log_context() / build_stream_*_handler() 中重复的字段赋值代码。
     * 接受一个关联数组，键名为 log_entry 的属性名。
     *
     * @param array $data 数据数组
     * @return self
     */
    public static function from_array(array $data): self {
        $entry = new self();
        foreach ($data as $key => $value) {
            if (property_exists($entry, $key)) {
                if (isset(self::TYPE_MAP[$key])) {
                    settype($value, self::TYPE_MAP[$key]);
                } else if ($value === null) {
                    // 非类型化 string 属性赋 null 在 PHP 8.1+ 会抛 TypeError
                    $value = '';
                }
                $entry->{$key} = $value;
            }
        }
        return $entry;
    }
}