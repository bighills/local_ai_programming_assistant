/**
 * AI 编程助手 - DOM 上下文提取模块
 *
 * 按需加载，负责：
 * - 从页面 DOM 提取题目上下文（题目文本、学生代码、测试结果）
 * - 管理 MutationObserver 监听 DOM 变化，自动刷新题目缓存
 *
 * @module     local_ai_programming_assistant/context
 * @author zfl
 * @package
 */
define([], function() {

    /** @type {Array|null} 缓存 DOM 提取结果，避免重复解析 */
    var questionsContextCache = null;
    /** @type {MutationObserver|null} MutationObserver 实例 */
    var questionsContextObserver = null;
    /** @type {number|null} 观察者防抖定时器引用 */
    var questionsContextDebounceTimer = null;
    /** @type {boolean} 标记是否需要刷新缓存 */
    var pendingContextRefresh = false;

    /**
     * 规范化提取的文本 - 合并多余空白、规范化换行符
     *
     * 注意：保留换行符，仅压缩水平空白，与后端 clean_question_text() 保持一致
     * 这样可以确保代码格式不被破坏。
     *
     * @param {string} text 原始文本
     * @returns {string} 规范化后的文本
     */
    function normalizeText(text) {
        if (!text) {
            return '';
        }
        // 合并 \r\n → \n, 然后 \r → \n（单次遍历，避免两次 replace）
        text = text.replace(/\r\n?/g, '\n');
        // 折叠水平空白
        text = text.replace(/[ \t]+/g, ' ');
        // 最多保留一个空行 & 行首尾修剪（合并为一次遍历）
        text = text.replace(/^[ \t]+|[ \t]+$/gm, '').replace(/\n{3,}/g, '\n\n');
        return text.trim();
    }

    /**
     * 题目文本提取时需要移除的非内容元素选择器列表
     * 在三级回退策略之间共享，避免重复定义
     */
    var QUE_REMOVE_SELECTORS = [
        '.ablock', '.answer', '.prompt', '.coderunner-examples',
        '.ace_editor', '.CodeMirror', 'textarea', 'select',
        '.penaltyregime', '.answerprompt', '.resetbutton', '.im-controls',
        '.specificfeedback', '.generalfeedback', '.outcome',
        '.grade', '.grading', '.penalty', '.comment', '.history',
        '.graderesult', '.response', '.submission', '.result',
        '.correctness', '.incorrect', '.partiallycorrect',
        '.state', '.generaltable',
        '.formulation', '.info', '.qno',
        // 填空代码框架（.fillblank-editor / .codefillinblank-frame）是学生的答题区
        // （代码骨架 + 填空控件），不属于题目描述，不作为题目内容读取，
        // 避免在 .formulation 回退策略中连同行号一起被纳入题目文本。
        '.fillblank-editor', '.codefillinblank-frame'
    ];
    var QUE_REMOVE_SELECTOR_STR = QUE_REMOVE_SELECTORS.join(', ');

    /**
     * 从题目元素中提取题目文本
     *
     * 使用三级回退策略：
     * 1. 优先从 .qtext 提取（大多数 Moodle 题型使用）
     * 2. 回退到 .formulation（CodeRunner 等题型）
     * 3. 最后从 .content 提取
     *
     * 提取时会移除答案框、反馈区、编辑器等非题目内容的元素。
     *
     * @param {Element} que 题目 DOM 元素
     * @returns {string} 提取的题目文本
     */
    function extractQuestionText(que) {
        var selectors = ['.qtext', '.formulation', '.content'];
        for (var i = 0; i < selectors.length; i++) {
            var container = que.querySelector(selectors[i]);
            // 预检：容器不存在或无明显文本内容时跳过深拷贝
            if (!container) { continue; }
            // 使用 textContent（不触发 layout）替代 innerText 做空检，主提取时再用 innerText
            var rawText = container.textContent || '';
            if (!rawText.trim()) { continue; }
            var clone = container.cloneNode(true);
            clone.querySelectorAll(QUE_REMOVE_SELECTOR_STR).forEach(function(el) { el.remove(); });
            var text = normalizeText(clone.innerText);
            if (text) {
                return text;
            }
        }
        return '';
    }

    /**
     * 从 CodeMirror 编辑器元素中提取代码内容
     *
     * @param {Element} editorElement CodeMirror 编辑器 DOM 元素
     * @returns {string} 编辑器中的代码文本
     */
    function getCodeMirrorContent(editorElement) {
        var view = editorElement.querySelector('.CodeMirror-code');
        if (!view) {
            return '';
        }
        var lines = [];
        view.querySelectorAll('.CodeMirror-line').forEach(function(line) {
            lines.push(line.innerText);
        });
        return lines.join('\n');
    }

    /**
     * 从填空编辑器/代码框架重建完整学生代码
     *
     * 支持两种填空题型（效果同 CodeRunner）：
     * - coderunner_fillblank：fillblank.js 生成的 .fillblank-editor
     *   （.fillblank-line / .fillblank-code / .fillblank-text / .fillblank-gap）
     * - codefillinblank：renderer 生成的 .codefillinblank-frame
     *   （.codefillinblank-line / .codefillinblank-code / .codefillinblank-text / .codefillinblank-blank）
     *
     * 按行拼接文本段与填空值，结果与后端 util::reconstruct() 逻辑一致。
     * 优先于 textarea 提取，避免填空内容依赖定时同步（最长 3 秒）导致代码滞后。
     *
     * @param {Element} que 题目 DOM 元素
     * @returns {string|null} 重建的代码；未找到填空编辑器时返回 null
     */
    function extractFillBlankAnswer(que) {
        var container = que.querySelector('.fillblank-editor, .codefillinblank-frame');
        if (!container) {
            return null;
        }
        var isCoderunnerFillBlank = container.classList.contains('fillblank-editor');
        var lineSelector = isCoderunnerFillBlank ? '.fillblank-line' : '.codefillinblank-line';
        var codeSelector = isCoderunnerFillBlank ? '.fillblank-code' : '.codefillinblank-code';
        // classList.contains 需要不带前导点的类名
        var textClass = isCoderunnerFillBlank ? 'fillblank-text' : 'codefillinblank-text';
        var gapClass = isCoderunnerFillBlank ? 'fillblank-gap' : 'codefillinblank-blank';

        var codeLines = [];
        container.querySelectorAll(lineSelector).forEach(function(lineEl) {
            var codeEl = lineEl.querySelector(codeSelector);
            if (!codeEl) {
                return;
            }
            var lineText = '';
            codeEl.childNodes.forEach(function(child) {
                if (child.nodeType !== 1) {
                    return;
                }
                if (child.classList.contains(textClass)) {
                    // fillblank-text 内可能有语法高亮的嵌套 span，textContent 可完整取回原文
                    lineText += child.textContent;
                } else if (child.classList.contains(gapClass)) {
                    lineText += child.value;
                }
            });
            codeLines.push(lineText);
        });

        if (codeLines.length === 0) {
            return null;
        }
        return codeLines.join('\n').trim();
    }

    /**
     * 实际的 DOM 提取逻辑（分离出来以支持缓存包装）
     * @returns {Array} 题目上下文数组
     */
    function doExtractQuestionsContext() {
        var contexts = [];
        var questionElements = document.querySelectorAll('.que');

        questionElements.forEach(function(que) {
            var ctx = {
                number: '',
                questiontitle: '',
                questiontext: '',
                studentanswer: '',
                testresults: '',
                programminglanguage: '',
                questionid: 0,
                usageid: 0,
                slot: 0,
                isCodeRunner: false
            };

            // 检测是否为 CodeRunner 类型试题
            // Moodle 在 .que 元素上添加 qtype 名称作为 CSS 类（coderunner）
            // 补充检查 CodeRunner 特有 DOM 元素作为回退（自定义渲染可能不添加 qtype 类）
            // 填空类题型 coderunner_fillblank / codefillinblank 效果同 CodeRunner，一并识别
            ctx.isCodeRunner = que.classList.contains('coderunner') ||
                que.classList.contains('coderunner_fillblank') ||
                que.classList.contains('codefillinblank') ||
                !!que.querySelector('.coderunner-test-results, .coderunner-examples, .codefillinblank-outcome');

            // 单次查询提取所有关键元素，合并 3 次遍历为 1 次，减少 DOM 操作
            var qnodes = que.querySelectorAll(
                '.qno, h3.no, input[type="hidden"][name^="q"], ' +
                'textarea[name*="answer"], .ace_editor, .CodeMirror, ' +
                'input[name*="language"], select[name*="language"]'
            );

            // 尝试从 que.id 提取 usageid/slot（最常用方式，优先级高）
            var queId = que.id || '';
            var usageMatch = queId.match(/^question-(\d+)-(\d+)$/);
            if (usageMatch) {
                ctx.usageid = parseInt(usageMatch[1], 10);
                ctx.slot = parseInt(usageMatch[2], 10);
            }

            // 单次遍历：同时提取 number、usageid、studentanswer、programminglanguage
            for (var qi = 0; qi < qnodes.length; qi++) {
                var qnel = qnodes[qi];

                // 题号提取（仅在尚未找到时处理）
                if (!ctx.number) {
                    if (qnel.matches('.qno')) {
                        ctx.number = qnel.innerText.trim().replace('.', '');
                    } else if (qnel.matches('h3.no')) {
                        var numMatch = qnel.innerText.trim().match(/(\d+)/);
                        if (numMatch) { ctx.number = numMatch[1]; }
                    }
                }

                // usageid 回退提取（从 id 解析失败时尝试 hidden input）
                if (ctx.usageid === 0 && qnel.matches('input[type="hidden"][name^="q"]')) {
                    var nameMatch = qnel.name.match(/^q(\d+)/);
                    if (nameMatch) { ctx.usageid = parseInt(nameMatch[1], 10); }
                }

                // 学生代码 & 编程语言提取
                if (qnel.matches('textarea[name*="answer"]')) {
                    ctx.studentanswer = qnel.value.trim();
                } else if (qnel.matches('.ace_editor')) {
                    var aceTextarea = qnel.querySelector('textarea');
                    if (aceTextarea && aceTextarea.value) {
                        ctx.studentanswer = aceTextarea.value.trim();
                    }
                } else if (qnel.matches('.CodeMirror')) {
                    var cmTextarea = qnel.querySelector('textarea');
                    if (cmTextarea && cmTextarea.value) {
                        ctx.studentanswer = cmTextarea.value.trim();
                    } else {
                        var cmContent = getCodeMirrorContent(qnel);
                        if (cmContent) { ctx.studentanswer = cmContent.trim(); }
                    }
                } else if (qnel.matches('input[name*="language"], select[name*="language"]')) {
                    ctx.programminglanguage = qnel.value.trim();
                }
            }

            // 填空类题型（coderunner_fillblank / codefillinblank）：优先从编辑器/代码框架
            // 重建完整学生代码（textContent 提取代码、填空控件取当前值），
            // 避免依赖 textarea 的定时同步（最长 3 秒）导致代码滞后。
            var fillblankAnswer = extractFillBlankAnswer(que);
            if (fillblankAnswer !== null) {
                ctx.studentanswer = fillblankAnswer;
            }

            // 回退：用 slot 号作为题号
            if (!ctx.number && ctx.slot > 0) {
                ctx.number = ctx.slot.toString();
            }

            // Step 2: Extract question text using the multi-strategy helper.
            ctx.questiontext = extractQuestionText(que);

            // 提取题目标题：优先从 .qname 获取，回退到本题文本首行
            var qnameEl = que.querySelector('.info .qname') || que.querySelector('.qname');
            if (qnameEl && qnameEl.innerText.trim()) {
                ctx.questiontitle = qnameEl.innerText.trim();
            } else if (ctx.questiontext) {
                // 取第一行作为标题（questiontext 已是 innerText 纯文本，无需去 HTML）
                var firstLine = ctx.questiontext.split('\n')[0].trim();
                if (firstLine.length > 0) {
                    ctx.questiontitle = firstLine;
                }
            }

            // Step 3: If student code was found embedded in question text, remove it.
            if (ctx.studentanswer && ctx.questiontext) {
                var normalizedCode = normalizeText(ctx.studentanswer);
                var normalizedText = ctx.questiontext;

                // Try removing the student code as a contiguous block.
                var codeIdx = normalizedText.indexOf(normalizedCode);
                if (codeIdx !== -1) {
                    ctx.questiontext = normalizedText.substring(0, codeIdx) +
                                       normalizedText.substring(codeIdx + normalizedCode.length);
                    ctx.questiontext = normalizeText(ctx.questiontext);
                } else {
                    // Try line-by-line: remove lines that appear in student code.
                    var codeLines = normalizedCode.split('\n');
                    var codeLineSet = {};
                    for (var j = 0; j < codeLines.length; j++) {
                        var cl = codeLines[j].trim();
                        if (cl && cl.length > 3) {
                            codeLineSet[cl] = true;
                        }
                    }
                    var qLines = ctx.questiontext.split('\n');
                    var cleanedLines = [];
                    for (var i = 0; i < qLines.length; i++) {
                        var line = qLines[i].trim();
                        if (!codeLineSet[line]) {
                            cleanedLines.push(qLines[i]);
                        }
                    }
                    ctx.questiontext = normalizeText(cleanedLines.join('\n'));
                }
            }

            // Extract test results from known test output containers only.
            var testParts = [];

            var testResultsDiv = que.querySelector('.coderunner-test-results');
            if (testResultsDiv) {
                var table = testResultsDiv.querySelector('table.coderunner-test-results');
                if (table) {
                    var mdTable = '';

                    // Extract headers.
                    var thead = table.querySelector('thead');
                    if (thead) {
                        var headers = [];
                        thead.querySelectorAll('th').forEach(function(th) {
                            headers.push(th.textContent.trim());
                        });
                        if (headers.length > 0) {
                            mdTable += '| ' + headers.join(' | ') + ' |\n';
                            mdTable += '| ' + headers.map(function() { return '---'; }).join(' | ') + ' |\n';
                        }
                    }

                    // Extract rows.
                    var tbody = table.querySelector('tbody');
                    if (tbody) {
                        tbody.querySelectorAll('tr').forEach(function(tr) {
                            var cells = [];
                            tr.querySelectorAll('td').forEach(function(td) {
                                cells.push(td.textContent.trim());
                            });
                            if (cells.length > 0) {
                                mdTable += '| ' + cells.join(' | ') + ' |\n';
                            }
                        });
                    }

                    if (mdTable) {
                        testParts.push(mdTable.trim());
                    }

                    // Extract summary paragraph (e.g. "Your code passed 3/5 tests").
                    var summaryParaEl = testResultsDiv.querySelector(
                        'p:not(.run_failed_error):not(.good_empty_precheck):not(.bad_empty_precheck)'
                    );
                    if (summaryParaEl) {
                        var summaryText = normalizeText(summaryParaEl.textContent);
                        if (summaryText && summaryText.indexOf('precheck') < 0) {
                            testParts.push(summaryText);
                        }
                    }
                } else {
                    // Fallback: no HTML table found, use plain text.
                    var text = normalizeText(testResultsDiv.innerText);
                    if (text) {
                        testParts.push(text);
                    }
                }
            }

            var syntaxError = que.querySelector('.pre_syntax_error');
            if (syntaxError) {
                var syntaxText = normalizeText(syntaxError.innerText);
                if (syntaxText) {
                    testParts.push('Syntax Error: ' + syntaxText);
                }
            }

            var runError = que.querySelector('.run_failed_error');
            if (runError) {
                var runtimeText = normalizeText(runError.innerText);
                if (runtimeText) {
                    testParts.push('Runtime Error: ' + runtimeText);
                }
            }

            // codefillinblank：判题结果区（含判题结果、编译错误、待判/出错提示）
            var cfOutcome = que.querySelector('.codefillinblank-outcome');
            if (cfOutcome) {
                var cfOutcomeText = normalizeText(cfOutcome.innerText);
                if (cfOutcomeText) {
                    testParts.push(cfOutcomeText);
                }
            }

            if (testParts.length > 0) {
                ctx.testresults = testParts.join('\n\n');
            }

            contexts.push(ctx);
        });

        return contexts;
    }

    /**
     * 从页面提取所有题目的上下文信息（带缓存）
     *
     * 首次调用时从 DOM 提取并缓存结果；后续调用直接返回缓存。
     * 当 DOM 结构变化时（如翻页），通过 MutationObserver 自动标记缓存失效。
     *
     * @param {boolean} forceRefresh 强制重新从 DOM 提取
     * @returns {Array} 题目上下文数组
     */
    function extractAllQuestionsContext(forceRefresh) {
        if (!forceRefresh && questionsContextCache !== null && !pendingContextRefresh) {
            return questionsContextCache;
        }
        pendingContextRefresh = false;
        questionsContextCache = doExtractQuestionsContext();
        return questionsContextCache;
    }

    /**
     * 设置 MutationObserver 监听 DOM 变化
     *
     * 当页面中的题目元素（.que）发生变化时（如翻页、题目重新加载），
     * 标记缓存为脏状态，下次提取时自动从 DOM 重新获取。
     */
    function setupQuestionsObserver() {
        if (questionsContextObserver) {
            questionsContextObserver.disconnect();
        }
        var scheduleContextRefresh = function() {
            pendingContextRefresh = true;
            if (questionsContextDebounceTimer) {
                clearTimeout(questionsContextDebounceTimer);
            }
            questionsContextDebounceTimer = setTimeout(function() {
                questionsContextDebounceTimer = null;
                // 标记为脏已足够，extractAllQuestionsContext 在读取缓存时会检查此标志
            }, 200);
        };

        questionsContextObserver = new MutationObserver(function(mutations) {
            // 防抖：200ms 内合并多次 DOM 变动为一次检查
            var aiPanel = document.getElementById('ai-programming-assistant');
            for (var i = 0; i < mutations.length; i++) {
                var mut = mutations[i];
                if (mut.addedNodes.length > 0 || mut.removedNodes.length > 0) {
                    var target = mut.target;
                    // 跳过 AI 助手面板内的 DOM 变化，避免日志渲染触发无意义的缓存刷新
                    if (aiPanel && (aiPanel === target || aiPanel.contains(target))) { continue; }
                    // 命中条件：target 本身是 .que、位于 .que 内部（如 CodeRunner 测试结果
                    // 插入 .que 内部节点时 target 是其后代）、或包含 .que 后代
                    var affected = target &&
                        (target.classList.contains('que') ||
                         target.closest('.que') ||
                         target.querySelector('.que'));
                    // 移除最后一个 .que 时 target 查不到已脱离 DOM 的节点，需检查 removedNodes
                    if (!affected && mut.removedNodes.length > 0) {
                        for (var j = 0; j < mut.removedNodes.length; j++) {
                            var rn = mut.removedNodes[j];
                            if (rn.nodeType === 1 && (rn.classList.contains('que') || rn.querySelector('.que'))) {
                                affected = true;
                                break;
                            }
                        }
                    }
                    if (affected) {
                        scheduleContextRefresh();
                        return;
                    }
                }
            }
        });
        var observeTarget = document.querySelector('#quiz-contents') || document.querySelector('form.mform') || document.body;
        questionsContextObserver.observe(observeTarget, {
            childList: true,
            subtree: true
        });
    }

    /**
     * 清理 MutationObserver 和定时器
     * 用于页面卸载时调用，防止内存泄漏
     */
    function cleanupObserver() {
        if (questionsContextObserver) {
            questionsContextObserver.disconnect();
            questionsContextObserver = null;
        }
        if (questionsContextDebounceTimer) {
            clearTimeout(questionsContextDebounceTimer);
            questionsContextDebounceTimer = null;
        }
    }

    return {
        normalizeText: normalizeText,
        extractQuestionText: extractQuestionText,
        extractAllQuestionsContext: extractAllQuestionsContext,
        getCodeMirrorContent: getCodeMirrorContent,
        extractFillBlankAnswer: extractFillBlankAnswer,
        setupQuestionsObserver: setupQuestionsObserver,
        cleanupObserver: cleanupObserver
    };
});