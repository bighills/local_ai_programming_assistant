<?php
/**
 * AI 编程助手 - 统计服务层
 *
 * 作为 stats_repository 的封装层，提供业务逻辑处理。
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */

namespace local_ai_programming_assistant;

defined('MOODLE_INTERNAL') || die();

class stats_service {

    /** @var stats_repository */
    private stats_repository $repo;

    /** @var self|null 请求级单例 */
    private static ?self $instance = null;

    /**
     * 获取请求级单例实例
     */
    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 重置单例（用于 PHP-FPM 常驻进程或单元测试）
     */
    public static function reset(): void {
        self::$instance = null;
    }

    public function __construct() {
        $this->repo = new stats_repository();
    }

    public function get_overall_stats(?int $starttime = null, ?int $endtime = null, ?int $quizid = null, ?int $courseid = null): array {
        return $this->repo->get_statistics($starttime, $endtime, $quizid, $courseid);
    }

    /**
     * 获取总揽统计（合并试题模式 + 自由模式）
     */
    public function get_overview_stats(?int $starttime = null, ?int $endtime = null, ?int $courseid = null): array {
        return $this->repo->get_overview_statistics($starttime, $endtime, $courseid);
    }

    /**
     * 获取每日趋势统计（合并试题模式 + 自由模式）
     */
    public function get_daily_stats(int $starttime, int $endtime, ?int $courseid = null): array {
        return $this->repo->get_daily_stats($starttime, $endtime, $courseid);
    }

    public function get_top_questions(int $limit = 10, ?int $starttime = null, ?int $endtime = null,
        int $offset = 0, string $sort = 'total_requests', string $order = 'DESC',
        ?int $courseid = null): array {
        return $this->repo->get_top_questions($limit, $starttime, $endtime, $offset, $sort, $order, $courseid);
    }

    public function count_top_questions(?int $starttime = null, ?int $endtime = null, ?int $courseid = null): int {
        return $this->repo->count_top_questions($starttime, $endtime, $courseid);
    }

    public function get_student_statistics(int $limit = 20, ?int $starttime = null, ?int $endtime = null,
        string $search = '', int $offset = 0, string $sort = 'total_requests', string $order = 'DESC',
        ?int $courseid = null): array {
        return $this->repo->get_student_statistics($limit, $starttime, $endtime, $search, $offset, $sort, $order, $courseid);
    }

    public function count_student_statistics(?int $starttime = null, ?int $endtime = null, string $search = '', ?int $courseid = null): int {
        return $this->repo->count_student_statistics($starttime, $endtime, $search, $courseid);
    }

    public function get_quiz_question_stats(int $quizid, ?int $starttime = null, ?int $endtime = null,
        int $limit = 5000, ?array $userids = null, int $offset = 0,
        string $sort = 'questionid', string $order = 'ASC'): array {
        return $this->repo->get_quiz_question_stats($quizid, $starttime, $endtime, $limit, $userids, $offset, $sort, $order);
    }

    public function count_quiz_question_stats(int $quizid): int {
        return $this->repo->count_quiz_question_stats($quizid);
    }

    public function get_quiz_student_stats(int $quizid, ?int $starttime = null, ?int $endtime = null,
        int $limit = 5000, ?array $userids = null, string $search = '',
        int $offset = 0, string $sort = 'total_requests', string $order = 'DESC'): array {
        return $this->repo->get_quiz_student_stats($quizid, $starttime, $endtime, $limit, $userids, $search, $offset, $sort, $order);
    }

    public function count_quiz_student_stats(int $quizid, ?int $starttime = null, ?int $endtime = null, ?array $userids = null, string $search = ''): int {
        return $this->repo->count_quiz_student_stats($quizid, $starttime, $endtime, $userids, $search);
    }

    public function get_quizzes_with_usage(): array {
        return $this->repo->get_quizzes_with_usage();
    }

    public function get_quiz_info(int $quizid): ?\stdClass {
        return $this->repo->get_quiz_info($quizid);
    }

    public function get_question_info(int $questionid): ?\stdClass {
        return $this->repo->get_question_info($questionid);
    }

    public function get_quiz_question_followups(int $quizid, ?int $starttime = null, ?int $endtime = null, int $limit = 10, int $offset = 0, ?array $userids = null): array {
        return $this->repo->get_quiz_question_followups($quizid, $starttime, $endtime, $limit, $offset, $userids);
    }

    public function count_quiz_question_followups(int $quizid, ?int $starttime = null, ?int $endtime = null, ?array $userids = null): int {
        return $this->repo->count_quiz_question_followups($quizid, $starttime, $endtime, $userids);
    }

    public function get_quiz_student_followups(int $quizid, ?int $starttime = null, ?int $endtime = null, int $limit = 10, int $offset = 0, ?array $userids = null): array {
        return $this->repo->get_quiz_student_followups($quizid, $starttime, $endtime, $limit, $offset, $userids);
    }

    public function count_quiz_student_followups(int $quizid, ?int $starttime = null, ?int $endtime = null, ?array $userids = null): int {
        return $this->repo->count_quiz_student_followups($quizid, $starttime, $endtime, $userids);
    }

    public function get_quiz_conversations(int $quizid, ?int $starttime = null, ?int $endtime = null,
        ?int $questionid = null, ?int $userid = null, ?string $actiontype = null,
        ?array $userids = null, int $limit = 20, int $offset = 0): array {
        return $this->repo->get_quiz_conversations($quizid, $starttime, $endtime, $questionid, $userid, $actiontype, $userids, $limit, $offset);
    }

    public function count_quiz_conversations(int $quizid, ?int $starttime = null, ?int $endtime = null,
        ?int $questionid = null, ?int $userid = null, ?string $actiontype = null,
        ?array $userids = null): int {
        return $this->repo->count_quiz_conversations($quizid, $starttime, $endtime, $questionid, $userid, $actiontype, $userids);
    }

    public function get_question_student_usage(int $quizid, int $questionid, ?int $starttime = null, ?int $endtime = null, int $limit = 5000, ?array $userids = null, int $offset = 0): array {
        return $this->repo->get_question_student_usage($quizid, $questionid, $starttime, $endtime, $limit, $userids, $offset);
    }

    public function count_question_students(int $quizid, int $questionid, ?int $starttime = null, ?int $endtime = null, ?array $userids = null): int {
        return $this->repo->count_question_students($quizid, $questionid, $starttime, $endtime, $userids);
    }

    public function get_question_followups(int $quizid, int $questionid, ?int $starttime = null, ?int $endtime = null, int $limit = 5000, ?array $userids = null, int $offset = 0, ?string $actiontype = null): array {
        return $this->repo->get_question_followups($quizid, $questionid, $starttime, $endtime, $limit, $userids, $offset, $actiontype);
    }

    public function count_question_followups(int $quizid, int $questionid, ?int $starttime = null, ?int $endtime = null, ?array $userids = null, ?string $actiontype = null): int {
        return $this->repo->count_question_followups($quizid, $questionid, $starttime, $endtime, $userids, $actiontype);
    }

    public function get_question_student_feedback_details(int $quizid, int $questionid, int $userid, ?int $starttime = null, ?int $endtime = null, int $limit = 5000, string $actiontype = ''): array {
        return $this->repo->get_question_student_feedback_details($quizid, $questionid, $userid, $starttime, $endtime, $limit, $actiontype);
    }

    // ===== 自由模式（无题号）统计 =====

    public function get_free_log_overall_stats(?int $starttime = null, ?int $endtime = null): array {
        return $this->repo->get_free_log_statistics($starttime, $endtime);
    }

    public function get_free_log_student_stats(?int $starttime = null, ?int $endtime = null,
        int $limit = 20, string $search = '', int $offset = 0, string $sort = 'total_requests',
        string $order = 'DESC'): array {
        return $this->repo->get_free_log_student_stats($starttime, $endtime, $limit, $search, $offset, $sort, $order);
    }

    public function count_free_log_student_stats(?int $starttime = null, ?int $endtime = null, string $search = ''): int {
        return $this->repo->count_free_log_student_stats($starttime, $endtime, $search);
    }

    public function get_free_log_daily_stats(int $starttime, int $endtime): array {
        return $this->repo->get_free_log_daily_stats($starttime, $endtime);
    }

    public function get_free_log_chat_detail(int $userid, ?int $starttime = null, ?int $endtime = null, int $limit = 100, int $offset = 0, ?int $contextid = null): array {
        return $this->repo->get_free_log_chat_detail($userid, $starttime, $endtime, $limit, $offset, $contextid);
    }

    public function count_free_log_chat(int $userid, ?int $starttime = null, ?int $endtime = null, ?int $contextid = null): int {
        return $this->repo->count_free_log_chat($userid, $starttime, $endtime, $contextid);
    }

    /**
     * 获取所有学生按页面+用户分组的最近自由对话记录
     */
    public function get_recent_free_chats_grouped(?int $starttime = null, ?int $endtime = null,
        int $limit = 100, int $offset = 0, string $search = ''): array {
        return $this->repo->get_recent_free_chats_grouped($starttime, $endtime, $limit, $offset, $search);
    }

    /**
     * 统计所有学生按页面+用户分组的最近自由对话记录组数
     */
    public function count_recent_free_chats_grouped(?int $starttime = null, ?int $endtime = null,
        string $search = ''): int {
        return $this->repo->count_recent_free_chats_grouped($starttime, $endtime, $search);
    }

    /**
     * 获取所有学生按试题+用户分组的最近 AI 使用对话记录
     */
    public function get_recent_quiz_chats_grouped(?int $starttime = null, ?int $endtime = null,
        int $limit = 100, int $offset = 0, string $search = '',
        ?int $courseid = null, ?int $quizid = null): array {
        return $this->repo->get_recent_quiz_chats_grouped($starttime, $endtime, $limit, $offset, $search, $courseid, $quizid);
    }

    /**
     * 统计所有学生按试题+用户分组的最近 AI 使用对话记录组数
     */
    public function count_recent_quiz_chats_grouped(?int $starttime = null, ?int $endtime = null,
        string $search = '', ?int $courseid = null, ?int $quizid = null): int {
        return $this->repo->count_recent_quiz_chats_grouped($starttime, $endtime, $search, $courseid, $quizid);
    }

    // ===== 学生题目列表（中间页） =====

    public function get_student_questions(int $userid, ?int $starttime = null, ?int $endtime = null,
        ?int $quizid = null, int $limit = 10, int $offset = 0): array {
        return $this->repo->get_student_questions($userid, $starttime, $endtime, $quizid, $limit, $offset);
    }

    public function count_student_questions(int $userid, ?int $starttime = null, ?int $endtime = null, ?int $quizid = null): int {
        return $this->repo->count_student_questions($userid, $starttime, $endtime, $quizid);
    }

    // ===== 学生提问记录（学生向 AI 提问的原始问题列表） =====

    /**
     * 获取学生提问记录列表
     *
     * @param int|null $questionid 题目 ID（>0 时仅统计该题）
     * @param int|null $userid 学生 ID（>0 时仅统计该学生）
     * @param string|null $actiontype 策略模式（question_analysis/idea_organization/code_debugging/free）
     * @param int|null $quizid 测验 ID（>0 时仅统计该测验）
     * @param int|null $courseid 课程 ID（>0 时仅统计该课程）
     * @param int|null $starttime 起始时间戳（按消息时间过滤）
     * @param int|null $endtime 结束时间戳
     * @param string $search 学生姓名/用户名搜索关键字
     * @param int $limit 每页条数
     * @param int $offset 偏移量
     * @return array 提问记录数组
     */
    public function get_asked_questions(?int $questionid = null, ?int $userid = null, ?string $actiontype = null,
        ?int $quizid = null, ?int $courseid = null, ?int $starttime = null, ?int $endtime = null,
        string $search = '', int $limit = 20, int $offset = 0): array {
        return $this->repo->get_asked_questions($questionid, $userid, $actiontype, $quizid, $courseid,
            $starttime, $endtime, $search, $limit, $offset);
    }

    /**
     * 统计学生提问记录总数
     *
     * @param int|null $questionid 题目 ID（>0 时仅统计该题）
     * @param int|null $userid 学生 ID（>0 时仅统计该学生）
     * @param string|null $actiontype 策略模式
     * @param int|null $quizid 测验 ID（>0 时仅统计该测验）
     * @param int|null $courseid 课程 ID（>0 时仅统计该课程）
     * @param int|null $starttime 起始时间戳（按消息时间过滤）
     * @param int|null $endtime 结束时间戳
     * @param string $search 学生姓名/用户名搜索关键字
     * @return int 提问记录总数
     */
    public function count_asked_questions(?int $questionid = null, ?int $userid = null, ?string $actiontype = null,
        ?int $quizid = null, ?int $courseid = null, ?int $starttime = null, ?int $endtime = null,
        string $search = ''): int {
        return $this->repo->count_asked_questions($questionid, $userid, $actiontype, $quizid, $courseid,
            $starttime, $endtime, $search);
    }

    // ===== 自由模式学生页面列表（中间页） =====

    public function get_student_free_pages(int $userid, ?int $starttime = null, ?int $endtime = null,
        int $limit = 10, int $offset = 0): array {
        return $this->repo->get_student_free_pages($userid, $starttime, $endtime, $limit, $offset);
    }

    public function count_student_free_pages(int $userid, ?int $starttime = null, ?int $endtime = null): int {
        return $this->repo->count_student_free_pages($userid, $starttime, $endtime);
    }

    public function get_student_free_pages_total_interactions(int $userid, ?int $starttime = null, ?int $endtime = null): int {
        return $this->repo->get_student_free_pages_total_interactions($userid, $starttime, $endtime);
    }

    // ===== 有题号主日志聊天详情 =====

    public function get_chat_detail(int $userid, ?int $starttime = null, ?int $endtime = null, ?int $quizid = null, int $limit = 100, int $offset = 0): array {
        return $this->repo->get_chat_detail($userid, $starttime, $endtime, $quizid, $limit, $offset);
    }

    public function count_chat(int $userid, ?int $starttime = null, ?int $endtime = null, ?int $quizid = null): int {
        return $this->repo->count_chat($userid, $starttime, $endtime, $quizid);
    }

    // ===== 会话列表（一级页面） =====

    public function get_conversations_list(?int $starttime = null, ?int $endtime = null,
        int $limit = 20, int $offset = 0): array {
        return $this->repo->get_conversations_list($starttime, $endtime, $limit, $offset);
    }

    public function count_conversations(?int $starttime = null, ?int $endtime = null): int {
        return $this->repo->count_conversations($starttime, $endtime);
    }

    public function get_conversation(int $conversationid): ?array {
        return $this->repo->get_conversation($conversationid);
    }

    public function get_conversation_messages(int $conversationid): array {
        return $this->repo->get_conversation_messages($conversationid);
    }

    public function get_filtered_conversations(?int $questionid = null, ?int $userid = null, ?int $quizid = null,
        ?string $actiontype = null, ?int $contextid = null, ?int $starttime = null, ?int $endtime = null, int $limit = 20, int $offset = 0, string $search = '', string $order = 'DESC', ?int $courseid = null): array {
        return $this->repo->get_filtered_conversations($questionid, $userid, $quizid, $actiontype, $contextid, $starttime, $endtime, $limit, $offset, $search, $order, $courseid);
    }

    public function count_filtered_conversations(?int $questionid = null, ?int $userid = null, ?int $quizid = null,
        ?string $actiontype = null, ?int $contextid = null, ?int $starttime = null, ?int $endtime = null, string $search = '', ?int $courseid = null): int {
        return $this->repo->count_filtered_conversations($questionid, $userid, $quizid, $actiontype, $contextid, $starttime, $endtime, $search, $courseid);
    }

    /**
     * 批量删除会话及其消息
     *
     * @param array $conversationids 会话 ID 数组
     * @return int 实际删除的会话数
     */
    public function delete_conversations(array $conversationids): int {
        return $this->repo->delete_conversations($conversationids);
    }

    /**
     * 按题目 ID 批量删除会话及其消息
     *
     * @param array $questionids 题目 ID 数组
     * @param int|null $quizid 仅删除指定测验下的会话
     * @param int|null $courseid 仅删除指定课程下的会话
     * @param int|null $starttime 仅删除该时间戳之后的会话
     * @param int|null $endtime 仅删除该时间戳之前的会话
     * @return int 删除的会话数
     */
    public function delete_conversations_by_questionids(array $questionids, ?int $quizid = null,
        ?int $courseid = null, ?int $starttime = null, ?int $endtime = null): int {
        return $this->repo->delete_conversations_by_questionids($questionids, $quizid, $courseid, $starttime, $endtime);
    }

    /**
     * 按用户 ID 批量删除会话及其消息
     *
     * @param array $userids 用户 ID 数组
     * @param bool $freeonly 是否仅删除自由模式（questionid = 0）的会话
     * @param int|null $quizid 仅删除指定测验下的会话
     * @param int|null $courseid 仅删除指定课程下的会话
     * @param int|null $starttime 仅删除该时间戳之后的会话
     * @param int|null $endtime 仅删除该时间戳之前的会话
     * @return int 删除的会话数
     */
    public function delete_conversations_by_userids(array $userids, bool $freeonly = false, ?int $quizid = null,
        ?int $courseid = null, ?int $starttime = null, ?int $endtime = null): int {
        return $this->repo->delete_conversations_by_userids($userids, $freeonly, $quizid, $courseid, $starttime, $endtime);
    }
}