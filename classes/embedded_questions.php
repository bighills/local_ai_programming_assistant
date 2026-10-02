<?php
/**
 * AI 编程助手 - 嵌入题提取器
 *
 * 从 mod/page 页面内容中提取 tiny_embedquestion（filter_embedquestion）嵌入的
 * 测验题（{Q{...}Q} 语法），解析出题库中题目的实际内容，
 * 作为 AI 的题目上下文（questiontext）使用。
 *
 * 背景：mod/page 等页面没有 .que DOM 结构，前端无法提取题目文本；
 * 页面中的嵌入题在渲染时被过滤器替换成 iframe，学生只能看到渲染结果。
 * 本类从数据库读取页面原始内容，复用 filter_embedquestion 的解析逻辑，
 * 还原出嵌入题的题目文本供 AI 参考。
 *
 * @author zfl
 * @package    local_ai_programming_assistant
 */

namespace local_ai_programming_assistant;

defined('MOODLE_INTERNAL') || die();

class embedded_questions {

    /** @var int 单页最多提取的嵌入题数量（防止提示词过长） */
    private const MAX_QUESTIONS = 10;

    /** @var int 单题题目文本最大长度（字符） */
    private const MAX_QUESTION_TEXT = 4000;

    /** @var int 提取的嵌入题总文本最大长度（字符） */
    private const MAX_TOTAL_TEXT = 8000;

    /**
     * 从页面模块上下文中提取嵌入题文本（全部题目合并为一个上下文块）
     *
     * @param \context $context 当前页面上下文（模块级）
     * @return string 嵌入题文本块；无嵌入题或无法解析时返回空字符串
     */
    public static function extract_questiontext(\context $context): string {
        $questions = self::get_embedded_questions($context);
        if (empty($questions)) {
            return '';
        }

        $blocks = [];
        foreach (array_values($questions) as $index => $question) {
            $header = get_string('embedded_question_header', 'local_ai_programming_assistant', [
                'number' => $index + 1,
                'name' => $question['name'],
                'qtype' => $question['qtypename'],
            ]);
            if ($question['questiontext'] === '') {
                $blocks[] = $header;
            } else {
                $blocks[] = $header . "\n" . $question['questiontext'];
            }
        }

        $intro = get_string('embedded_questions_intro', 'local_ai_programming_assistant', count($blocks));
        return $intro . "\n\n" . implode("\n\n", $blocks);
    }

    /**
     * 从 mod/page 页面内容中解析嵌入题列表（供前端题目选择器使用）
     *
     * 按页面中出现顺序返回，同一题目嵌入多次时只保留一份；
     * 数量与总文本长度受 MAX_QUESTIONS / MAX_TOTAL_TEXT 限制。
     *
     * @param \context $context 当前页面上下文（模块级）
     * @return array[] 嵌入题列表，每个元素包含：
     *                 questionid（题库题目 ID）、name（题名）、
     *                 qtype（题型标识）、qtypename（题型名称）、
     *                 questiontext（已转为纯文本的题目内容）
     */
    public static function get_embedded_questions(\context $context): array {
        // filter_embedquestion 未安装时页面中不会出现 {Q{...}Q} 语法，直接跳过。
        if (!class_exists('\filter_embedquestion\embed_id') || !class_exists('\filter_embedquestion\utils')) {
            return [];
        }

        // 仅支持 mod/page 页面内容中的嵌入题。
        $page = self::get_visible_page_record($context);
        if ($page === null) {
            return [];
        }

        // 匹配规则与 filter_embedquestion\text_filter::get_filter_regexp() 保持一致，
        // 确保只提取过滤器真实会渲染成题目的语法（. 默认不匹配换行，跨行 token 不提取）。
        if (!preg_match_all('~\{Q\{((?:(?!\}Q\}).)*)\}Q\}~', $page->content, $matches)) {
            return [];
        }

        $questions = [];
        $seenquestionids = [];
        $totallength = 0;

        foreach ($matches[1] as $embedcode) {
            if (count($questions) >= self::MAX_QUESTIONS) {
                break;
            }

            $resolved = self::resolve_embedded_question($embedcode, $context);
            if ($resolved === null) {
                continue;
            }
            $question = $resolved['question'];

            // 同一题目在一页嵌入多次时只保留一份。
            if (isset($seenquestionids[$question->id])) {
                continue;
            }
            $seenquestionids[$question->id] = true;

            // 读取当前用户在该嵌入题上的最近作答（代码、运行反馈、测试结果、编程语言）。
            // 嵌入题没有 .que DOM，前端无法提取，只能由后端从答题会话中还原。
            $attemptdata = self::get_attempt_data($resolved['embedid'], $context, $question);

            $entry = [
                'questionid' => (int)$question->id,
                'name' => trim((string)$question->name),
                'qtype' => (string)$question->qtype,
                'qtypename' => self::get_qtype_name($question->qtype),
                'questiontext' => self::questiontext_to_text(
                    (string)$question->questiontext,
                    (int)$question->questiontextformat
                ),
                'studentanswer' => $attemptdata['studentanswer'],
                'testresults' => $attemptdata['testresults'],
                'programminglanguage' => $attemptdata['programminglanguage'],
            ];
            $questions[] = $entry;
            $totallength += mb_strlen($entry['questiontext'], 'UTF-8');

            if ($totallength >= self::MAX_TOTAL_TEXT) {
                break;
            }
        }

        return $questions;
    }

    /**
     * 获取当前用户可见的 mod/page 页面记录
     *
     * @param \context $context 上下文
     * @return \stdClass|null 页面记录（含 content/contentformat），非页面模块或不可见时返回 null
     */
    private static function get_visible_page_record(\context $context): ?\stdClass {
        global $DB;

        if ($context->contextlevel !== CONTEXT_MODULE) {
            return null;
        }

        $page = $DB->get_record_sql(
            "SELECT p.id, p.course, p.content, p.contentformat
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = 'page'
               JOIN {page} p ON p.id = cm.instance
              WHERE cm.id = :cmid",
            ['cmid' => $context->instanceid]
        );

        if ($page === null) {
            return null;
        }

        // 权限防御：入口处的 require_capability 只保证插件使用权限，
        // 不保证该课程模块对当前用户可见（如隐藏/可用性限制），
        // 此处校验 uservisible，确保不泄露学生访问不到的页面中的题目。
        try {
            $cm = get_fast_modinfo($page->course)->get_cm((int)$context->instanceid);
            if (!$cm || !$cm->uservisible) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        return $page;
    }

    /**
     * 解析单条嵌入码，还原为题库中的最新 ready 版本题目
     *
     * 解析流程与 filter_embedquestion 渲染嵌入题时的查找逻辑一致：
     * 校验 token → 定位课程 → 定位题库（qbank）→ 定位类别 → 定位题目。
     *
     * @param string $embedcode {Q{...}Q} 内的嵌入码
     * @param \context $context 当前页面上下文（课程短名缺省时用于推断课程）
     * @return array|null 包含 question（题库 question 记录）与 embedid（嵌入题标识，
     *                    用于定位答题会话）的数组，解析失败时返回 null
     */
    private static function resolve_embedded_question(string $embedcode, \context $context): ?array {
        try {
            // 嵌入码格式：questioninfo|token 或 questioninfo|token|option=value...
            // questioninfo：courseshortname/qbankidnumber/categoryidnumber/questionidnumber（前两项可省略）
            $parts = explode('|', htmlspecialchars_decode($embedcode));
            if (count($parts) < 2) {
                return null;
            }
            $questioninfo = (string)array_shift($parts);
            $token = (string)array_pop($parts);

            $embedid = \filter_embedquestion\embed_id::create_from_string($questioninfo);
            if ($embedid === null) {
                return null;
            }

            // 与过滤器渲染时的校验一致：仅提取页面中真实可渲染的嵌入题。
            if (!\filter_embedquestion\token::is_authorized_secret_token($token, $embedid)) {
                return null;
            }

            $courseid = \filter_embedquestion\utils::get_relevant_courseid($context);
            if (!empty($embedid->courseshortname)) {
                $courseid = (int)\filter_embedquestion\utils::get_courseid_by_course_shortname($embedid->courseshortname);
            }
            if (empty($courseid)) {
                return null;
            }

            $qbankcmid = \filter_embedquestion\utils::get_qbank_by_idnumber(
                $courseid,
                $embedid->questionbankidnumber
            );
            if (!$qbankcmid || $qbankcmid === -1) {
                return null;
            }

            $qbankcontext = \context_module::instance($qbankcmid);
            if ($qbankcontext === false) {
                return null;
            }

            $category = \filter_embedquestion\utils::get_category_by_idnumber(
                $qbankcontext,
                $embedid->categoryidnumber
            );
            if ($category === null) {
                return null;
            }

            // 返回最新 ready 版本的题目（与过滤器渲染给学生的版本一致）。
            $question = \filter_embedquestion\utils::get_question_by_idnumber(
                $category->id,
                $embedid->questionidnumber
            );
            if ($question === null) {
                return null;
            }

            return [
                'question' => $question,
                // embed_id 的字符串形式与 report_embedquestion 记录的 embedid 列一致，
                // 用于定位当前用户在该嵌入题上的答题会话（question_usage）。
                'embedid' => (string)$embedid,
            ];
        } catch (\Throwable $e) {
            // 任一环节失败（如上下文不存在）都视为该嵌入码不可解析，跳过而非中断。
            return null;
        }
    }

    /**
     * 读取当前用户在该嵌入题上的最近一次作答数据
     *
     * 嵌入题没有 .que DOM，前端无法直接读取学生代码与运行反馈；此处从
     * report_embedquestion 保存的答题会话（question_attempt）中还原：
     * 学生提交的代码、系统运行反馈与详细测试结果、编程语言。
     *
     * @param string $embedid 嵌入题标识（embed_id 的字符串形式）
     * @param \context $context 页面模块上下文
     * @param \stdClass $question 题库题目记录
     * @return array 含 studentanswer / testresults / programminglanguage 三个键，
     *               无历史作答或读取失败时各字段为空字符串
     */
    private static function get_attempt_data(string $embedid, \context $context, \stdClass $question): array {
        global $CFG, $DB, $USER;

        $data = [
            'studentanswer' => '',
            'testresults' => '',
            'programminglanguage' => self::get_programming_language($question),
        ];

        try {
            // 嵌入题由外部服务接口解析，运行环境未必已加载题库引擎，需显式引入。
            require_once($CFG->dirroot . '/question/engine/lib.php');
            if (!$DB->get_manager()->table_exists('report_embedquestion_attempt')) {
                return $data;
            }

            $attemptinfo = $DB->get_record('report_embedquestion_attempt', [
                'userid' => $USER->id,
                'contextid' => $context->id,
                'embedid' => $embedid,
            ]);
            if (!$attemptinfo) {
                return $data;
            }

            $quba = \question_engine::load_questions_usage_by_activity((int)$attemptinfo->questionusageid);
            $slots = $quba->get_slots();
            if (empty($slots)) {
                return $data;
            }

            // 一次嵌入题作答会话对应一个 slot（重复作答会追加 slot，取最后一次）。
            $slot = (int)end($slots);
            $qa = $quba->get_question_attempt($slot);
            $attemptquestion = $quba->get_question($slot);

            $answer = $qa->get_last_qt_var('answer');
            if (is_array($answer)) {
                // 填空题按空位以数组保存，拼接为可读文本。
                $answer = implode("\n", array_map('strval', $answer));
            }
            $data['studentanswer'] = trim((string)$answer);

            $data['testresults'] = self::format_attempt_outcome($qa, $attemptquestion);

            $language = self::get_programming_language($attemptquestion);
            if ($language !== '') {
                $data['programminglanguage'] = $language;
            }
        } catch (\Throwable $e) {
            // 读取历史作答失败不应阻塞嵌入题列表，降级为无作答数据。
            return $data;
        }

        return $data;
    }

    /**
     * 将作答会话中的运行反馈与测试结果格式化为可读文本（按题型分派）
     *
     * CodeRunner 系列读取其 `_testoutcome`（JSON，交由 prompt_filter 统一渲染为
     * Markdown 测试表格）；代码填空题读取判题记录。其余题型无运行反馈。
     *
     * @param \question_attempt $qa 题目作答
     * @param mixed $question 题目对象
     * @return string 格式化文本；无运行结果时返回空字符串
     */
    private static function format_attempt_outcome(\question_attempt $qa, $question): string {
        if ($question instanceof \qtype_coderunner_question) {
            return self::format_coderunner_outcome($qa, $question);
        }
        if ($question instanceof \qtype_codefillinblank_question) {
            return self::format_codefillinblank_outcome($qa);
        }
        return '';
    }

    /**
     * 格式化 CodeRunner 系列的运行反馈与测试结果（JSON）
     *
     * 输出结构化的 outcome / errorMessage / headers / rows / isCorrect 等字段，
     * 由 prompt_filter::format_test_results_from_json() 统一渲染为可读文本。
     *
     * @param \question_attempt $qa 题目作答
     * @param object $question CodeRunner 题目对象
     * @return string JSON 字符串；无运行结果时返回空字符串
     */
    private static function format_coderunner_outcome(\question_attempt $qa, $question): string {
        $serialised = $qa->get_last_qt_var('_testoutcome');
        if (!is_string($serialised) || $serialised === '') {
            return '';
        }

        $outcome = @unserialize($serialised);
        if (!is_object($outcome) || !($outcome instanceof \qtype_coderunner_testing_outcome)) {
            return '';
        }

        // 运行失败 / 语法错误 / 无法解析：只发送原始错误信息。
        if ($outcome->invalid() || $outcome->run_failed() || $outcome->has_syntax_error()) {
            return (string)json_encode(
                ['error_raw' => (string)$outcome->errormessage],
                JSON_UNESCAPED_UNICODE
            );
        }

        $payload = [];
        if ($outcome->is_precheck($qa)) {
            $payload['isPrecheck'] = true;
        }

        // 逐条测试用例：表头 + 数据行 + 通过情况（与 CodeRunner 前端表格一致）。
        $tablerows = $outcome->get_test_results($question);
        if (is_array($tablerows) && count($tablerows) > 1) {
            $rawheaders = array_map('strtolower', array_map('strval', $tablerows[0]));

            $headerindexes = [];
            $payload['headers'] = [];
            foreach ($rawheaders as $index => $header) {
                if ($header === 'ishidden') {
                    continue;
                }
                $headerindexes[] = $index;
                // iscorrect 列在表格中渲染为勾/叉，表头留空。
                $payload['headers'][] = $header === 'iscorrect' ? '' : (string)$tablerows[0][$index];
            }

            $payload['rows'] = [];
            $payload['isCorrect'] = [];
            $rowcount = count($tablerows);
            for ($r = 1; $r < $rowcount; $r++) {
                $cells = $tablerows[$r];
                $row = [];
                $iscorrect = true;
                foreach ($headerindexes as $index) {
                    $cell = $cells[$index] ?? '';
                    if ($rawheaders[$index] === 'iscorrect') {
                        $fraction = (float)$cell;
                        $row[] = self::fraction_mark($fraction);
                        if ($fraction < 1) {
                            $iscorrect = false;
                        }
                    } else {
                        $row[] = self::result_cell_to_text($cell);
                    }
                }
                $payload['rows'][] = $row;
                $payload['isCorrect'][] = $iscorrect;
            }
        }

        // 无任何可展示的运行信息时返回空串，避免发送 "[]" 之类的空负载。
        if (empty($payload)) {
            return '';
        }

        return (string)json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 格式化代码填空题（codefillinblank）的判题反馈
     *
     * 判题结果保存在 qtype_codefillinblank_judge 表中，这里还原判题结果、
     * 测试通过率与编译错误，输出纯文本。
     *
     * @param \question_attempt $qa 题目作答
     * @return string 判题反馈文本；无判题记录时返回空字符串
     */
    private static function format_codefillinblank_outcome(\question_attempt $qa): string {
        if (!class_exists('\qtype_codefillinblank\store')) {
            return '';
        }

        try {
            $record = \qtype_codefillinblank\store::latest((int)$qa->get_database_id());
        } catch (\Throwable $e) {
            return '';
        }
        if (!$record) {
            return '';
        }

        $lines = [];
        if ($record->status === 'done') {
            if ((int)$record->result !== \qtype_codefillinblank\judge::RESULT_PENDING) {
                $lines[] = get_string('judgeresultlabel', 'qtype_codefillinblank', [
                    'result' => \qtype_codefillinblank\judge::result_label((int)$record->result),
                ]);
            }
            if ((int)$record->result !== \qtype_codefillinblank\judge::RESULT_CE) {
                $lines[] = get_string(
                    'passratelabel',
                    'qtype_codefillinblank',
                    round((float)$record->passrate * 100)
                );
            }
        } else if ($record->status === 'submitted') {
            $lines[] = get_string('judgepending', 'qtype_codefillinblank');
        }

        if (!empty($record->compileerror)) {
            $lines[] = get_string('compileerror', 'qtype_codefillinblank') . ":\n" . $record->compileerror;
        }

        return implode("\n", $lines);
    }

    /**
     * 获取题目使用的编程语言
     *
     * @param mixed $question 题目对象或题库记录
     * @return string 编程语言标识（如 python3）；无此信息时返回空字符串
     */
    private static function get_programming_language($question): string {
        if (!is_object($question)) {
            return '';
        }

        // 已加载的题目对象通常直接带有 language 属性。
        if (property_exists($question, 'language') && is_string($question->language)
                && trim($question->language) !== '') {
            return trim($question->language);
        }

        // 题库 question 记录不含语言信息：对 CodeRunner 系列查选项表补充。
        if (property_exists($question, 'qtype') && property_exists($question, 'id')
                && strpos((string)$question->qtype, 'coderunner') === 0) {
            global $DB;
            try {
                $options = $DB->get_record(
                    'question_coderunner_options',
                    ['questionid' => (int)$question->id],
                    'language, acelang'
                );
                if ($options) {
                    foreach (['language', 'acelang'] as $field) {
                        if (!empty($options->$field) && is_string($options->$field)) {
                            return trim($options->$field);
                        }
                    }
                }
            } catch (\Throwable $e) {
                return '';
            }
        }

        return '';
    }

    /**
     * 将测试用例得分比例转换为通过标记
     *
     * @param float $fraction 得分比例（1=完全通过，0=未通过）
     * @return string 通过标记
     */
    private static function fraction_mark(float $fraction): string {
        if ($fraction >= 1) {
            return '✓';
        }
        if ($fraction > 0) {
            return '◐';
        }
        return '✗';
    }

    /**
     * 将测试结果单元格转换为纯文本
     *
     * 单元格可能为 HTML 包装对象（期望/实际输出等），需还原为纯文本。
     *
     * @param mixed $cell 单元格内容
     * @return string 纯文本
     */
    private static function result_cell_to_text($cell): string {
        if ($cell instanceof \qtype_coderunner_html_wrapper) {
            $cell = $cell->value();
        }
        return trim(strip_tags((string)$cell));
    }

    /**
     * 获取题型的显示名称
     *
     * @param string $qtype 题型标识（如 coderunner_fillblank）
     * @return string 题型名称（插件缺失时回退为原始标识）
     */
    private static function get_qtype_name(string $qtype): string {
        if (\core_component::get_component_directory('qtype_' . $qtype)) {
            return (string)get_string('pluginname', 'qtype_' . $qtype);
        }
        return $qtype;
    }

    /**
     * 将题目文本（通常为 HTML）转换为纯文本
     *
     * @param string $questiontext 题目原文
     * @param int $format 题目文本格式（FORMAT_HTML / FORMAT_MARKDOWN / FORMAT_PLAIN）
     * @return string 纯文本
     */
    private static function questiontext_to_text(string $questiontext, int $format): string {
        // 注意：Moodle 的 FORMAT_* 常量定义为字符串（如 FORMAT_HTML = '1'），
        // 此处统一按整数比较，避免严格比较类型不匹配。
        if ($format === (int)FORMAT_HTML) {
            $text = self::html_to_plain_text($questiontext);
        } else {
            $text = $questiontext;
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (mb_strlen($text, 'UTF-8') > self::MAX_QUESTION_TEXT) {
            $text = mb_substr($text, 0, self::MAX_QUESTION_TEXT, 'UTF-8') . '…';
        }

        return trim($text);
    }

    /**
     * 将 HTML 转换为纯文本
     *
     * 块级元素边界转换为换行，保留代码块的行结构；
     * 与后端 clean_question_text() 的空白处理风格保持一致（压缩水平空白、折叠空行）。
     *
     * @param string $html HTML 内容
     * @return string 纯文本
     */
    private static function html_to_plain_text(string $html): string {
        // 移除脚本与样式内容。
        $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html) ?? '';
        // 行内换行。
        $html = preg_replace('~<br\s*/?>~i', "\n", $html) ?? '';
        // 列表项添加项目符号。
        $html = preg_replace('~<li\b[^>]*>~i', '• ', $html) ?? '';
        // 块级元素结束边界转换为换行。
        $html = preg_replace('~</(?:p|div|li|tr|h[1-6]|pre|blockquote|table)>~i', "\n", $html) ?? '';

        $html = strip_tags($html);
        // 压缩水平空白、折叠空行（保留换行符）。
        $html = preg_replace('~[ \t]+~', ' ', $html) ?? '';
        $html = preg_replace('~\n{3,}~', "\n\n", $html) ?? '';

        return trim($html);
    }
}
