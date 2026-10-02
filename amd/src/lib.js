/**
 * AI 编程助手 - 前端交互模块
 *
 * 负责：
 * - 管理聊天面板的展开/收起/弹出/最小化
 * - 处理用户消息输入和发送
 * - 管理策略模式切换（自由问答/题目分析/思路整理/代码调试）
 * - 按需加载 stream 和 context 子模块
 * - 渲染对话日志和管理聊天历史
 *
 * @module     local_ai_programming_assistant/lib
 * @author zfl
 * @package
 */
define(['core/ajax'], function(Ajax) {

    // ===================== 常量 =====================

    var COPY_FEEDBACK_DELAY = 2000;
    var MAX_HISTORY = 50;
    var MAX_MESSAGE_LENGTH = 10000;
    var MAX_SELECTED_TEXT_LENGTH = 2000;
    /** @type {number} 对话日志最大 DOM 节点数，超出时截断最旧消息 */
    var MAX_DOM_MESSAGES = 100;
    var SCROLL_THRESHOLD = 80;

    // ===================== 视图模式常量 =====================

    /** @type {string} 视图模式 localStorage 键 */
    var VIEWMODE_KEY = 'local_ai_programming_assistant_viewmode';
    /** @type {string} 侧边栏宽度百分比 localStorage 键 */
    var SIDEBAR_WIDTH_KEY = 'local_ai_programming_assistant_sidebar_width';
    /** @type {string} 悬浮窗模式 */
    var VIEWMODE_FLOATING = 'floating';
    /** @type {string} 侧边栏模式 */
    var VIEWMODE_SIDEBAR = 'sidebar';
    /** @type {string} 当前视图模式 */
    var currentViewMode = VIEWMODE_FLOATING;

    // ===================== 会话持久化常量 =====================

    /** @type {string} 会话状态 localStorage 键 */
    var SESSION_KEY = 'local_ai_programming_assistant_session';
    /** @type {number} 会话保存防抖延迟 (ms) */
    var SESSION_SAVE_DELAY = 300;
    /** @type {number|null} 会话保存定时器 */
    var sessionSaveTimer = null;

    // ===================== 模块级状态 =====================

    /** @type {Object<string, Array<{user:string, message:string}>>} 按策略模式隔离的对话历史 */
    var chatHistoryByMode = {};

    /** @type {Object<string, number>} 按策略模式隔离的对话会话 ID */
    var conversationIdByMode = {};

    /**
     * 获取当前策略模式对应的对话历史数组
     * @returns {Array<{user:string, message:string}>}
     */
    function getCurrentHistory() {
        var key = currentActionType || 'free';
        if (!chatHistoryByMode[key]) {
            chatHistoryByMode[key] = [];
        }
        return chatHistoryByMode[key];
    }

    /**
     * 获取当前策略模式对应的对话会话 ID
     * @returns {number}
     */
    function getCurrentConversationId() {
        var key = currentActionType || 'free';
        return conversationIdByMode[key] || 0;
    }

    /**
     * 设置当前策略模式的对话会话 ID
     * @param {number} id 会话 ID
     */
    function setCurrentConversationId(id) {
        var key = currentActionType || 'free';
        conversationIdByMode[key] = id || 0;
        saveSession();
    }

    var config = {};
    var panelExpanded = false;
    var panelPopped = false;
    /** @var {boolean} 面板展开自动聚焦时跳过选中文本粘贴 */
    var panelFocusSkipPaste = false;
    /**
     * 页面全部可选题目上下文（DOM 试题 + 后端提取的嵌入题），
     * 索引与题目选择器的 option 值一一对应。
     * @type {Array<Object>}
     */
    var questionsContext = [];
    /**
     * 仅由前端 DOM 提取的试题上下文缓存（.que 元素）。
     * 嵌入题不在 DOM 中，需与 embeddedQuestions 合并后才是完整列表。
     * @type {Array<Object>}
     */
    var domQuestionsContext = [];
    var isSending = false;
    var currentActionType = 'free';
    /** @type {number} 策略切换请求序列号，用于防止竞态条件 */
    var strategyRequestSeq = 0;
    /** @type {number} 渲染令牌，用于在 AJAX 响应到达时使本地缓存的渲染批次失效 */
    var renderToken = 0;
    var currentQuestionIndex = -1;
    /**
     * 题目选择器"全部嵌入题"选项的取值（哨兵值）。
     * 选中该选项时不指定具体题目，发送空 questiontext，由后端注入全部嵌入题。
     * 注意：不能使用 -1，模板占位 option 也用 -1 且 getSelectedQuestionIndex 将 -1 视为未选中。
     */
    var ALL_EMBEDDED_VALUE = -2;
    /**
     * mod/page 页面通过后端提取的嵌入题上下文列表（tiny_embedquestion 嵌入题，
     * 页面无 .que DOM 结构，无法通过 DOM 提取）。每个元素结构与 DOM 提取的
     * 题目上下文一致，追加在 DOM 试题之后，索引与题目选择器选项对应。
     * @type {Array<Object>}
     */
    var embeddedQuestions = [];
    /** @var {boolean} 嵌入题列表是否已拉取（防止重复请求） */
    var embeddedQuestionsLoaded = false;
    var responseMode = 'streaming';
    var abortController = null;
    var strings = {};
    var dom = {};
    var errorString = '';
    var strategyBtns = null;
    /** @var {Element|null} 返回按钮缓存 */
    var backBtn = null;
    /** @var {Element|null} 自由对话按钮缓存 */
    var freeBtn = null;
    /** @var {NodeList|null} 策略按钮列表缓存 */
    var strategyItems = null;
    /** @var {MutationObserver|null} 监听测试结果出现的观察器 */
    var codeCheckObserver = null;
    /** @var {boolean} 是否处于策略对话模式（选中策略后进入） */
    var inStrategyMode = false;
    /** @var {string|null} 最小化前保存的策略模式，用于恢复时还原 */
    var savedStrategyBeforeMinimize = null;
    /**
     * 按策略模式缓存的离线 DOM 容器（隐藏的 <div> 元素，离屏不触发 reflow）
     * 切换时交换子节点而非销毁，缓存永不清空，任意次切换均为 O(1) 节点移动
     * @type {Object<string, HTMLDivElement>}
     */
    var domCacheByMode = {};
    /** @type {Object<string, number>} 按策略模式缓存的滚动位置 */
    var scrollCacheByMode = {};
    /** @var {boolean} 渲染历史记录期间为 true，阻止 MutationObserver 触发 */
    var isRenderingHistory = false;
    if (typeof window !== 'undefined') {
        window.__aiRenderingHistory = function(val) {
            if (val !== undefined) { isRenderingHistory = val; }
            return isRenderingHistory;
        };
    }

    /**
     * 设置渲染历史记录标志（封装全局函数守卫，消除重复的 typeof 检查样板）
     * @param {boolean} flag 是否正在渲染历史记录
     */
    function setRenderingHistory(flag) {
        if (typeof window !== 'undefined' && typeof window.__aiRenderingHistory === 'function') {
            window.__aiRenderingHistory(flag);
        }
    }

    // ===================== 子模块懒加载 =====================

    /** @type {Object<string, Object>} 已加载的 AMD 模块缓存 */
    var cachedModules = {};
    /** @type {Array<function>} 等待子模块加载完成的回调队列 */
    var moduleCallbacks = [];
    /** @type {boolean} 子模块加载失败标志（防止后续调用无限等待队列） */
    var moduleLoadFailed = false;

    /**
     * 确保 stream 和 context 子模块已加载
     *
     * 使用回调队列而非每次调用 require() 的独立加载，避免 AMD 重复请求。
     * 首次调用时触发一次 require()，所有等待回调在模块就绪时统一执行。
     * 加载失败时（模块构建损坏/网络错误）以 (null, null) 刷新队列，
     * 由调用方兜底展示错误并复位状态，避免 isSending 永久卡死。
     *
     * @param {function(Object|null, Object|null): void} callback 接收到 (stream, context) 后的回调
     */
    function ensureModules(callback) {
        if (cachedModules.stream && cachedModules.context) {
            callback(cachedModules.stream, cachedModules.context);
            return;
        }
        if (moduleLoadFailed) {
            callback(null, null);
            return;
        }
        moduleCallbacks.push(callback);
        if (moduleCallbacks.length === 1) {
            require(
                ['local_ai_programming_assistant/stream', 'local_ai_programming_assistant/context'],
                function(stream, context) {
                    cachedModules.stream = stream;
                    cachedModules.context = context;
                    var q = moduleCallbacks.slice();
                    moduleCallbacks = [];
                    q.forEach(function(cb) { cb(stream, context); });
                },
                function(err) {
                    // eslint-disable-next-line no-console
                    console.error('[AI Programming Assistant] Failed to load stream/context modules:', err);
                    moduleLoadFailed = true;
                    var q = moduleCallbacks.slice();
                    moduleCallbacks = [];
                    q.forEach(function(cb) { cb(null, null); });
                }
            );
        }
    }

    // ===================== 最小工具函数（回退用，正常流程委托给 stream 模块） =====================

    /**
     * 对缓存消息的 renderedHtml 应用净化后回写 DOM（恢复路径防御纵深）
     *
     * 流式渲染产出的 renderedHtml 因增量更新限制未经过 DOMParser 净化
     * （只经 renderer.html 正则过滤）。该 HTML 被持久化到本地缓存后，
     * 会话恢复路径原样回写会反复重放未净化内容，此处统一在回写点补齐净化。
     * sanitizeHtml 需要完整 HTML，恢复路径的 renderedHtml 是已渲染完成的
     * 完整片段，适合 DOMParser 处理。
     *
     * @param {string} html 缓存的渲染 HTML
     * @returns {string} 净化后的 HTML
     */
    function sanitizeRenderedHtml(html) {
        if (html && cachedModules.stream && cachedModules.stream.sanitizeHtml) {
            return cachedModules.stream.sanitizeHtml(html);
        }
        return html;
    }

    /**
     * HTML 转义（优先委托给 stream 模块的标准实现）
     * @param {string} text 原始文本
     * @returns {string} 转义后的 HTML 安全文本
     */
    function escapeHtml(text) {
        if (cachedModules.stream) {
            return cachedModules.stream.escapeHtml(text);
        }
        if (text == null) {
            return '';
        }
        if (typeof text !== 'string') {
            text = String(text);
        }
        return text.replace(/[&<>"']/g, function(ch) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch];
        });
    }

    /**
     * 滚动到日志底部（优先委托给 stream 模块的标准实现）
     * @param {Element} log
     */
    function scrollToBottom(log) {
        if (cachedModules.stream) {
            cachedModules.stream.scrollToBottom(log);
            return;
        }
        if (!log || (log.scrollHeight - log.scrollTop - log.clientHeight > SCROLL_THRESHOLD)) {
            return;
        }
        requestAnimationFrame(function() {
            log.scrollTop = log.scrollHeight;
        });
    }

    var defaultStrings = {
        ai_sender: 'AI',
        me: '我',
        copy: '复制',
        copied: '已复制！',
        ai_feedback_loading: 'AI 正在分析...',
        request_error: '请求失败，请稍后重试。',
        thinking_label: '思考过程',
        thinking_in_progress: 'AI 正在思考...',
        thinking_done: '思考完成'
    };

    function getString(key, fallback) {
        var val = strings[key];
        if (val && val.indexOf('[[') !== 0) {
            return val;
        }
        return defaultStrings[key] || fallback || key;
    }

    // ===================== 策略配置 =====================

    /**
     * 策略配置集中管理
     * 每个策略定义自己的行为：占位符、要发送的上下文字段、是否显示测试结果选项、显示标签
     */
    var STRATEGY_CONFIG = {
        'free': {
            labelKey: 'strAskQuestion',
            labelFallback: '自由问答',
            placeholderKey: 'strAskQuestion',
            placeholderFallback: '提问关于代码的问题...',
            showStudentAnswerOption: false,
            showTestResultsOption: false,
            defaultSendStudentCode: false,
            defaultSendTestResults: false,
            autoSelectFirstQuestion: false,
            requiresQuestion: false,
            sendFields: ['questiontext', 'studentanswer']
        },
        'question_analysis': {
            labelKey: 'strQuestionAnalysis',
            labelFallback: '题目分析',
            placeholderKey: 'strQuestionAnalysisHint',
            placeholderFallback: '点击发送即可分析题目...',
            showStudentAnswerOption: true,
            showTestResultsOption: true,
            defaultSendStudentCode: false,
            defaultSendTestResults: false,
            autoSelectFirstQuestion: true,
            requiresQuestion: true,
            sendFields: ['questiontext', 'studentanswer', 'testresults']
        },
        'idea_organization': {
            labelKey: 'strIdeaOrganization',
            labelFallback: '思路整理',
            placeholderKey: 'strIdeaOrganizationHint',
            placeholderFallback: '点击发送即可整理思路...',
            showStudentAnswerOption: true,
            showTestResultsOption: true,
            defaultSendStudentCode: false,
            defaultSendTestResults: false,
            autoSelectFirstQuestion: true,
            requiresQuestion: true,
            sendFields: ['questiontext', 'studentanswer', 'testresults']
        },
        'code_debugging': {
            labelKey: 'strCodeDebugging',
            labelFallback: '代码调试',
            placeholderKey: 'strCodeDebuggingHint',
            placeholderFallback: '点击发送即可调试代码...',
            showStudentAnswerOption: true,
            showTestResultsOption: true,
            defaultSendStudentCode: true,
            defaultSendTestResults: true,
            autoSelectFirstQuestion: true,
            requiresQuestion: true,
            sendFields: ['questiontext', 'studentanswer', 'testresults']
        }
    };

    /**
     * 获取指定策略类型的配置
     * @param {string} type 策略类型标识符
     * @returns {Object} 策略配置对象
     */
    function getStrategyConfig(type) {
        return STRATEGY_CONFIG[type] || STRATEGY_CONFIG['free'];
    }

    // ===================== 代码调试解锁逻辑 =====================

    /**
     * 检查页面上是否有可用的代码测试结果
     * （学生点击"检查"按钮后，CodeRunner 会生成测试结果 DOM）
     *
     * 优化：使用单次组合选择器替代 4 次独立 querySelector 调用，
     * 将直接匹配和嵌套匹配合并为一次 DOM 遍历。
     *
     * @returns {boolean}
     */
    function hasTestResults() {
        // 组合选择器：直接匹配 CodeRunner 测试结果/错误元素，或在 .submission/.graderesult 内嵌套匹配
        // codefillinblank 的判题结果区（.codefillinblank-outcome）同样视为可用测试结果
        return !!document.querySelector(
            '.coderunner-test-results, .run_failed_error, .pre_syntax_error, .codefillinblank-outcome, ' +
            '.submission .coderunner-test-results, .submission .run_failed_error, .submission .pre_syntax_error, ' +
            '.graderesult .coderunner-test-results, .graderesult .run_failed_error, .graderesult .pre_syntax_error'
        );
    }

    /**
     * 解锁代码调试按钮
     * 当检测到测试结果后移除禁用状态
     */
    function enableCodeDebuggingButton() {
        // 优先使用缓存的 strategyBtns，避免重复 DOM 查询
        var codeBtn = null;
        if (strategyBtns) {
            for (var i = 0; i < strategyBtns.length; i++) {
                if (strategyBtns[i].getAttribute('data-action') === 'code_debugging') {
                    codeBtn = strategyBtns[i];
                    break;
                }
            }
        }
        if (!codeBtn) {
            codeBtn = document.querySelector('.ai-strategy-btn[data-action="code_debugging"]');
        }
        if (codeBtn) {
            codeBtn.classList.remove('ai-strategy-disabled');
            codeBtn.removeAttribute('title');
        }
        if (codeCheckObserver) {
            codeCheckObserver.disconnect();
            codeCheckObserver = null;
        }
    }

    /**
     * 启动 MutationObserver 监听测试结果出现
     * 当用户点击"检查"按钮后，CodeRunner 会在 DOM 中添加测试结果节点
     */
    function setupCodeCheckObserver() {
        // 立即检查一次（页面可能已加载了测试结果）
        if (hasTestResults()) {
            enableCodeDebuggingButton();
            return;
        }

        var observeTarget = document.querySelector('#quiz-contents') || document.querySelector('form.mform') || document.body;
        codeCheckObserver = new MutationObserver(function(mutations) {
            if (isRenderingHistory) { return; }
            for (var i = 0; i < mutations.length; i++) {
                var addedNodes = mutations[i].addedNodes;
                for (var j = 0; j < addedNodes.length; j++) {
                    var node = addedNodes[j];
                    if (node.nodeType === 1) {
                        // 跳过 AI 助手面板内的 DOM 变化，避免日志渲染触发无意义检查
                        if (dom.container && dom.container.contains(node)) { continue; }
                        if (node.classList && (
                            node.classList.contains('coderunner-test-results') ||
                            node.classList.contains('run_failed_error') ||
                            node.classList.contains('pre_syntax_error') ||
                            node.classList.contains('codefillinblank-outcome')
                        )) {
                            enableCodeDebuggingButton();
                            return;
                        }
                        if (node.querySelector && node.querySelector(
                            '.coderunner-test-results, .run_failed_error, .pre_syntax_error, .codefillinblank-outcome'
                        )) {
                            enableCodeDebuggingButton();
                            return;
                        }
                    }
                }
            }
        });
        codeCheckObserver.observe(observeTarget, { childList: true, subtree: true });
    }

    // ===================== DOM 缓存 =====================

    function cacheDom() {
        dom = {
            container: document.getElementById('ai-programming-assistant'),
            input: document.getElementById('ai-assistant-input'),
            sendBtn: document.getElementById('ai-assistant-send'),
            log: document.getElementById('ai-assistant-log'),
            toggle: document.getElementById('ai-assistant-toggle'),
            refresh: document.getElementById('ai-assistant-refresh'),
            popout: document.getElementById('ai-assistant-popout'),
            minimize: document.getElementById('ai-assistant-minimize'),
            stopBtn: document.getElementById('ai-assistant-stop'),
            questionSelect: document.getElementById('ai-assistant-question-select'),
            strategyOptions: document.getElementById('ai-assistant-strategy-options'),
            sendStudentCode: document.getElementById('ai-assistant-send-student-code'),
            sendTestResults: document.getElementById('ai-assistant-send-test-results'),
        };
    }

    // ===================== 复制按钮 =====================

    function initCopyButtonDelegation() {
        if (initCopyButtonDelegation._bound) {
            return;
        }
        initCopyButtonDelegation._bound = true;
        document.addEventListener('click', function(e) {
            // Handle retry button clicks.
            var retryBtn = e.target.closest('.ai-retry-btn');
            if (retryBtn) {
                var msg = retryBtn.dataset.retryMessage;
                if (msg) {
                    var retryAction = retryBtn.dataset.retryAction;
                    if (retryAction) {
                        setActiveStrategy(retryAction);
                    }
                    var retryQ = retryBtn.dataset.retryQuestion;
                    if (retryQ !== undefined) {
                        currentQuestionIndex = parseInt(retryQ, 10);
                    }
                    if (dom.input) {
                        dom.input.value = msg;
                    }
                    sendMessage();
                }
                return;
            }

            var copyBtn = e.target.closest('.ai-code-copy');
            if (copyBtn) {
                var block = copyBtn.closest('.ai-code-block');
                if (!block) {
                    return;
                }
                var code = block.querySelector('code');
                if (!code) {
                    return;
                }
                if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                    navigator.clipboard.writeText(code.textContent).then(function() {
                        copyBtn.textContent = getString('copied');
                        setTimeout(function() {
                            copyBtn.textContent = getString('copy');
                        }, COPY_FEEDBACK_DELAY);
                    }).catch(function() {
                        fallbackCopy(code.textContent, copyBtn);
                    });
                } else {
                    fallbackCopy(code.textContent, copyBtn);
                }
                return;
            }

            var foldBtn = e.target.closest('.ai-code-fold');
            if (foldBtn) {
                var block = foldBtn.closest('.ai-code-block');
                if (!block) {
                    return;
                }
                block.classList.toggle('ai-code-block-folded');
                var icon = foldBtn.querySelector('i');
                if (block.classList.contains('ai-code-block-folded')) {
                    foldBtn.title = getString('expand');
                    if (icon) {
                        icon.className = 'fa fa-chevron-down';
                    }
                } else {
                    foldBtn.title = getString('collapse');
                    if (icon) {
                        icon.className = 'fa fa-chevron-up';
                    }
                }
            }
        });
    }

    /** @type {HTMLTextAreaElement|null} 复用的剪贴板 textarea（fallbackCopy 缓存） */
    var _copyTextarea = null;

    function fallbackCopy(text, btn) {
        if (!_copyTextarea) {
            _copyTextarea = document.createElement('textarea');
            _copyTextarea.style.position = 'fixed';
            _copyTextarea.style.left = '-9999px';
            _copyTextarea.style.top = '0';
            _copyTextarea.style.opacity = '0';
            _copyTextarea.style.pointerEvents = 'none';
        }
        _copyTextarea.value = text;
        document.body.appendChild(_copyTextarea);
        _copyTextarea.select();
        try {
            var successful = document.execCommand('copy');
            if (successful) {
                btn.textContent = getString('copied');
                setTimeout(function() {
                    btn.textContent = getString('copy');
                }, COPY_FEEDBACK_DELAY);
            }
        } catch (err) {
            // eslint-disable-next-line no-console
            console.warn('[AI Programming Assistant] Copy failed:', err);
        }
        document.body.removeChild(_copyTextarea);
    }

    // ===================== 策略按钮 =====================

    function initStrategyButtons() {
        strategyBtns = document.querySelectorAll('.ai-strategy-btn');
        backBtn = document.querySelector('.ai-back-btn');
        freeBtn = document.querySelector('.ai-strategy-btn[data-action="free"]');
        strategyItems = document.querySelectorAll('.ai-strategy-btn[data-action="question_analysis"], .ai-strategy-btn[data-action="idea_organization"], .ai-strategy-btn[data-action="code_debugging"]');
        strategyBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var action = btn.getAttribute('data-action');
                if (action === 'back') {
                    exitStrategyMode();
                    return;
                }
                setActiveStrategy(action);
            });
        });
    }

    /**
     * 在试题页面且未选择策略模式时，在聊天框中显示提示并高亮策略栏
     * 去重：若最后一条消息已是策略提示，仅重新高亮策略栏，不重复添加
     */
    function showStrategySelectionHint() {
        if (dom.log) {
            // 检查最后一条消息是否已是策略提示，避免重复堆积
            var lastChild = dom.log.lastElementChild;
            if (!lastChild || !lastChild.classList.contains('ai-message-system')) {
                var hintMsg = document.createElement('div');
                hintMsg.className = 'ai-message ai-message-system';
                var bubble = document.createElement('div');
                bubble.className = 'ai-msg-bubble ai-msg-bubble-system';
                bubble.textContent = getString('strategy_select_reminder', '请先点击上方的策略模式按钮（题目分析/思路整理/代码调试）开始分析');
                hintMsg.appendChild(bubble);
                dom.log.appendChild(hintMsg);
                scrollToBottom(dom.log);
            }
        }
        // 高亮策略栏，引导用户点击
        var strategyBar = document.querySelector('.ai-assistant-strategy-bar');
        if (strategyBar) {
            strategyBar.classList.add('ai-strategy-bar-highlight');
            setTimeout(function() {
                strategyBar.classList.remove('ai-strategy-bar-highlight');
            }, 2000);
        }
    }

    /**
     * 退出策略对话模式，恢复显示策略按钮
     *
     * 性能优化：
     * - 优先从 DOM 缓存恢复自由对话界面（零渲染开销）
     * - 保存当前策略的 DOM 缓存供后续快速恢复
     * - 无缓存时使用 requestAnimationFrame 分批异步渲染
     */
    function exitStrategyMode() {
        if (!inStrategyMode) {
            return;
        }
        var prevMode = currentActionType;
        inStrategyMode = false;
        currentActionType = 'free';

        saveSession();

        // 递增渲染令牌，使所有待处理的渲染批次失效
        renderToken++;

        // 1) 同步更新按钮状态 + 清空日志（轻量操作，不阻塞首帧）
        var hasQuestions = hasCodeRunnerQuestions();
        if (backBtn) {
            backBtn.classList.add('ai-back-btn-hidden');
        }
        if (strategyBtns) {
            strategyBtns.forEach(function(btn) {
                var action = btn.getAttribute('data-action');
                if (action === 'back') {
                    return;
                }
                btn.classList.toggle('ai-strategy-active', action === 'free');
                var cfg = getStrategyConfig(action);
                var needDisable = cfg.requiresQuestion && !hasQuestions;
                btn.disabled = needDisable;
                btn.classList.toggle('ai-strategy-disabled', needDisable);
            });
        }
        if (hasQuestions) {
            if (freeBtn) { freeBtn.classList.add('ai-strategy-item-hidden'); }
            if (strategyItems) { strategyItems.forEach(function(b) { b.classList.remove('ai-strategy-item-hidden'); }); }
        } else {
            if (freeBtn) { freeBtn.classList.remove('ai-strategy-item-hidden'); }
            if (strategyItems) { strategyItems.forEach(function(b) { b.classList.add('ai-strategy-item-hidden'); }); }
        }
        if (dom.strategyOptions) { dom.strategyOptions.classList.add('ai-strategy-options-hidden'); }
        if (dom.input) { dom.input.placeholder = config[STRATEGY_CONFIG['free'].placeholderKey] || STRATEGY_CONFIG['free'].placeholderFallback; }

        // 保存当前策略模式的 DOM 缓存（移动节点而非销毁，保留渲染状态）
        saveDomCache(prevMode);

        // 优先从 DOM 缓存恢复自由对话界面（零渲染开销）
        if (restoreDomCache('free')) {
            return;
        }

        // 无缓存时从历史记录渲染
        if (dom.log) {
            dom.log.textContent = '';
        }

        // 2) 准备异步渲染
        var freeHistory = chatHistoryByMode['free'];
        var freeConvId = conversationIdByMode['free'] || 0;
        var mySeq = ++strategyRequestSeq;
        var myRenderToken = renderToken;

        if (!freeHistory || freeHistory.length === 0) {
            return;
        }

        // 预计算批次大小（纯数组遍历，无 DOM 访问）
        var LARGE_MSG_THRESHOLD = 3000;
        var hasLargeMessages = false;
        for (var j = 0; j < freeHistory.length; j++) {
            var entry = freeHistory[j];
            if (!entry) { continue; }
            var msgLen = entry.message ? entry.message.length : 0;
            var htmlLen = entry.renderedHtml ? entry.renderedHtml.length : 0;
            if (msgLen > LARGE_MSG_THRESHOLD || htmlLen > LARGE_MSG_THRESHOLD * 2) {
                hasLargeMessages = true;
                break;
            }
        }
        var BATCH_SIZE = hasLargeMessages ? 1 : 4;

        var userName = config.userName || defaultStrings.me;
        var currentIndex = 0;
        var restoredHistory = [];

        // 预检查：freeHistory 是否所有条目都有 renderedHtml
        var allFreeRendered = true;
        for (var k = 0; k < freeHistory.length; k++) {
            if (!freeHistory[k] || !freeHistory[k].renderedHtml) {
                allFreeRendered = false;
                break;
            }
        }
        if (allFreeRendered) {
            BATCH_SIZE = Math.min(20, freeHistory.length);
        }

        // 3) 使用 requestAnimationFrame 在下一帧渲染首批消息
        // （日志已同步清空，浏览器可立即重绘空白状态）
        requestAnimationFrame(function() {
            if (mySeq !== strategyRequestSeq || !dom.log) {
                setRenderingHistory(false);
                return;
            }
            if (myRenderToken !== renderToken) {
                setRenderingHistory(false);
                return;
            }

            setRenderingHistory(true);

            var t0 = performance.now();

            // 立即渲染首批消息
            renderBatch();

            function renderBatch() {
                if (mySeq !== strategyRequestSeq || !dom.log) {
                    setRenderingHistory(false);
                    return;
                }
                if (myRenderToken !== renderToken) {
                    setRenderingHistory(false);
                    return;
                }

                var endIndex = Math.min(currentIndex + BATCH_SIZE, freeHistory.length);

                if (allFreeRendered) {
                    // 快速路径：所有消息已有 renderedHtml，单次 innerHTML 批量渲染
                    var htmlParts = [];
                    for (var i = currentIndex; i < endIndex; i++) {
                        var entry = freeHistory[i];
                        if (!entry || !entry.user || !entry.message) { continue; }
                        var type = (entry.user === userName) ? 'user' : 'bot';
                        var senderHtml = (type === 'user' || type === 'bot')
                            ? '<div class="ai-msg-sender">' + escapeHtml(entry.user) + '</div>' : '';
                        htmlParts.push(
                            '<div class="ai-message ' + type + '">' +
                            senderHtml +
                            '<div class="ai-message-content">' + sanitizeRenderedHtml(entry.renderedHtml) + '</div>' +
                            '</div>'
                        );
                        restoredHistory.push({user: entry.user, message: entry.message, renderedHtml: entry.renderedHtml});
                    }
                    dom.log.insertAdjacentHTML('beforeend', htmlParts.join(''));
                } else {
                    var fragment = document.createDocumentFragment();
                    for (var i = currentIndex; i < endIndex; i++) {
                        var entry = freeHistory[i];
                        if (!entry || !entry.user || !entry.message) { continue; }
                        var type = (entry.user === userName) ? 'user' : 'bot';
                        if (entry.renderedHtml) {
                            var template = document.createElement('template');
                            template.innerHTML = sanitizeRenderedHtml(entry.renderedHtml);
                            var msgDiv = document.createElement('div');
                            msgDiv.className = 'ai-message ' + type;
                            msgDiv.appendChild(template.content.cloneNode(true));
                            fragment.appendChild(msgDiv);
                            restoredHistory.push({user: entry.user, message: entry.message, renderedHtml: entry.renderedHtml});
                        } else if (cachedModules.stream) {
                            var rendered = addMessageToLog(type, entry.message, false, fragment);
                            var renderedHtml = '';
                            if (rendered) {
                                var cEl = rendered.querySelector('.ai-message-content');
                                if (cEl) { renderedHtml = cEl.innerHTML; }
                            }
                            restoredHistory.push({user: entry.user, message: entry.message, renderedHtml: renderedHtml});
                        } else {
                            restoredHistory.push({user: entry.user, message: entry.message, renderedHtml: ''});
                        }
                    }
                    dom.log.appendChild(fragment);
                }

                currentIndex = endIndex;

                chatHistoryByMode['free'] = restoredHistory;
                if (freeConvId > 0) {
                    conversationIdByMode['free'] = freeConvId;
                }

                if (currentIndex < freeHistory.length) {
                    if (hasUnrendered(freeHistory)) {
                        ensureModules(function() {
                            if (mySeq !== strategyRequestSeq || myRenderToken !== renderToken) {
                                setRenderingHistory(false);
                                return;
                            }
                            requestAnimationFrame(renderBatch);
                        });
                    } else {
                        requestAnimationFrame(renderBatch);
                    }
                } else {
                    setRenderingHistory(false);
                    scrollToBottom(dom.log);
                    // 恢复完成：补齐恢复期间被跳过（isRenderingHistory 短路）的 KaTeX 渲染，
                    // 否则历史消息中的 $...$/\[...\] 停留在原始文本不显示为公式。
                    if (cachedModules.stream) {
                        cachedModules.stream.renderMath(dom.log);
                    }
                    var t1 = performance.now();
                    if (t1 - t0 > 50) {
                        // eslint-disable-next-line no-console
                        console.warn('[AI Assistant] exitStrategyMode rendering took:', Math.round(t1 - t0) + 'ms');
                    }
                }
            }
        });
    }

    // 辅助函数：检查历史中是否有未渲染的消息
    function hasUnrendered(history) {
        if (!history) return false;
        for (var i = 0; i < history.length; i++) {
            if (history[i] && !history[i].renderedHtml) {
                return true;
            }
        }
        return false;
    }

    // ===================== DOM 缓存（策略切换性能优化） =====================

    /**
     * 将当前日志 DOM 移入离线缓存容器（移动节点，保留 KaTeX/Prism/MathJax 渲染状态）
     * 缓存容器为离屏隐藏 div，不触发 reflow
     * @param {string} mode 策略模式标识
     */
    function saveDomCache(mode) {
        if (!dom.log || !mode || dom.log.children.length === 0) { return; }
        scrollCacheByMode[mode] = dom.log.scrollTop;
        var container = domCacheByMode[mode];
        if (!container) {
            container = document.createElement('div');
            container.style.display = 'none';
            domCacheByMode[mode] = container;
        } else {
            // 复用已有容器，清空旧内容
            container.textContent = '';
        }
        // 使用 DocumentFragment 批量转移节点
        var frag = document.createDocumentFragment();
        while (dom.log.firstChild) {
            frag.appendChild(dom.log.firstChild);
        }
        container.appendChild(frag);
    }

    /**
     * 从缓存恢复指定模式的 DOM 到日志容器（O(1) 节点交换，零渲染开销）
     * 当前日志内容会先保存到其对应模式的缓存中
     * @param {string} mode 策略模式标识
     * @returns {boolean} 是否成功恢复
     */
    function restoreDomCache(mode) {
        if (!dom.log || !mode) { return false; }
        var container = domCacheByMode[mode];
        if (!container || container.children.length === 0) { return false; }
        // 使用 DocumentFragment 批量移动节点，将 N 次 appendChild（N 次回流）合并为 1 次
        var frag = document.createDocumentFragment();
        while (container.firstChild) {
            frag.appendChild(container.firstChild);
        }
        dom.log.appendChild(frag);
        if (scrollCacheByMode[mode] !== undefined) {
            dom.log.scrollTop = scrollCacheByMode[mode];
        }
        return true;
    }

    /**
     * 清除指定模式的 DOM 缓存（刷新/切换试题时调用）
     * @param {string} mode 策略模式标识
     */
    function clearDomCache(mode) {
        var container = domCacheByMode[mode];
        if (container) {
            container.textContent = '';
        }
        delete scrollCacheByMode[mode];
    }

    /**
     * 进入策略对话模式，隐藏策略按钮并显示返回按钮
     */
    function enterStrategyMode() {
        inStrategyMode = true;
        saveSession();
        // 递增渲染令牌，使所有待处理的渲染批次失效
        renderToken++;
        // 使用缓存的 strategyBtns，避免重复查询 DOM
        var btns = strategyBtns;
        if (!btns || btns.length === 0) {
            btns = document.querySelectorAll('.ai-strategy-btn');
        }
        // 隐藏自由对话按钮和策略按钮
        btns.forEach(function(btn) {
            var action = btn.getAttribute('data-action');
            if (action !== 'back') {
                btn.classList.add('ai-strategy-item-hidden');
            }
        });
        // 显示返回按钮
        if (backBtn) {
            backBtn.classList.remove('ai-back-btn-hidden');
        }
    }

    /**
     * 检查当前页面是否存在 CodeRunner 类型试题
     * 用于决定是否显示策略模式按钮（题目分析/思路整理/代码调试）
     *
     * 检测策略：
     * 1. 优先检查已提取的 questionsContext 中的 isCodeRunner 标志（利用缓存）
     * 2. 回退到直接 DOM 检查（questionsContext 未提取或可能过期时）
     *
     * @returns {boolean}
     */
    function hasCodeRunnerQuestions() {
        // 优先使用已提取的 questionsContext 中的 isCodeRunner 标志
        if (questionsContext && questionsContext.length > 0) {
            for (var i = 0; i < questionsContext.length; i++) {
                if (questionsContext[i] && questionsContext[i].isCodeRunner) {
                    return true;
                }
            }
        }
        // 直接 DOM 检查：CodeRunner 题型在 .que 元素上添加 'coderunner' 类
        // 填空类题型 coderunner_fillblank / codefillinblank 效果同 CodeRunner，一并识别
        // 补充检查 CodeRunner/填空题型特有 DOM 元素作为回退
        return document.querySelectorAll(
            '.que.coderunner, .que.coderunner_fillblank, .que.codefillinblank, ' +
            '.que .coderunner-test-results, .que .coderunner-examples, .que .codefillinblank-outcome'
        ).length > 0;
    }

    /**
     * 更新策略栏可见性：根据页面类型显示/隐藏策略按钮
     * - CodeRunner 试题页面：显示策略按钮（题目分析/思路整理/代码调试），隐藏自由对话按钮
     * - 其他页面（非试题或非 CodeRunner 试题）：只显示自由对话按钮
     */
    function updateStrategyBarVisibility() {
        var hasQuestions = hasCodeRunnerQuestions();

        if (hasQuestions) {
            // 试题页面：显示策略按钮，隐藏自由对话按钮
            if (freeBtn) {
                freeBtn.classList.add('ai-strategy-item-hidden');
            }
            strategyItems.forEach(function(btn) {
                btn.classList.remove('ai-strategy-item-hidden');
            });
        } else {
            // 非试题页面：只显示自由对话按钮，隐藏策略按钮
            if (freeBtn) {
                freeBtn.classList.remove('ai-strategy-item-hidden');
            }
            strategyItems.forEach(function(btn) {
                btn.classList.add('ai-strategy-item-hidden');
            });
        }
    }

    function setActiveStrategy(action) {

        var strategyCfg = getStrategyConfig(action);

        // 异常路径修复：当前已处于策略模式却切换到自由模式时
        // （Escape 收起面板后重新展开、需试题的策略在无试题页面回退、刷新恢复
        // 会话时面板已收起等），必须先调用 exitStrategyMode() 恢复按钮可见性并
        // 复位 inStrategyMode。否则 inStrategyMode 残留 true 会导致策略按钮
        // 持续隐藏、自由模式发送被拦截，界面"卡死"在策略模式 UI。
        if (action === 'free' && inStrategyMode) {
            exitStrategyMode();
        }

        // 保存切换前的模式，用于 DOM 缓存
        var prevMode = currentActionType;
        // 标记当前策略类型
        currentActionType = action;
        saveSession();

        // 先同步更新按钮状态（轻量操作）
        var hasQuestions = hasCodeRunnerQuestions();
        if (strategyBtns) {
            strategyBtns.forEach(function(btn) {
                var btnAction = btn.getAttribute('data-action');
                btn.classList.toggle('ai-strategy-active', btnAction === action);
                if (btnAction !== 'back') {
                    var cfg = getStrategyConfig(btnAction);
                    var needDisable = cfg.requiresQuestion && !hasQuestions;
                    btn.disabled = needDisable;
                    btn.classList.toggle('ai-strategy-disabled', needDisable);
                }
            });
        }

        // 同步更新选项可见性（轻量操作）
        if (dom.strategyOptions) {
            var showOptions = strategyCfg.showStudentAnswerOption || strategyCfg.showTestResultsOption;
            dom.strategyOptions.classList.toggle('ai-strategy-options-hidden', !showOptions);
        }
        if (dom.sendStudentCode) { dom.sendStudentCode.checked = strategyCfg.defaultSendStudentCode; }
        if (dom.sendTestResults) { dom.sendTestResults.checked = strategyCfg.defaultSendTestResults; }
        if (dom.input) { dom.input.placeholder = config[strategyCfg.placeholderKey] || strategyCfg.placeholderFallback; }

        // 异步处理日志和试题选择器
        var mySeq = ++strategyRequestSeq;
        requestAnimationFrame(function() {
            if (mySeq !== strategyRequestSeq || !dom.log) {
                return;
            }

            // 切换模式时保存上一模式的 DOM 缓存
            if (prevMode !== action) {
                saveDomCache(prevMode);
            }

            // 非自由对话模式时提取试题上下文
            if (action !== 'free') {
                populateQuestionSelector();
            }

            // 如果策略需要 CodeRunner 试题但页面无此类试题，回退到自由模式
            if (strategyCfg.requiresQuestion && !hasCodeRunnerQuestions()) {
                // 必须同步修改 action 变量，否则下方 `action !== 'free'` 仍会进入
                // 策略模式（inStrategyMode=true），造成 currentActionType='free' 与
                // inStrategyMode=true 状态失配：策略按钮隐藏、发送流程走错模式。
                action = 'free';
                currentActionType = 'free';
                // 回退到自由模式时同步恢复自由模式的选项面板与复选框状态，
                // 避免上一策略 showOptions=true 的选项面板残留可见。
                if (dom.strategyOptions) {
                    dom.strategyOptions.classList.add('ai-strategy-options-hidden');
                }
                if (dom.sendStudentCode) { dom.sendStudentCode.checked = STRATEGY_CONFIG['free'].defaultSendStudentCode; }
                if (dom.sendTestResults) { dom.sendTestResults.checked = STRATEGY_CONFIG['free'].defaultSendTestResults; }
                if (dom.input) { dom.input.placeholder = config[STRATEGY_CONFIG['free'].placeholderKey] || STRATEGY_CONFIG['free'].placeholderFallback; }
                // 若已处于策略模式（如重试按钮触发的回退），需完整退出策略模式，
                // 复位 inStrategyMode 并恢复策略按钮可见性，防止界面"卡死"。
                if (inStrategyMode) {
                    exitStrategyMode();
                }
            }

            // 更新按钮可见性（仅在首次进入时）
            if (!inStrategyMode) {
                updateStrategyBarVisibility();
            }

            // 进入策略对话模式
            if (action !== 'free') {
                enterStrategyMode();
            }

            // 优先从 DOM 缓存恢复（零渲染开销）
            if (prevMode !== action && restoreDomCache(action)) {
                return;
            }

            // 无缓存时清空日志并从历史记录渲染
            dom.log.textContent = '';
            var qctx = (currentQuestionIndex >= 0 && currentQuestionIndex < questionsContext.length)
                ? questionsContext[currentQuestionIndex] : null;
            loadConversationHistory(action, qctx ? qctx.usageid : 0, qctx ? qctx.slot : 0, mySeq,
                qctx ? qctx.questionid : 0);
        });
    }

    /**
     * 从日志数据库加载指定策略模式的对话历史记录
     *
     * 调用外部服务获取当前用户在对应模式下的历史对话，
     * 按时间从远到近的顺序呈现在前端对话框，并构建历史对话记录。
     * 当指定 usageid/slot 时，过滤该试题的历史（用于多试题页面切换试题）。
     *
     * 优化：
     * - 本地缓存有效时跳过 AJAX 调用，防止快速切换时累积请求
     * - AJAX 回调使用 addMessageToLog 异步分批渲染，保留 KB 链接、KaTeX、Prism 高亮
     *
     * @param {string} action 策略模式标识
     * @param {number} [usageid] 试题用法 ID（切换试题时传入）
     * @param {number} [slot] 试题插槽号（切换试题时传入）
     * @param {number} [requestSeq] 策略切换请求序列号（用于丢弃过期响应）
     * @param {number} [questionid] 题库题目 ID（嵌入题页面无 usageid/slot，直接传入）
     */
    function loadConversationHistory(action, usageid, slot, requestSeq, questionid) {
        // 快速路径：本地已有缓存的会话，先渲染让 UI 立即响应
        var localHistory = chatHistoryByMode[action];
        var localConvId = conversationIdByMode[action] || 0;

        // 早返回：本地缓存有效且已完全渲染时跳过 AJAX
        var skipAjax = false;
        if (localHistory && localHistory.length > 0) {
            // 检查是否所有条目都有 renderedHtml（表示已完整渲染）
            var allRendered = true;
            for (var i = 0; i < localHistory.length; i++) {
                if (!localHistory[i] || !localHistory[i].renderedHtml) {
                    allRendered = false;
                    break;
                }
            }
            if (allRendered && localConvId > 0) {
                // 本地缓存完全有效，直接使用
                restoreLocalConversation(action, localHistory.slice(), localConvId, requestSeq);
                skipAjax = true;
            } else {
                // 部分渲染，先显示缓存，后续 AJAX 会补充
                restoreLocalConversation(action, localHistory.slice(), localConvId, requestSeq);
            }
        }

        if (skipAjax) {
            return;
        }

        var args = {
            actiontype: action,
            contextid: config.contextid || 0,
            quizid: config.quizid || 0,
            usageid: usageid || 0,
            slot: slot || 0,
            questionid: questionid || 0,
            conversationid: localConvId
        };

        var request = Ajax.call([{
            methodname: 'local_ai_programming_assistant_get_conversation_history',
            args: args
        }])[0];

        request.done(function(response) {
            // 检查请求是否仍然有效（防止竞态条件）
            if (requestSeq !== strategyRequestSeq) {
                return;
            }
            if (!dom.log) {
                return;
            }

            // 如果服务器返回了有效消息，异步分批渲染
            if (response && response.messages && response.messages.length > 0) {
                var messages = response.messages;
                var userName = config.userName || defaultStrings.me;
                var assistantName = config.assistantName || defaultStrings.ai_sender;

                if (response.conversationid) {
                    conversationIdByMode[action] = response.conversationid;
                }

                // 递增渲染令牌，使本地缓存的待处理批次失效
                // （restoreLocalConversation 的批次会在检查令牌后自动停止）
                renderToken++;

                setRenderingHistory(true);

                // 清空日志，准备渲染服务器数据
                dom.log.textContent = '';

                // 构建完整的历史条目列表
                var fullHistory = [];
                for (var i = 0; i < messages.length; i++) {
                    var msg = messages[i];
                    fullHistory.push({user: userName, message: msg.usermessage, renderedHtml: ''});
                    if (msg.airesponse) {
                        fullHistory.push({user: assistantName, message: msg.airesponse, renderedHtml: ''});
                    }
                }

                // 异步分批渲染
                var SMALL_THRESHOLD = 2000;
                var LARGE_THRESHOLD = 8000;
                var hasVeryLarge = false;
                var hasLarge = false;
                for (var j = 0; j < fullHistory.length; j++) {
                    var entry = fullHistory[j];
                    if (!entry) continue;
                    var size = entry.message ? entry.message.length : 0;
                    if (size > LARGE_THRESHOLD) {
                        hasVeryLarge = true;
                        break;
                    } else if (size > SMALL_THRESHOLD) {
                        hasLarge = true;
                    }
                }
                var BATCH_SIZE = hasVeryLarge ? 1 : (hasLarge ? 2 : 5);
                var myRenderToken = renderToken;

                var currentIndex = 0;
                var renderedHistory = [];

                function renderBatch() {
                    if (requestSeq !== strategyRequestSeq || !dom.log) {
                        setRenderingHistory(false);
                        return;
                    }
                    if (myRenderToken !== renderToken) {
                        setRenderingHistory(false);
                        return;
                    }

                    var fragment = document.createDocumentFragment();
                    var endIndex = Math.min(currentIndex + BATCH_SIZE, fullHistory.length);

                    for (var i = currentIndex; i < endIndex; i++) {
                        var entry = fullHistory[i];
                        if (!entry || !entry.message) {
                            continue;
                        }

                        var type = (entry.user === userName) ? 'user' : 'bot';
                        var rendered = addMessageToLog(type, entry.message, false, fragment);
                        var renderedHtml = '';
                        if (rendered) {
                            var cEl = rendered.querySelector('.ai-message-content');
                            if (cEl) { renderedHtml = cEl.innerHTML; }
                        }
                        renderedHistory.push({user: entry.user, message: entry.message, renderedHtml: renderedHtml});
                    }

                    dom.log.appendChild(fragment);
                    currentIndex = endIndex;

                    // 更新缓存
                    chatHistoryByMode[action] = renderedHistory;
                    if (response.conversationid) {
                        conversationIdByMode[action] = response.conversationid;
                    }

                    if (currentIndex < fullHistory.length) {
                        requestAnimationFrame(renderBatch);
                    } else {
                        setRenderingHistory(false);
                        scrollToBottom(dom.log);
                        // 恢复完成：补齐恢复期间被跳过（isRenderingHistory 短路）的 KaTeX 渲染。
                        if (cachedModules.stream) {
                            cachedModules.stream.renderMath(dom.log);
                        }
                    }
                }

                // 首帧立即渲染首批消息（日志已清空，无需再等一帧）
                if (cachedModules.stream) {
                    renderBatch();
                } else {
                    ensureModules(function() {
                        if (requestSeq !== strategyRequestSeq || myRenderToken !== renderToken) {
                            setRenderingHistory(false);
                            return;
                        }
                        renderBatch();
                    });
                }
                return;
            }

            // 服务器返回空消息 — 本地缓存已在函数开头渲染，无需再次处理
        }).fail(function(error) {
            // eslint-disable-next-line no-console
            console.warn('[AI Programming Assistant] Failed to load conversation history for mode:', action, error);
        });
    }

    /**
     * 从本地缓存恢复指定模式的会话内容
     *
     * 优化：
     * - 优先使用已缓存的 renderedHtml（已解析的 HTML），跳过重复的 Markdown/LaTeX/代码高亮处理
     * - 如果所有条目都有 renderedHtml，完全跳过 ensureModules，避免加载 stream 模块
     * - 对于没有 renderedHtml 的旧条目，使用 addMessageToLog 完整渲染，并立即保存 renderedHtml
     * - 使用增量渲染（分批处理），避免一次性大量 DOM 操作阻塞主线程
     * - 对于代码调试模式等包含大型消息的场景，使用更小的批次和异步渲染
     */
    function restoreLocalConversation(action, history, convId, requestSeq) {
        if (!dom.log || !history || history.length === 0) {
            return;
        }

        var userName = config.userName || defaultStrings.me;
        var myRenderToken = renderToken;

        setRenderingHistory(true);

        // 检查是否所有条目都有 renderedHtml（可完全跳过 ensureModules）
        var allHaveRenderedHtml = true;
        for (var i = 0; i < history.length; i++) {
            if (!history[i] || !history[i].renderedHtml) {
                allHaveRenderedHtml = false;
                break;
            }
        }

        // 动态调整批次大小：更智能的大型消息检测
        var SMALL_MSG_THRESHOLD = 2000;
        var LARGE_MSG_THRESHOLD = 8000;
        var hasVeryLargeMessages = false;
        var hasLargeMessages = false;

        for (var j = 0; j < history.length; j++) {
            var entry = history[j];
            if (!entry) continue;
            var msgSize = 0;
            if (entry.renderedHtml) {
                msgSize = entry.renderedHtml.length;
            } else if (entry.message) {
                msgSize = entry.message.length;
            }
            if (msgSize > LARGE_MSG_THRESHOLD) {
                hasVeryLargeMessages = true;
                break;
            } else if (msgSize > SMALL_MSG_THRESHOLD) {
                hasLargeMessages = true;
            }
        }

        // 更灵活的批次大小：超大消息1条/批，大消息2条/批，普通消息5条/批
        var BATCH_SIZE = hasVeryLargeMessages ? 1 : (hasLargeMessages ? 2 : 5);
        // 如果所有条目都有 renderedHtml，使用更大的批次（innerHTML 解析比逐条 template 快得多）
        if (allHaveRenderedHtml) {
            BATCH_SIZE = Math.min(20, history.length);
        }

        var currentIndex = 0;
        var restoredHistory = [];

        function renderBatch() {
            if (requestSeq !== strategyRequestSeq || !dom.log) {
                setRenderingHistory(false);
                return;
            }
            if (myRenderToken !== renderToken) {
                setRenderingHistory(false);
                return;
            }

            var endIndex = Math.min(currentIndex + BATCH_SIZE, history.length);

            if (allHaveRenderedHtml) {
                // 快速路径：所有消息已有 renderedHtml，用单次 innerHTML 替代逐条 template 解析
                var htmlParts = [];
                for (var i = currentIndex; i < endIndex; i++) {
                    var entry = history[i];
                    if (!entry || !entry.user || !entry.message) { continue; }
                    var type = (entry.user === userName) ? 'user' : 'bot';
                    var senderHtml = (type === 'user' || type === 'bot')
                        ? '<div class="ai-msg-sender">' + escapeHtml(entry.user) + '</div>' : '';
                    htmlParts.push(
                        '<div class="ai-message ' + type + '">' +
                        senderHtml +
                        '<div class="ai-message-content">' + sanitizeRenderedHtml(entry.renderedHtml || escapeHtml(entry.message)) + '</div>' +
                        '</div>'
                    );
                    restoredHistory.push({user: entry.user, message: entry.message, renderedHtml: entry.renderedHtml});
                }
                dom.log.insertAdjacentHTML('beforeend', htmlParts.join(''));
            } else {
                var fragment = document.createDocumentFragment();
                for (var i = currentIndex; i < endIndex; i++) {
                    var entry = history[i];
                    if (!entry || !entry.user || !entry.message) { continue; }
                    var type = (entry.user === userName) ? 'user' : 'bot';
                    if (entry.renderedHtml) {
                        var template = document.createElement('template');
                        template.innerHTML = sanitizeRenderedHtml(entry.renderedHtml);
                        var msgDiv = document.createElement('div');
                        msgDiv.className = 'ai-message ' + type;
                        msgDiv.appendChild(template.content.cloneNode(true));
                        fragment.appendChild(msgDiv);
                        restoredHistory.push({user: entry.user, message: entry.message, renderedHtml: entry.renderedHtml});
                    } else {
                        var rendered = addMessageToLog(type, entry.message, false, fragment);
                        var renderedHtml = '';
                        if (rendered) {
                            var cEl = rendered.querySelector('.ai-message-content');
                            if (cEl) { renderedHtml = cEl.innerHTML; }
                        }
                        restoredHistory.push({user: entry.user, message: entry.message, renderedHtml: renderedHtml});
                    }
                }
                dom.log.appendChild(fragment);
            }

            currentIndex = endIndex;

            // 更新缓存
            chatHistoryByMode[action] = restoredHistory;
            if (convId > 0) {
                conversationIdByMode[action] = convId;
            }

            if (currentIndex < history.length) {
                requestAnimationFrame(renderBatch);
            } else {
                setRenderingHistory(false);
                scrollToBottom(dom.log);
                // 恢复完成：补齐恢复期间被跳过（isRenderingHistory 短路）的 KaTeX 渲染。
                if (cachedModules.stream) {
                    cachedModules.stream.renderMath(dom.log);
                }
            }
        }

        // 首帧立即渲染首批消息（日志已由调用方清空，无需再等一帧）
        if (allHaveRenderedHtml) {
            // 所有消息都有 renderedHtml，直接同步渲染首批
            renderBatch();
        } else {
            // 需要 ensureModules，加载完成后立即渲染首批
            ensureModules(function() {
                if (requestSeq !== strategyRequestSeq || myRenderToken !== renderToken) {
                    setRenderingHistory(false);
                    return;
                }
                renderBatch();
            });
        }
    }

    // ===================== 题目选择器 =====================

    function populateQuestionSelector(forceRefresh) {
        ensureModules(function(Stream, Context) {
            if (!Stream || !Context) {
                return;
            }
            // 仅在首次加载或强制刷新时重新提取 DOM，策略切换时复用已有缓存
            if (forceRefresh || domQuestionsContext.length === 0) {
                domQuestionsContext = Context.extractAllQuestionsContext();
            }
            // 嵌入题（tiny_embedquestion）不在 DOM 中，需由后端异步提取，
            // 拉取完成后（含失败）再合并渲染，避免阻塞首屏
            loadEmbeddedQuestions(function() {
                renderQuestionSelector();
                // 嵌入题合并后 questionsContext 才包含其 isCodeRunner 标志，
                // 需重新计算策略栏可见性（首次加载与 ensureModules 回调存在竞态，
                // 此处兜底保证含 CodeRunner 嵌入题的页面能正确开启策略模式）。
                if (!inStrategyMode) {
                    updateStrategyBarVisibility();
                }
            });
        });
    }

    /**
     * 通过外部服务拉取当前页面的嵌入题（tiny_embedquestion）列表。
     *
     * mod/page 等页面正文中的嵌入题没有 .que DOM 结构，前端无法提取，
     * 只能由后端解析页面内容，返回题面纯文本供选择器展示与发送。
     *
     * @param {Function} [callback] 拉取结束（成功或失败）后的回调
     */
    function loadEmbeddedQuestions(callback) {
        // 已拉取成功或正在拉取：直接回调，避免重复请求
        if (embeddedQuestionsLoaded) {
            if (callback) {
                callback();
            }
            return;
        }
        embeddedQuestionsLoaded = true;
        var request = Ajax.call([{
            methodname: 'local_ai_programming_assistant_get_embedded_questions',
            args: {contextid: config.contextid || 0}
        }])[0];
        request.done(function(response) {
            var list = (response && response.questions) ? response.questions : [];
            embeddedQuestions = [];
            for (var i = 0; i < list.length; i++) {
                var item = list[i] || {};
                // 结构与 DOM 提取的题目上下文保持一致，便于统一下标访问；
                // 嵌入题无 .que 元素，故 usageid/slot 恒为 0，改用 questionid 标识
                embeddedQuestions.push({
                    questiontitle: (item.name || '').trim(),
                    questiontext: item.questiontext || '',
                    // 嵌入题的作答数据（学生代码、运行反馈/测试结果、编程语言）由后端
                    // 从答题会话中还原，随题目列表一并返回，前端直接携带发送。
                    studentanswer: item.studentanswer || '',
                    testresults: item.testresults || '',
                    programminglanguage: item.programminglanguage || '',
                    questionid: parseInt(item.questionid, 10) || 0,
                    qtype: item.qtype || '',
                    usageid: 0,
                    slot: 0,
                    // 嵌入题无 .que DOM，不能像 DOM 试题那样靠 CSS 类识别，
                    // 只能依据题库题型标识判断是否为 CodeRunner 系列（含代码填空题），
                    // 使这类嵌入题页面的策略模式（题目分析/思路整理/代码调试）正常开启。
                    isCodeRunner: ['coderunner', 'coderunner_fillblank', 'codefillinblank']
                        .indexOf((item.qtype || '').toLowerCase()) !== -1,
                    isEmbedded: true
                });
            }
            if (callback) {
                callback();
            }
        });
        request.fail(function() {
            // 拉取失败不阻塞页面：复位标记以便后续重试，选择器仅展示 DOM 试题
            embeddedQuestionsLoaded = false;
            if (callback) {
                callback();
            }
        });
    }

    /**
     * 合并 DOM 试题与嵌入题（DOM 在前、嵌入题在后）。
     *
     * 嵌入题的题号按合并后的位置重新编号，避免与 DOM 试题题号重复，
     * 合并结果写入 questionsContext 并返回。
     *
     * @returns {Array<Object>} 合并后的题目上下文列表
     */
    function mergeQuestionContexts() {
        questionsContext = domQuestionsContext.concat(embeddedQuestions);
        for (var i = domQuestionsContext.length; i < questionsContext.length; i++) {
            questionsContext[i].number = (i + 1).toString();
        }
        return questionsContext;
    }

    /**
     * 合并 DOM 试题与嵌入题后渲染题目选择器。
     * 合并结果写入 questionsContext，其索引即为 option 的 value。
     */
    function renderQuestionSelector() {
        if (!dom.questionSelect) {
            return;
        }
        mergeQuestionContexts();
        var select = dom.questionSelect;
        var prevIndex = currentQuestionIndex;
        var hasEmbedded = embeddedQuestions.length > 0;
        // 存在嵌入题时追加"全部嵌入题"选项（哨兵值 ALL_EMBEDDED_VALUE）
        var expectedCount = questionsContext.length + (hasEmbedded ? 1 : 0);

        if (questionsContext.length === 0) {
            select.innerHTML = '<option value="-1">' + escapeHtml(config.strNoQuestions || 'No questions found') + '</option>';
            select.disabled = true;
            currentQuestionIndex = -1;
            return;
        }

        // 选项数量与取值形式均未变化时跳过重建（避免不必要的 DOM 操作），仅修复选中状态
        if (select.options.length === expectedCount && select.options.length > 0
                && parseInt(select.options[0].value, 10) === 0) {
            currentQuestionIndex = resolveSelectorValue(select, prevIndex);
            select.value = currentQuestionIndex.toString();
            return;
        }

        select.disabled = false;
        var html = '';
        for (var i = 0; i < questionsContext.length; i++) {
            var qnum = escapeHtml(questionsContext[i].number || (i + 1).toString());
            var title = (questionsContext[i].questiontitle || '').trim();
            if (title) {
                // 截断过长的标题，避免 select 选项过宽
                if (title.length > 60) {
                    title = title.substring(0, 60) + '...';
                }
                html += '<option value="' + i + '">Q' + qnum + ': ' + escapeHtml(title) + '</option>';
            } else {
                // 无标题时仅显示题号
                html += '<option value="' + i + '">Q' + qnum + '</option>';
            }
        }
        if (hasEmbedded) {
            html += '<option value="' + ALL_EMBEDDED_VALUE + '">'
                + escapeHtml(config.strAllEmbeddedQuestions || 'All embedded questions') + '</option>';
        }
        select.innerHTML = html;

        currentQuestionIndex = resolveSelectorValue(select, prevIndex);
        select.value = currentQuestionIndex.toString();
    }

    /**
     * 计算重建选择器后应保持的选中值。
     * 优先保留选择器当前的有效选中项（含"全部嵌入题"），其次沿用上一次索引，
     * 最后回退到第一道题。
     *
     * @param {HTMLSelectElement} select 题目选择器
     * @param {number} prevIndex 重建前的选中索引
     * @returns {number} 选中值（题目索引或 ALL_EMBEDDED_VALUE）
     */
    function resolveSelectorValue(select, prevIndex) {
        var curVal = parseInt(select.value, 10);
        if (!isNaN(curVal) && (curVal === ALL_EMBEDDED_VALUE
                || (curVal >= 0 && curVal < questionsContext.length))) {
            return curVal;
        }
        if (prevIndex >= 0 && prevIndex < questionsContext.length) {
            return prevIndex;
        }
        return 0;
    }

    // ===================== 面板控制 =====================

    function togglePanel() {
        if (!dom.container) {
            return;
        }
        panelExpanded = !panelExpanded;
        if (panelExpanded) {
            dom.container.classList.remove('ai-assistant-collapsed');
            dom.container.classList.add('ai-assistant-expanded');
            // 恢复最小化前的策略模式，如果没有则默认为自由对话
            if (savedStrategyBeforeMinimize) {
                setActiveStrategy(savedStrategyBeforeMinimize);
                savedStrategyBeforeMinimize = null;
            } else {
                setActiveStrategy('free');
            }
            panelFocusSkipPaste = true;
            setTimeout(function() {
                if (dom.input) {
                    dom.input.focus();
                }
            }, 300);
        } else {
            dom.container.classList.remove('ai-assistant-expanded');
            dom.container.classList.add('ai-assistant-collapsed');
        }
        // 同步 body 打开状态（侧边栏模式收起时移除页面 margin，并显示重新打开按钮）
        syncPanelOpenState();
        saveSession();
    }

    // ===================== 消息和重试按钮 =====================

    function addRetryButton(message, actionType, questionIndex) {
        if (!dom.log) {
            return;
        }
        var errorMsgs = dom.log.querySelectorAll('.ai-message.bot.error');
        var lastMsg = errorMsgs.length > 0 ? errorMsgs[errorMsgs.length - 1] : null;
        if (lastMsg) {
            var retryBtn = document.createElement('button');
            retryBtn.className = 'btn btn-sm btn-outline-secondary ai-retry-btn mt-2';
            retryBtn.textContent = getString('retry', 'Retry');
            retryBtn.dataset.retryMessage = message;
            if (actionType) {
                retryBtn.dataset.retryAction = actionType;
            }
            if (questionIndex !== undefined && questionIndex >= 0) {
                retryBtn.dataset.retryQuestion = questionIndex;
            }
            lastMsg.appendChild(retryBtn);
            scrollToBottom(dom.log);
        }
    }

    function removeLoadingMessage() {
        if (!dom.log) {
            return;
        }
        var loading = dom.log.querySelector('.ai-message.loading');
        if (loading) {
            dom.log.removeChild(loading);
        }
    }

    function resetSendState() {
        isSending = false;
        if (abortController) {
            abortController.abort();
            abortController = null;
        }
        if (dom.input) {
            dom.input.disabled = false;
        }
        if (dom.sendBtn) {
            dom.sendBtn.disabled = false;
        }
        if (dom.stopBtn) {
            dom.stopBtn.classList.remove('ai-stop-btn-visible');
            dom.stopBtn.classList.add('ai-stop-btn-hidden');
        }
    }

    // ===================== 消息发送 =====================

    function getSelectedQuestionIndex() {
        var index = -1;
        var label = '';

        if (dom.questionSelect && !dom.questionSelect.disabled) {
            var val = parseInt(dom.questionSelect.value, 10);
            if (!isNaN(val) && val === ALL_EMBEDDED_VALUE) {
                // "全部嵌入题"：视为未指定具体题目，由后端注入全部嵌入题，不显示题号标签
                return {index: -1, label: ''};
            }
            if (!isNaN(val) && val >= 0) {
                index = val;
            }
        }
        if (index < 0 && currentQuestionIndex >= 0) {
            index = currentQuestionIndex;
        }
        if (currentActionType !== 'free' && index < 0 && questionsContext.length > 0) {
            index = 0;
        }
        if (index >= 0 && index < questionsContext.length) {
            var qnum = questionsContext[index].number || (index + 1).toString();
            label = 'Q' + qnum;
        }
        return {index: index, label: label};
    }

    function buildRequestData(message, pageContext, strategyCfg) {
        var history = getCurrentHistory();
        // 前端截断历史：与服务端 max_conversation_turns 对齐，减少序列化和传输开销
        var maxCount = parseInt(config.maxHistoryCount, 10) || 0;
        if (maxCount > 0 && history.length > maxCount) {
            history = history.slice(history.length - maxCount);
        }
        // 使用 JSON.stringify 白名单排除 renderedHtml（服务端不需要），
        // 避免创建临时对象，同时减少约 50%-80% 的传输体积
        var historyJson = JSON.stringify(history, ['user', 'message']);
        var sendQuestiontext = '';
        var sendStudentanswer = '';
        var sendTestresults = '';

        if (strategyCfg.sendFields.indexOf('questiontext') !== -1) {
            sendQuestiontext = pageContext.questiontext;
        }
        if (strategyCfg.sendFields.indexOf('studentanswer') !== -1) {
            if (dom.sendStudentCode) {
                sendStudentanswer = dom.sendStudentCode.checked ? pageContext.studentanswer : '';
            } else {
                sendStudentanswer = pageContext.studentanswer;
            }
        }
        if (strategyCfg.sendFields.indexOf('testresults') !== -1) {
            if (dom.sendTestResults) {
                sendTestresults = dom.sendTestResults.checked ? pageContext.testresults : '';
            } else {
                sendTestresults = pageContext.testresults;
            }
        }

        return {
            message: message,
            history: historyJson,
            questiontext: sendQuestiontext,
            studentanswer: sendStudentanswer,
            testresults: sendTestresults,
            programminglanguage: pageContext.programminglanguage,
            contextid: config.contextid || 0,
            pagetype: config.pagetype || '',
            actiontype: currentActionType,
            questionid: pageContext.questionid || 0,
            usageid: pageContext.usageid || 0,
            slot: pageContext.slot || 0,
            quizid: config.quizid || 0,
            attemptid: config.attemptid || 0,
            conversationid: getCurrentConversationId()
        };
    }

    function sendMessage() {
        if (isSending) {
            return;
        }

        if (!dom.input) {
            return;
        }
        var message = dom.input.value.trim();

        // 在 CodeRunner 试题页面且未选择策略模式时（仍处于 free 模式），提醒用户先选择策略
        if (currentActionType === 'free' && hasCodeRunnerQuestions()) {
            showStrategySelectionHint();
            return;
        }

        // 对话框已有内容时，输入框为空不允许发送（防止重复自动分析）
        // 代码调试策略除外：允许空消息重新触发调试分析
        if (dom.log && dom.log.children.length > 0 && message === '' && currentActionType !== 'code_debugging') {
            return;
        }

        // 非自由模式下允许空消息发送（仅限对话框为空时的首次自动分析或代码调试重新分析）
        if (currentActionType !== 'free' && message === '') {
            // 首次对话（对话框为空）时，不同策略使用不同的默认消息
            var isFirstMessage = !dom.log || dom.log.children.length === 0;
            if (isFirstMessage) {
                switch (currentActionType) {
                    case 'question_analysis':
                        message = config.strDefaultQuestionAnalysisMsg || '我对题目要求不太清楚';
                        break;
                    case 'idea_organization':
                        message = config.strDefaultIdeaOrganizationMsg || '我需要帮助梳理解题思路';
                        break;
                    case 'code_debugging':
                        message = config.strDefaultCodeDebuggingMsg || '我的代码有问题，需要找出原因';
                        break;
                    default:
                        message = ' ';
                }
            } else {
                // 非首次对话时（仅代码调试策略会走到这里）保持空格触发重新分析
                message = ' ';
            }
        }

        if (message === '') {
            return;
        }

        if (message.length > MAX_MESSAGE_LENGTH) {
            message = message.substring(0, MAX_MESSAGE_LENGTH) + '…';
        }

        dom.input.value = '';

        var sel = getSelectedQuestionIndex();
        var questionIndex = sel.index;
        var questionLabel = sel.label;

        var displayMessage = questionLabel ? '[' + questionLabel + '] ' + message : message;
        var strategyCfg = getStrategyConfig(currentActionType);
        if (currentActionType !== 'free') {
            var label = config[strategyCfg.labelKey] || strategyCfg.labelFallback;
            displayMessage = '[' + label + '] ' + displayMessage;
        }
        addMessageToLog('user', displayMessage);

        addMessageToLog('bot loading', '<div class="ai-typing-indicator"><span class="ai-typing-dot"></span><span class="ai-typing-dot"></span><span class="ai-typing-dot"></span></div>');

        isSending = true;
        dom.input.disabled = true;
        if (dom.sendBtn) {
            dom.sendBtn.disabled = true;
        }

        ensureModules(function(Stream, Context) {
            if (!Stream || !Context) {
                // 子模块加载失败：兜底展示错误并复位发送状态，避免输入框永久禁用
                addMessageToLog('bot error', errorString);
                resetSendState();
                return;
            }
            // 优先使用缓存上下文避免全量 DOM 提取，仅在线读取学生代码和测试结果
            var freshContexts = Context.extractAllQuestionsContext(false);
            if (freshContexts.length === 0) {
                // 首次加载：强制全量提取
                freshContexts = Context.extractAllQuestionsContext(true);
            } else if (questionIndex >= 0 && questionIndex < freshContexts.length) {
                // 复用缓存，仅读取当前试题的最新学生代码和测试结果
                // 优化：通过 usageid+slot 精确定位 .que 元素，在其内部查询，
                // 避免跨所有试题的 querySelectorAll + 索引关联（O(1) 替代 O(N)）
                var qctx = freshContexts[questionIndex];
                if (qctx && qctx.usageid > 0 && qctx.slot > 0) {
                    var queEl = document.getElementById('question-' + qctx.usageid + '-' + qctx.slot);
                    if (queEl) {
                        var ta = queEl.querySelector('textarea[name*="answer"], .ace_editor textarea, .CodeMirror textarea');
                        if (ta && ta.value) {
                            qctx.studentanswer = ta.value.trim();
                        }
                        // 填空类题型（coderunner_fillblank / codefillinblank）：从编辑器/代码框架
                        // 重建最新学生代码，避免依赖 textarea 定时同步或缓存过期
                        var freshFillBlank = Context.extractFillBlankAnswer ? Context.extractFillBlankAnswer(queEl) : null;
                        if (freshFillBlank) {
                            qctx.studentanswer = freshFillBlank;
                        }
                        var trEl = queEl.querySelector('.coderunner-test-results');
                        if (trEl && trEl.innerText) {
                            qctx.testresults = trEl.innerText.trim();
                        }
                        var cfOutcome = queEl.querySelector('.codefillinblank-outcome');
                        if (cfOutcome && cfOutcome.innerText) {
                            qctx.testresults = cfOutcome.innerText.trim();
                        }
                    }
                }
            }

            // 记录最新 DOM 试题缓存，并合并后端拉取的嵌入题（无 .que DOM），
            // 使索引与题目选择器保持一致（合并结果同时写入 questionsContext）
            // 先合并再校验：嵌入题（如 coderunner / coderunner_fillblank）的
            // isCodeRunner 标志只在合并后才存在于 questionsContext 中。
            domQuestionsContext = freshContexts;
            freshContexts = mergeQuestionContexts();

            // 防御性检查：如果当前策略需要 CodeRunner 试题但页面无此类试题，阻止发送
            var hasCodeRunnerCtx = false;
            for (var fc = 0; fc < freshContexts.length; fc++) {
                if (freshContexts[fc] && freshContexts[fc].isCodeRunner) { hasCodeRunnerCtx = true; break; }
            }
            if (strategyCfg.requiresQuestion && !hasCodeRunnerCtx) {
                addMessageToLog('bot error', config.strNoQuestions || 'No questions found');
                resetSendState();
                return;
            }

            var pageContext;
            if (questionIndex >= 0 && questionIndex < freshContexts.length) {
                pageContext = freshContexts[questionIndex];
            } else {
                // 未指定具体题目（含"全部嵌入题"选项）：questiontext 留空，
                // 由后端注入页面内全部嵌入题作为题目上下文
                pageContext = {
                    questiontext: '',
                    studentanswer: '',
                    testresults: '',
                    programminglanguage: ''
                };
            }

            if (questionIndex >= 0 && questionIndex < freshContexts.length) {
                currentQuestionIndex = questionIndex;
            }

            var requestData = buildRequestData(message, pageContext, strategyCfg);

            // 记录发送时的请求序号，用于回调中丢弃模式切换/会话重置后的过期响应
            var sendSeq = strategyRequestSeq;

            if (responseMode === 'streaming') {
                handleStreamingSend(Stream, requestData, message, sendSeq);
            } else {
                handleNonStreamingSend(Stream, requestData, message, sendSeq);
            }
        });
    }

    /**
     * 流式响应发送处理（从 sendMessage 提取）
     * @param {Object} Stream stream 模块
     * @param {Object} requestData 请求数据
     * @param {string} message 原始用户消息
     * @param {number} sendSeq 发送时的策略请求序号（用于丢弃过期响应）
     */
    function handleStreamingSend(Stream, requestData, message, sendSeq) {
        var botMessage = dom.log.querySelector('.ai-message.loading');
        var contentEl = null;
        if (botMessage) {
            contentEl = botMessage.querySelector('.ai-message-content');
            botMessage.classList.remove('loading');
            botMessage.classList.add('streaming');
            contentEl.textContent = '';
        }

        var contentId = contentEl ? contentEl.id : '';
        if (!contentId) {
            contentId = 'ai-stream-content-' + Date.now();
            if (contentEl) {
                contentEl.id = contentId;
            }
        }

        if (dom.stopBtn) {
            dom.stopBtn.classList.remove('ai-stop-btn-hidden');
            dom.stopBtn.classList.add('ai-stop-btn-visible');
            dom.stopBtn.onclick = function() {
                if (abortController) {
                    abortController.abort();
                }
            };
        }

        var streamHandler = Stream.createStreamHandler({
            contentId: contentId,
            getString: getString,
            onComplete: function(data) {
                // 发送期间策略模式已切换或会话已重置：丢弃过期响应，仅复位发送状态
                if (sendSeq !== strategyRequestSeq) {
                    resetSendState();
                    return;
                }
                resetSendState();
                var streamingMsg = dom.log.querySelector('.ai-message.streaming');
                if (data.result && data.result.success) {
                    if (streamingMsg) {
                        streamingMsg.classList.remove('streaming');
                        streamingMsg.classList.add('bot');
                    }
                    // 保存服务端返回的 conversationId
                    if (data.result.conversationid) {
                        setCurrentConversationId(data.result.conversationid);
                    }
                    // 获取已渲染的 HTML（从 DOM 提取，包含 Markdown/LaTeX/高亮处理后的完整结果）
                    var botRenderedHtml = '';
                    if (streamingMsg) {
                        var contentEl = streamingMsg.querySelector('.ai-message-content');
                        if (contentEl) {
                            botRenderedHtml = contentEl.innerHTML;
                        }
                    }
                    // 用户消息也提取已渲染的 HTML。
                    // 注意：不能用 '.ai-message.user:last-of-type'——该选择器要求元素是兄弟节点中
                    // 同标签名的最后一个，而日志尾部总是 bot 消息，恒匹配不到。取最后一条用户消息。
                    var userMsgs = dom.log.querySelectorAll('.ai-message.user .ai-message-content');
                    var userMsgEl = userMsgs.length > 0 ? userMsgs[userMsgs.length - 1] : null;
                    var userRenderedHtml = userMsgEl ? userMsgEl.innerHTML : '';
                    pushHistory(config.userName, message, userRenderedHtml);
                    // 使用 data.content（来自 complete 事件的已过滤消息，含引用链接转换），
                    // 回退到 data.result.message（原始完成消息）
                    pushHistory(config.assistantName, data.content || data.result.message, botRenderedHtml);
                } else {
                    if (streamingMsg) {
                        streamingMsg.remove();
                    }
                    addMessageToLog('bot error', (data.result && data.result.error) || errorString);
                }
            },
            onError: function(errorMsg) {
                if (sendSeq !== strategyRequestSeq) {
                    resetSendState();
                    return;
                }
                resetSendState();
                // createStreamHandler 移除了 .ai-message-content，此处移除空的流式消息包装
                var streamingMsg = dom.log.querySelector('.ai-message.streaming');
                if (streamingMsg) { streamingMsg.remove(); }
                addMessageToLog('bot error', errorMsg || errorString);
                addRetryButton(message, currentActionType, currentQuestionIndex);
                // eslint-disable-next-line no-console
                console.error('AI Assistant streaming error:', errorMsg);
            },
            // 无内容时用户点击停止：静默复位发送状态并移除空占位消息，不展示错误
            onAbort: function() {
                resetSendState();
                if (sendSeq !== strategyRequestSeq) {
                    return;
                }
                var streamingMsg = dom.log.querySelector('.ai-message.streaming');
                if (streamingMsg) { streamingMsg.remove(); }
                var loadingMsg = dom.log.querySelector('.ai-message.loading');
                if (loadingMsg) { loadingMsg.remove(); }
            }
        });

        var controller = Stream.fetchSSE(
            M.cfg.wwwroot + '/local/ai_programming_assistant/stream_message.php',
            requestData,
            streamHandler
        );
        abortController = controller;
    }

    /**
     * 非流式响应发送处理（从 sendMessage 提取）
     * @param {Object} Stream stream 模块
     * @param {Object} requestData 请求数据
     * @param {string} message 原始用户消息
     * @param {number} sendSeq 发送时的策略请求序号（用于丢弃过期响应）
     */
    function handleNonStreamingSend(Stream, requestData, message, sendSeq) {
        var request = Ajax.call([{
            methodname: 'local_ai_programming_assistant_send_message',
            args: requestData
        }])[0];

        request.done(function(response) {
            if (sendSeq !== strategyRequestSeq) {
                resetSendState();
                return;
            }
            removeLoadingMessage();
            resetSendState();
            if (!response) {
                addMessageToLog('bot error', errorString);
                addRetryButton(message, currentActionType, currentQuestionIndex);
                return;
            }
            if (response.success) {
                var fullHtml = Stream.markedParse(response.message);
                var botMsg = addMessageToLog('bot', fullHtml, true);
                if (response.debug_prompt) {
                    addMessageToLog('debug', '<details class="ai-debug-details">' +
                        '<summary>' + (config.strDebugPromptLabel || '--- DEBUG: Full prompt ---') + '</summary>' +
                        '<pre class="ai-debug-pre">' + escapeHtml(response.debug_prompt) + '</pre>' +
                        '</details>');
                }
                // 保存服务端返回的 conversationId
                if (response.conversationid) {
                    setCurrentConversationId(response.conversationid);
                }
                // 获取已渲染的 HTML
                var botRenderedHtml = '';
                if (botMsg) {
                    var botContentEl = botMsg.querySelector('.ai-message-content');
                    if (botContentEl) {
                        botRenderedHtml = botContentEl.innerHTML;
                    }
                }
                var userMsgs = dom.log ? dom.log.querySelectorAll('.ai-message.user .ai-message-content') : [];
                var userMsgEl = userMsgs.length > 0 ? userMsgs[userMsgs.length - 1] : null;
                var userRenderedHtml = userMsgEl ? userMsgEl.innerHTML : '';
                pushHistory(config.userName, message, userRenderedHtml);
                pushHistory(config.assistantName, response.message, botRenderedHtml);
            } else {
                addMessageToLog('bot error', response.error || errorString);
            }
        }).fail(function(error) {
            if (sendSeq !== strategyRequestSeq) {
                resetSendState();
                return;
            }
            removeLoadingMessage();
            resetSendState();

            // 诊断日志：即使 error 对象不包含 responseText，也检查可能通过
            // jqXHR 扩展注入的属性（如 status, responseText）。
            // 当 JSON 解析失败时，核心错误是 SyntaxError，但我们丢失了原始 HTTP 响应体。
            // 此处尝试从 jQuery 的 jqXHR 对象获取更多信息。
            if (error && error.status) {
                // eslint-disable-next-line no-console
                console.warn('[AI Programming Assistant] AJAX error status:', error.status, error.statusText);
                if (error.responseText) {
                    // eslint-disable-next-line no-console
                    console.warn('[AI Programming Assistant] AJAX error response (first 500 chars):', error.responseText.substring(0, 500));
                }
            }

            var failMsg = errorString;
            if (error) {
                if (typeof error === 'string') {
                    failMsg = error;
                } else if (error.error) {
                    failMsg = error.error;
                } else if (error.message) {
                    // 空响应或 JSON 解析失败时给出更友好的提示
                    if (error.message.indexOf('Unexpected end of JSON input') !== -1 ||
                        error.message.indexOf('JSON.parse') !== -1 ||
                        error.message.indexOf('parsererror') !== -1) {
                        // 记录完整错误信息用于诊断
                        // eslint-disable-next-line no-console
                        console.warn('[AI Programming Assistant] JSON parse failure detected. This usually means the server returned an empty or non-JSON response (e.g., redirect to login due to session timeout, or a PHP error).');
                        failMsg = getString('request_error', '请求失败，请稍后重试。');
                    } else {
                        failMsg = error.message;
                    }
                } else if (error.responseText) {
                    try {
                        var errObj = JSON.parse(error.responseText);
                        if (errObj && (errObj.error || errObj.message)) {
                            failMsg = errObj.error || errObj.message;
                        }
                    } catch (e) {
                        if (error.responseText.length < 200) {
                            failMsg = error.responseText;
                        }
                    }
                }
            }
            addMessageToLog('bot error', failMsg);
            addRetryButton(message, currentActionType, currentQuestionIndex);
            // eslint-disable-next-line no-console
            console.error('AI Assistant error:', error);
        });
    }

    // ===================== 对话框 =====================

    function addMessageToLog(type, content, isRawHtml, appendTo) {
        if (!dom.log && !appendTo) {
            return;
        }
        var log = dom.log;
        var stream = cachedModules.stream;

        var msgDiv = document.createElement('div');
        msgDiv.className = 'ai-message ' + type + ' ai-message-new';

        if (type === 'user' || type.indexOf('bot') === 0) {
            var sender = document.createElement('div');
            sender.className = 'ai-msg-sender';
            sender.textContent = (type === 'user') ? getString('me') : getString('ai_sender');
            msgDiv.appendChild(sender);
        }

        var contentDiv = document.createElement('div');
        contentDiv.className = 'ai-message-content';
        if (type === 'user') {
            contentDiv.innerHTML = escapeHtml(content);
        } else if (type === 'bot') {
            if (isRawHtml) {
                contentDiv.innerHTML = content;
            } else if (stream) {
                contentDiv.innerHTML = stream.markedParse(content);
                // stream 已就绪时，合并执行 KB 链接、LaTeX 扫描、KaTeX 渲染
                stream.convertKbCitationLinksInElement(contentDiv);
                stream.scanLatexInDom(contentDiv);
                if (!appendTo) {
                    stream.renderMath(contentDiv);
                }
            } else {
                // 回退：模块尚未加载时显示纯文本
                contentDiv.textContent = content;
            }
        } else {
            contentDiv.innerHTML = stream ? stream.sanitizeHtml(content) : escapeHtml(content);
        }
        msgDiv.appendChild(contentDiv);

        var target = appendTo || log;
        target.appendChild(msgDiv);

        // 当直接追加到 log 时，执行 truncation / scroll / highlight 等副作用
        if (!appendTo) {
            if (log.children.length > MAX_DOM_MESSAGES) {
                var excess = log.children.length - MAX_DOM_MESSAGES;
                var range = document.createRange();
                range.setStart(log, 0);
                range.setEnd(log, excess);
                range.deleteContents();
            }

            scrollToBottom(log);

            if (type !== 'user' && type !== 'bot loading') {
                if (stream) {
                    stream.highlightElement(contentDiv);
                }
            }
        }

        return msgDiv;
    }

    /**
     * 将消息推入聊天历史记录
     *
     * @param {string} user 用户名
     * @param {string} message 原始消息文本
     * @param {string} [renderedHtml] 已渲染的 HTML（用于从缓存恢复时跳过重复解析）
     */
    function pushHistory(user, message, renderedHtml) {
        var history = getCurrentHistory();
        var entry = {user: user, message: message};
        if (renderedHtml) {
            entry.renderedHtml = renderedHtml;
        }
        history.push(entry);
        if (history.length > MAX_HISTORY) {
            history.splice(0, history.length - MAX_HISTORY);
        }
        saveSession();
    }

    // ===================== 通用拖拽逻辑（用于调整大小/宽度） =====================

    /**
     * 创建可拖拽交互
     * 封装 mousedown/mousemove/mouseup + touch 事件生命周期，
     * 通过 requestAnimationFrame 节流，消除各拖拽场景中的重复代码。
     *
     * @param {Element} handle 拖拽把手元素
     * @param {Object} options
     * @param {function} [options.onDown] 拖拽开始回调
     * @param {function} options.onMove 拖拽移动回调，接收 (deltaX, deltaY)
     * @param {function} [options.onUp] 拖拽结束回调
     * @param {string} [options.resizingClass] 拖拽时添加到 documentElement 的 CSS 类
     */
    function makeDraggable(handle, options) {
        if (handle._resizeBound) { return; }
        handle._resizeBound = true;

        var rafId = null;
        var startX = 0;
        var startY = 0;

        function getClientXY(e) {
            var clientX = e.clientX;
            var clientY = e.clientY;
            if (typeof clientX === 'undefined' && e.touches) {
                clientX = e.touches[0].clientX;
                clientY = e.touches[0].clientY;
            }
            return {clientX: clientX, clientY: clientY};
        }

        function onDown(e) {
            var xy = getClientXY(e);
            if (typeof xy.clientX === 'undefined') { return; }
            startX = xy.clientX;
            startY = xy.clientY;
            if (options.onDown) { options.onDown(); }
            if (options.resizingClass) {
                document.documentElement.classList.add(options.resizingClass);
            }
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
            document.addEventListener('touchmove', onMove, {passive: false});
            document.addEventListener('touchend', onUp);
        }

        function onMove(e) {
            var xy = getClientXY(e);
            if (typeof xy.clientX === 'undefined') { return; }
            e.preventDefault();
            if (rafId) { cancelAnimationFrame(rafId); }
            rafId = requestAnimationFrame(function() {
                rafId = null;
                options.onMove(xy.clientX - startX, xy.clientY - startY);
            });
        }

        function onUp() {
            if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
            if (options.resizingClass) {
                document.documentElement.classList.remove(options.resizingClass);
            }
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            document.removeEventListener('touchmove', onMove);
            document.removeEventListener('touchend', onUp);
            if (options.onUp) { options.onUp(); }
        }

        handle.addEventListener('mousedown', onDown);
        handle.addEventListener('touchstart', onDown, {passive: false});
    }

    // ===================== 面板缩放 =====================

    function initResize() {
        var handle = document.getElementById('ai-assistant-resize-handle');
        var panel = document.getElementById('ai-assistant-panel');
        if (!handle || !panel) { return; }

        makeDraggable(handle, {
            resizingClass: 'ai-assistant-resizing',
            onDown: function() {
                var cs = getComputedStyle(panel);
                makeDraggable._rw = panel.offsetWidth;
                makeDraggable._rh = panel.offsetHeight;
                makeDraggable._miW = parseFloat(cs.minWidth) || 288;
                makeDraggable._miH = parseFloat(cs.minHeight) || 320;
                makeDraggable._maW = parseFloat(cs.maxWidth) || 960;
                makeDraggable._maH = parseFloat(cs.maxHeight) || 800;
            },
            onMove: function(dx, dy) {
                panel.style.width = Math.max(makeDraggable._miW, Math.min(makeDraggable._maW, makeDraggable._rw - dx)) + 'px';
                panel.style.height = Math.max(makeDraggable._miH, Math.min(makeDraggable._maH, makeDraggable._rh - dy)) + 'px';
            }
        });
    }

    // ===================== 侧边栏宽度调整 =====================

    /**
     * 获取存储的侧边栏宽度百分比（从 localStorage）
     * @returns {number} 宽度百分比（0-100）
     */
    function getStoredSidebarWidth() {
        try {
            var stored = localStorage.getItem(SIDEBAR_WIDTH_KEY);
            var val = parseFloat(stored);
            if (!isNaN(val) && val >= 10 && val <= 60) {
                return val;
            }
        } catch (e) {
            // localStorage 不可用时静默回退
        }
        return 30;
    }

    /**
     * 保存侧边栏宽度百分比到 localStorage
     * @param {number} widthPercent 宽度百分比
     */
    function storeSidebarWidth(widthPercent) {
        try {
            localStorage.setItem(SIDEBAR_WIDTH_KEY, String(widthPercent));
        } catch (e) {
            // localStorage 不可用时静默忽略
        }
    }

    /**
     * 应用侧边栏宽度（更新 CSS 变量）
     * @param {number} widthPercent 宽度百分比
     */
    function applySidebarWidth(widthPercent) {
        var body = document.body;
        body.style.setProperty('--ai-sidebar-width', widthPercent + '%');
    }

    /**
     * 初始化侧边栏拖拽调整宽度
     */
    function initSidebarResize() {
        var handle = document.getElementById('ai-sidebar-resize-handle');
        var panel = document.getElementById('ai-assistant-panel');
        if (!handle || !panel) { return; }

        var startWidth = 0;
        var docWidth = 0;

        makeDraggable(handle, {
            onDown: function() {
                startWidth = parseFloat(getComputedStyle(document.body).getPropertyValue('--ai-sidebar-width')) || 30;
                docWidth = document.documentElement.clientWidth;
                document.body.classList.add('ai-sidebar-resizing');
            },
            onMove: function(dx) {
                // 拖拽是从面板左边缘开始的，向右拖动增加宽度
                // panel 固定在右侧，所以 -dx 表示宽度变化
                var newPct = Math.round(Math.max(10, Math.min(60, startWidth + (-dx / docWidth) * 100)));
                applySidebarWidth(newPct);
            },
            onUp: function() {
                document.body.classList.remove('ai-sidebar-resizing');
                var finalPct = parseFloat(getComputedStyle(document.body).getPropertyValue('--ai-sidebar-width')) || 30;
                storeSidebarWidth(Math.round(finalPct));
            }
        });
    }

    // ===================== 会话持久化 =====================

    /**
     * 保存当前会话状态到 localStorage
     *
     * 使用防抖避免频繁序列化，仅保存 {user, message} 不包含 renderedHtml
     * @param {boolean} immediate 是否立即保存（跳过防抖）
     */
    function saveSession(immediate) {
        if (sessionSaveTimer) {
            clearTimeout(sessionSaveTimer);
            sessionSaveTimer = null;
        }
        if (!immediate) {
            sessionSaveTimer = setTimeout(function() { saveSession(true); }, SESSION_SAVE_DELAY);
            return;
        }
        try {
            var history = {};
            var keys = Object.keys(chatHistoryByMode);
            for (var i = 0; i < keys.length; i++) {
                var key = keys[i];
                var arr = chatHistoryByMode[key];
                if (arr && arr.length > 0) {
                    history[key] = [];
                    for (var j = 0; j < arr.length; j++) {
                        if (arr[j]) {
                            history[key].push({user: arr[j].user, message: arr[j].message});
                        }
                    }
                }
            }
            var session = {
                history: history,
                convIds: conversationIdByMode,
                panelExpanded: panelExpanded,
                panelPopped: panelPopped,
                currentActionType: currentActionType,
                inStrategyMode: inStrategyMode
            };
            localStorage.setItem(SESSION_KEY, JSON.stringify(session));
        } catch (e) {
            // localStorage 不可用时静默忽略
        }
    }

    /**
     * 从 localStorage 恢复会话状态
     * @returns {boolean} 是否成功恢复
     */
    function restoreSession() {
        try {
            var raw = localStorage.getItem(SESSION_KEY);
            if (!raw) { return false; }
            var session = JSON.parse(raw);
            if (!session) { return false; }

            // 恢复对话历史和会话 ID
            if (session.history) {
                var histKeys = Object.keys(session.history);
                for (var i = 0; i < histKeys.length; i++) {
                    var key = histKeys[i];
                    var arr = session.history[key];
                    if (arr && arr.length > 0) {
                        chatHistoryByMode[key] = [];
                        for (var j = 0; j < arr.length; j++) {
                            if (arr[j] && arr[j].user && arr[j].message) {
                                chatHistoryByMode[key].push({
                                    user: arr[j].user,
                                    message: arr[j].message,
                                    renderedHtml: ''
                                });
                            }
                        }
                    }
                }
            }
            if (session.convIds) {
                var convKeys = Object.keys(session.convIds);
                for (var i = 0; i < convKeys.length; i++) {
                    var key = convKeys[i];
                    if (session.convIds[key]) {
                        conversationIdByMode[key] = session.convIds[key];
                    }
                }
            }

            // 恢复面板状态
            panelExpanded = !!session.panelExpanded;
            panelPopped = !!session.panelPopped;
            currentActionType = session.currentActionType || 'free';
            inStrategyMode = !!session.inStrategyMode;

            return true;
        } catch (e) {
            return false;
        }
    }

    /**
     * 清除 localStorage 中的会话数据（新聊天/退出时调用）
     */
    function clearSession() {
        try {
            localStorage.removeItem(SESSION_KEY);
        } catch (e) {
            // 静默忽略
        }
    }

    /**
     * 恢复会话后展开面板并渲染历史对话
     * 在 init() 中 DOM 就绪后调用
     */
    function applyRestoredSession() {
        if (!panelExpanded || !dom.container || !dom.log) {
            return;
        }

        // 直接设置 DOM 状态
        dom.container.classList.remove('ai-assistant-collapsed');
        dom.container.classList.add('ai-assistant-expanded');
        if (panelPopped) {
            dom.container.classList.add('ai-assistant-popped');
        }

        // 同步 body 打开状态（侧边栏模式下应用页面 margin）
        syncPanelOpenState();

        // 加载当前模式的历史对话
        // setActiveStrategy 内部会检查本地缓存，跳过 AJAX
        setActiveStrategy(currentActionType);
    }

    // ===================== 视图模式切换 =====================

    /**
     * 获取存储的视图模式（从 localStorage）
     * @returns {string} 视图模式标识
     */
    function getStoredViewMode() {
        try {
            var stored = localStorage.getItem(VIEWMODE_KEY);
            if (stored === VIEWMODE_FLOATING || stored === VIEWMODE_SIDEBAR) {
                return stored;
            }
        } catch (e) {
            // localStorage 不可用时静默回退
        }
        return VIEWMODE_FLOATING;
    }

    /**
     * 保存视图模式到 localStorage
     * @param {string} mode 视图模式标识
     */
    function storeViewMode(mode) {
        try {
            localStorage.setItem(VIEWMODE_KEY, mode);
        } catch (e) {
            // localStorage 不可用时静默忽略
        }
    }

    /**
     * 同步 body 上的面板打开状态类
     *
     * 侧边栏模式下页面内容需要跟随面板开合让出/恢复空间，
     * 参照 block_ai_chat 的 block_ai_chat_open 做法，用 body 类
     * 控制 #page-wrapper #page 的 margin-right。
     */
    function syncPanelOpenState() {
        var body = document.body;
        if (currentViewMode === VIEWMODE_SIDEBAR && panelExpanded) {
            body.classList.add('ai-panel-open');
        } else {
            body.classList.remove('ai-panel-open');
        }
    }

    /**
     * 切换视图模式
     * @param {string} mode 视图模式标识
     */
    function setViewMode(mode) {
        if (mode === currentViewMode) {
            return;
        }
        // 从 body 移除所有视图模式类
        var body = document.body;
        body.classList.remove('ai-mode-floating', 'ai-mode-sidebar');
        body.classList.add('ai-mode-' + mode);
        currentViewMode = mode;
        storeViewMode(mode);

        // 切换到侧边栏模式时应用存储的宽度
        if (mode === VIEWMODE_SIDEBAR) {
            var storedWidth = getStoredSidebarWidth();
            applySidebarWidth(storedWidth);
            // 确保侧边栏拖拽把手已初始化
            initSidebarResize();
        }

        // 侧边栏模式自动展开面板
        if (mode !== VIEWMODE_FLOATING && dom.container) {
            if (panelExpanded) {
                // 已展开，确保面板正确显示
                dom.container.classList.remove('ai-assistant-collapsed');
                dom.container.classList.add('ai-assistant-expanded');
            } else {
                // 未展开，自动展开
                togglePanel();
            }
        }

        // 同步 body 打开状态（控制页面 margin）
        syncPanelOpenState();
    }

    /**
     * 初始化视图模式：从 localStorage 恢复并应用
     */
    function initViewMode() {
        var stored = getStoredViewMode();
        if (stored !== VIEWMODE_FLOATING) {
            // 如果是侧边栏模式，预先应用存储的宽度
            if (stored === VIEWMODE_SIDEBAR) {
                var storedWidth = getStoredSidebarWidth();
                applySidebarWidth(storedWidth);
            }
            // 延迟执行，确保 DOM 就绪
            setTimeout(function() {
                setViewMode(stored);
            }, 50);
        }
    }

    // ===================== 事件绑定 =====================

    function bindEvents() {
        if (dom.toggle) {
            dom.toggle.addEventListener('click', function() {
                togglePanel();
            });
        }

        if (dom.sendBtn && dom.input) {
            dom.sendBtn.addEventListener('click', function() {
                sendMessage();
            });
            dom.input.addEventListener('keydown', function(e) {
                // Enter 发送消息：无修饰键 或 Ctrl/Meta+Enter（Shift+Enter 为换行）
                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey || !e.shiftKey)) {
                    e.preventDefault();
                    sendMessage();
                }
                if (e.key === 'Escape' && !e.repeat && panelExpanded) {
                    togglePanel();
                }
            });
            var cachedSelectedText = '';
            dom.input.addEventListener('mousedown', function() {
                if (panelFocusSkipPaste) {
                    return;
                }
                var sel = window.getSelection();
                if (sel && !sel.isCollapsed && sel.toString().trim()) {
                    cachedSelectedText = sel.toString().trim();
                    if (cachedSelectedText.length > MAX_SELECTED_TEXT_LENGTH) {
                        cachedSelectedText = cachedSelectedText.substring(0, MAX_SELECTED_TEXT_LENGTH) + '…';
                    }
                } else {
                    cachedSelectedText = '';
                }
            });
            dom.input.addEventListener('focus', function() {
                if (panelFocusSkipPaste) {
                    panelFocusSkipPaste = false;
                    return;
                }
                if (cachedSelectedText) {
                    if (dom.input.value.trim() === '') {
                        dom.input.value = cachedSelectedText;
                        dom.input.selectionStart = dom.input.selectionEnd = dom.input.value.length;
                    }
                    cachedSelectedText = '';
                    return;
                }
                var sel = window.getSelection();
                if (!sel || sel.isCollapsed || !sel.toString().trim()) {
                    return;
                }
                var selectedText = sel.toString().trim();
                if (selectedText.length > MAX_SELECTED_TEXT_LENGTH) {
                    selectedText = selectedText.substring(0, MAX_SELECTED_TEXT_LENGTH) + '…';
                }
                if (dom.input.value.trim() === '') {
                    dom.input.value = selectedText;
                    dom.input.selectionStart = dom.input.selectionEnd = dom.input.value.length;
                }
            });
        }

        if (dom.refresh) {
            dom.refresh.addEventListener('click', function() {
                // 中止进行中的请求并复位发送状态
                // 仅在 isSending 时需要 abort；非发送状态下 UI 已处于复位状态
                if (isSending) {
                    resetSendState();
                }
                // 使进行中的请求回调失效，防止 abort 后回调把错误/响应写入已清空的日志
                strategyRequestSeq++;

                clearSession();
                setCurrentConversationId(0);
                chatHistoryByMode[currentActionType] = [];
                clearDomCache(currentActionType);
                if (dom.log) {
                    dom.log.textContent = '';
                }
            });
        }

        if (dom.popout) {
            dom.popout.addEventListener('click', function() {
                panelPopped = !panelPopped;
                if (dom.container) {
                    dom.container.classList.toggle('ai-assistant-popped', panelPopped);
                }
                saveSession();
            });
        }

        if (dom.minimize) {
            dom.minimize.addEventListener('click', function() {
                if (panelExpanded) {
                    panelPopped = false;
                    if (dom.container) {
                        dom.container.classList.remove('ai-assistant-popped');
                    }
                    // 保存当前策略模式，以便恢复时还原
                    savedStrategyBeforeMinimize = currentActionType;
                    // 侧边栏模式下点击最小化：切回悬浮窗+收起模式
                    if (currentViewMode !== VIEWMODE_FLOATING) {
                        var body = document.body;
                        body.classList.remove('ai-mode-floating', 'ai-mode-sidebar');
                        body.classList.add('ai-mode-floating');
                        currentViewMode = VIEWMODE_FLOATING;
                        storeViewMode(VIEWMODE_FLOATING);
                    }
                    togglePanel();
                }
            });
        }

        initStrategyButtons();
        initResize();
        initSidebarResize();
        populateQuestionSelector();
        setupCodeCheckObserver();

        // 视图模式切换
        var viewmodeOptions = document.querySelectorAll('.ai-viewmode-option');
        viewmodeOptions.forEach(function(option) {
            option.addEventListener('click', function(e) {
                e.preventDefault();
                var mode = this.getAttribute('data-viewmode');
                setViewMode(mode);
                // 切换后自动关闭下拉菜单
                var dropdown = this.closest('.dropdown-menu');
                if (dropdown) {
                    var toggle = dropdown.previousElementSibling;
                    if (toggle) {
                        toggle.setAttribute('aria-expanded', 'false');
                    }
                    dropdown.classList.remove('show');
                }
            });
        });

        if (dom.questionSelect) {
            dom.questionSelect.addEventListener('change', function() {
                var val = parseInt(this.value, 10);
                if (isNaN(val)) {
                    return;
                }
                // "全部嵌入题"选项：不指定具体题目（currentQuestionIndex 置为哨兵值）
                currentQuestionIndex = val;
                // 切换试题时，清空对话框并加载当前试题对应模式的历史记录
                if (dom.log) {
                    dom.log.textContent = '';
                }
                chatHistoryByMode[currentActionType] = [];
                conversationIdByMode[currentActionType] = 0;
                clearDomCache(currentActionType);
                strategyRequestSeq++;
                var mySeq = strategyRequestSeq;
                var qctx = (val >= 0 && val < questionsContext.length)
                    ? questionsContext[val] : null;
                loadConversationHistory(currentActionType, qctx ? qctx.usageid : 0, qctx ? qctx.slot : 0, mySeq,
                    qctx ? qctx.questionid : 0);
            });
        }
    }

    // ===================== 初始化 =====================

    function init(data) {
        // 重置模块级状态，防止重复调用 init 时状态残留
        chatHistoryByMode = {};
        conversationIdByMode = {};
        panelExpanded = false;
        panelPopped = false;
        panelFocusSkipPaste = false;
        questionsContext = [];
        domQuestionsContext = [];
        embeddedQuestions = [];
        embeddedQuestionsLoaded = false;
        isSending = false;
        currentActionType = 'free';
        strategyRequestSeq = 0;
        currentQuestionIndex = -1;
        inStrategyMode = false;
        savedStrategyBeforeMinimize = null;
        domCacheByMode = {};
        scrollCacheByMode = {};
        if (abortController) {
            abortController.abort();
            abortController = null;
        }
        // 清理旧的测试结果 MutationObserver，防止 init 重复调用时泄漏
        if (codeCheckObserver) {
            codeCheckObserver.disconnect();
            codeCheckObserver = null;
        }
        dom = {};

        var configEl = document.getElementById('ai-programming-assistant');
        var rawConfig = configEl ? configEl.getAttribute('data-config') : null;
        if (rawConfig) {
            try {
                config = JSON.parse(rawConfig);
            } catch (e) {
                console.warn('[AI Programming Assistant] Config parse error:', e);
                config = data || {};
            }
        } else {
            config = data || {};
        }
        cacheDom();

        // 试题页面检查：若管理员关闭了"非 CodeRunner 试题启用助手"功能，
        // 且当前试题页面无 CodeRunner 题型，则完全隐藏助手。
        // 检查在初始化早期执行，避免不必要的面板渲染和模块加载。
        var isQuizPage = config.pagetype && config.pagetype.indexOf('mod-quiz') === 0;
        if (isQuizPage && !config.enableNonCodeRunner && !hasCodeRunnerQuestions()) {
            if (dom.container) {
                dom.container.style.display = 'none';
            }
            return;
        }

        // 从 localStorage 恢复会话状态（面板状态、对话历史、会话 ID 等）
        var hasSession = restoreSession();
        responseMode = config.responsemode || 'streaming';

        strings = {
            ai_sender: config.strAiSender || 'AI',
            me: config.strMe || '我',
            copy: config.strCopy || '复制',
            copied: config.strCopied || '已复制！',
            retry: config.strRetry || '重试',
            expand: config.strExpand || '展开',
            collapse: config.strCollapse || '收起',
            ai_feedback_loading: config.strAiFeedbackLoading || 'AI 正在分析...',
            request_error: config.strRequestError || '请求失败，请稍后重试。',
            erroroccurred: config.strErrorOccurred || '请求失败，请稍后重试。',
        };
        errorString = strings.erroroccurred || strings.request_error || '请求失败，请稍后重试。';

        initCopyButtonDelegation();
        // 防止 init 被多次调用时重复绑定事件监听（造成监听器泄漏和重复触发）
        if (!init._eventsBound) {
            init._eventsBound = true;
            bindEvents();
        }

        // 预加载子模块，确保首次发送时缓存已就绪
        ensureModules(function(stream, context) {
            // 模块加载失败时（moduleLoadFailed 路径）以 (null, null) 调用回调，
            // 此处必须守卫，避免直接访问 context/stream 抛 TypeError 使 init 中断。
            if (!stream || !context) {
                return;
            }
            context.setupQuestionsObserver();
            // 启动 MutationObserver 监视数学公式元素
            if (dom.log) {
                stream.watchMath(dom.log);
            }
            // 根据页面试题情况初始化策略栏可见性
            // populateQuestionSelector 已在 bindEvents 中先于此回调执行，questionsContext 已填充
            // 此处基于实际 questionsContext 精确修正模板初始状态（如试题页无 coderunner 题目时回退为自由模式）
            if (!inStrategyMode) {
                updateStrategyBarVisibility();
            }
        });

        // 恢复并应用视图模式（从 localStorage）
        initViewMode();

        // 恢复会话后展开面板并渲染历史对话（延迟确保 DOM 就绪）
        if (hasSession) {
            setTimeout(function() {
                applyRestoredSession();
            }, 100);
        }

        // 防止重复注册 beforeunload 监听器（init 可能被多次调用）
        // 仅首次调用时注册一次：handler 引用的是模块级变量，始终读取最新值，
        // 无需重复注册。重复注册会导致同一事件触发多个相同 handler，造成资源浪费。
        if (!init._beforeunloadBound) {
            init._beforeunloadBound = true;
            window.addEventListener('beforeunload', function() {
                // 页面卸载前保存当前会话状态
                saveSession(true);
                if (abortController) {
                    abortController.abort();
                }
                if (codeCheckObserver) {
                    codeCheckObserver.disconnect();
                    codeCheckObserver = null;
                }
                if (cachedModules.context) {
                    cachedModules.context.cleanupObserver();
                }
                if (cachedModules.stream && typeof cachedModules.stream.destroyMathObserver === 'function') {
                    cachedModules.stream.destroyMathObserver();
                }
                // 清理所有模式的 DOM 缓存，释放 detached 节点引用
                for (var m in domCacheByMode) {
                    if (domCacheByMode.hasOwnProperty(m)) {
                        var c = domCacheByMode[m];
                        if (c && c.innerHTML !== undefined) {
                            c.textContent = '';
                        }
                    }
                }
                domCacheByMode = {};
                scrollCacheByMode = {};
                // 释放对象引用，帮助 GC 回收
                chatHistoryByMode = {};
                conversationIdByMode = {};
                moduleCallbacks = [];
                cachedModules = {};
            });
        }
    }

    return {
        init: init
    };
});