/**
 * AI 编程助手 - 流式处理和渲染模块
 *
 * 按需加载，包含：
 * - SSE 流式连接管理
 * - 流式内容增量渲染（稳定区/尾部策略）
 * - Markdown → HTML 转换
 * - HTML 安全净化
 * - KaTeX 数学公式渲染
 * - Prism 语法高亮延迟加载
 *
 * @module     local_ai_programming_assistant/stream
 * @author zfl
 * @package
 */
/* global katex */
define([], function() {
    /** @var {Object|null} Prism 语法高亮库（按需延迟加载） */
    var Prism = null;

    // ===================== 常量 =====================
    var STREAM_CONNECT_TIMEOUT = 30000;
    var STREAM_NO_DATA_TIMEOUT = 120000;
    var STREAM_HARD_TIMEOUT = 600000;
    var TIMEOUT_WARNING_DELAY = 60000;
    var RENDER_DEBOUNCE_DELAY = 50;
    var SCROLL_THRESHOLD = 80;
    /** @var {number} SSE 缓冲区最大字节数，防止服务器发送无分隔符数据导致 OOM */
    var MAX_SSE_BUFFER_SIZE = 2 * 1024 * 1024;

    // ===================== i18n 字符串 =====================
    /**
     * 获取本地化字符串（setStrings 从未被调用，strings 始终为空，直接返回回退值）
     * @param {string} key 字符串键
     * @param {string} [fallback] 回退文本
     * @returns {string} 回退值或键名
     */
    function gs(key, fallback) {
        return fallback || key;
    }

    // ===================== 工具函数 =====================

    var ESCAPE_HTML_MAP = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    };

    function escapeHtml(text) {
        if (text == null) {
            return '';
        }
        if (typeof text !== 'string') {
            text = String(text);
        }
        return text.replace(/[&<>"']/g, function(ch) {
            return ESCAPE_HTML_MAP[ch];
        });
    }

    function scrollToBottom(log) {
        if (!log) {
            return;
        }
        if (log.scrollHeight - log.scrollTop - log.clientHeight > SCROLL_THRESHOLD) {
            return;
        }
        log.scrollTop = log.scrollHeight;
    }

    /**
     * 清理 LaTeX 公式文本：去除末尾多余反斜杠和空白
     * @param {string} tex 原始公式文本
     * @returns {string} 清理后的公式文本
     */
    function cleanTex(tex) {
        return tex.replace(/\\+\s*$/, '').trim();
    }

    /**
     * 判断反引号 code span 内容是否为数学表达式
     * 多层判定：(非字面量 & 非已包装) → 强数学信号 → 编程特征排除
     * @param {string} text 反引号内文本（不含首尾反引号）
     * @returns {boolean} true 表示应作为数学公式渲染
     */
    function isMathLikeCode(text) {
        if (text == null || text === '') { return false; }
        var t = text.trim();
        if (t === '') { return false; }
        // 前置快速排除：已被数学定界符完整包裹（用户想展示源码，保持 code）
        if (WRAPPED_MATH_RE.test(t)) { return false; }
        // 前置快速排除：字符串 / 正则等代码字面量（反斜杠是转义而非 LaTeX 指令）
        if (CODE_LITERAL_RE.test(t)) { return false; }
        // 前置快速排除：路径类代码（Windows 盘符 / \Users\Desktop 多级路径）
        // 其中 \Users \Projects 等首字母大写词组会被误判为 LaTeX 指令，需优先排除
        if (PATH_LIKE_RE.test(t)) { return false; }
        // 第一层：必须包含至少一个强数学信号
        if (!MATH_SIGNAL_RE.test(t)) { return false; }
        // 第二层：排除编程特征（关键字 > 运算符 > 方法调用）
        if (CODE_KEYWORD_RE.test(t)) { return false; }
        if (CODE_OPERATOR_RE.test(t)) { return false; }
        if (CODE_METHOD_RE.test(t)) { return false; }
        return true;
    }

    /**
     * 美化反引号内识别出的数学表达式
     * - * → \cdot（带尾部空格终止命令名，避免 \cdoty 类未知命令错误）
     * - ** 自然变为 \cdot \cdot（双点乘，不转换为 ^2）
     * - \* 转义星号保留原样（用 split 保护，不依赖 lookbehind）
     * @param {string} tex 原始公式文本（已经过 cleanTex）
     * @returns {string} 美化后的公式文本
     */
    function beautifyMathTex(tex) {
        if (!tex) { return tex; }
        // 用 split 保护 \* (转义星号)：奇数索引片段是 \* 本身，保留不动
        // 偶数索引片段中的 * 替换为 \cdot (带空格终止符)
        return tex.split(/(\\\*)/).map(function(part, i) {
            return i % 2 === 0 ? part.replace(/\*/g, '\\cdot ') : part;
        }).join('');
    }

    function createSettleOnce(timeoutClear) {
        var settled = false;
        return function(callback, arg) {
            if (settled) {
                return false;
            }
            settled = true;
            if (timeoutClear) {
                timeoutClear();
            }
            if (callback) {
                callback(arg);
            }
            return true;
        };
    }

    function getErrorMessage(error, fallback) {
        if (!error) {
            return fallback || gs('error_request_failed', '请求失败，请稍后重试。');
        }
        if (typeof error === 'string') {
            return error;
        }
        if (error.message) {
            return error.message;
        }
        return fallback || gs('error_request_failed', '请求失败，请稍后重试。');
    }

    function ensurePrism(callback) {
        if (typeof Prism !== 'undefined' && Prism !== null) {
            callback();
            return;
        }
        require(['filter_codehighlighter/prism'], function(prism) {
            Prism = prism;
            callback();
        }, function() {
            // 加载失败时静默回退（无高亮），但仍需调用 callback 以释放调用方等待
            callback();
        });
    }

    // ===================== 安全净化常量 =====================
    var SANITIZE_DANGEROUS_TAGS = {
        script: 1, style: 1, iframe: 1, object: 1, embed: 1,
        applet: 1, form: 1, link: 1, meta: 1, base: 1,
        template: 1, slot: 1
    };
    var SANITIZE_URL_ATTRS = {href: 1, src: 1, action: 1, formaction: 1, cite: 1, data: 1};
    var SANITIZE_JS_RE = /^(javascript|vbscript|data)\s*:/i;
    var SANITIZE_CSS_RE = /expression\s*\(|behavior\s*:/i;
    var SANITIZE_DOM_PARSER = null;

    /**
     * 检测 URL 是否包含 javascript/vbscript/data 等危险协议
     * @param {string} value
     * @returns {boolean}
     */
    function isDangerousUrl(value) {
        if (!value) { return false; }
        return SANITIZE_JS_RE.test(value.replace(/[\s\u0000-\u001f]+/g, '').toLowerCase());
    }

    /**
     * 对 HTML 属性值进行转义
     * @param {string} value
     * @returns {string}
     */
    function escapeAttr(value) {
        return (value || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    /**
     * HTML 净化函数（DOMParser 版本）
     */
    function sanitizeHtml(html) {
        if (html == null || html === '') {
            return '';
        }
        // 短文本快速路径：无 HTML 标签时直接返回（跳过 DOMParser 开销）
        if (html.length < 1024 && html.indexOf('<') === -1) {
            return html;
        }
        try {
            if (!SANITIZE_DOM_PARSER) {
                SANITIZE_DOM_PARSER = new DOMParser();
            }
            var doc = SANITIZE_DOM_PARSER.parseFromString(html, 'text/html');
            var body = doc.body;
            var cleanNode = function(node) {
                var i, attr;
                if (node.nodeType === Node.ELEMENT_NODE) {
                    var tag = node.tagName.toLowerCase();
                    if (SANITIZE_DANGEROUS_TAGS[tag]) {
                        node.parentNode.removeChild(node);
                        return;
                    }
                    var toRemove = [];
                    for (i = 0; i < node.attributes.length; i++) {
                        attr = node.attributes[i];
                        var name = attr.name.toLowerCase();
                        if (name.indexOf('on') === 0) {
                            toRemove.push(attr.name);
                        } else if (SANITIZE_URL_ATTRS[name] && isDangerousUrl(attr.value)) {
                            node.setAttribute(attr.name, '#');
                        } else if (name === 'style' && SANITIZE_CSS_RE.test(attr.value)) {
                            toRemove.push(attr.name);
                        }
                    }
                    for (i = 0; i < toRemove.length; i++) {
                        node.removeAttribute(toRemove[i]);
                    }
                }
                for (i = node.childNodes.length - 1; i >= 0; i--) {
                    cleanNode(node.childNodes[i]);
                }
            };
            cleanNode(body);
            return body.innerHTML;
        } catch (e) {
            return html.replace(/[&<>]/g, function(ch) {
                return ch === '&' ? '&amp;' : ch === '<' ? '&lt;' : '&gt;';
            });
        }
    }

    // ===================== Markdown 渲染 =====================
    // 保留的预编译正则（仅保留必需的：HTML/文本检测、数学提取）
    var MD_HTML_TAG_RE = /<(?:p|div|h[1-6]|ul|ol|li|table|thead|tbody|tr|td|th|pre|code|blockquote|details|summary|section|article|aside|header|footer|nav|main|strong|em|a|span|img|br|hr)\b[^>]*>/i;
    var MD_MARKDOWN_RE = /(?:```[\s\S]*?```|`[^`]+`|\*\*[^*]+\*\*|^[#*>\-\d]|^\d+\.\s+|\[([^\]]+)\]\(|^-{3,}$|\|.+\|)/m;
    var MD_HTML_START_RE = /^<[a-z][\s\S]*>/i;
    var MD_URL_RE = /(https?:\/\/[^\s<>"']+)(?![^<>]*>|[^<>]*<\/)/g;
    var MD_MATH_DISPLAY_DOLLAR_RE = /\$\$([\s\S]*?)\$\$/g;
    var MD_MATH_INLINE_DOLLAR_RE = /\$([^\$]+)\$/g;
    var MD_MATH_DISPLAY_BRACKET_RE = /\\\[([\s\S]*?)\\\]/g;
    var MD_MATH_INLINE_RE = /\\\(([\s\S]*?)\\\)/g;
    var MD_MATH_BRACKET_RE = /\[\s*([^\[\]]*?)\s*\]/g;

    // ===================== 反引号内数学识别 =====================
    // 强数学信号：_{...} / ^{...} / \xxx LaTeX 指令（至少 2 字母，排除 \n\t 等代码转义） / Unicode 上下标
    var MATH_SIGNAL_RE = /(?:_\{[^}]+\}|\^\{[^}]+\}|\\[a-zA-Z]{2,}|[\u00B2\u00B3\u00B9\u2070-\u2079\u2080-\u2089])/;
    // 编程关键字黑名单（覆盖 Python/JS/Java/C/Go/Rust 等常见语言）
    var CODE_KEYWORD_RE = /\b(?:def|for|if|elif|else|while|return|function|class|import|from|print|range|lambda|in|as|with|try|except|finally|throw|catch|switch|case|break|continue|new|delete|var|let|const|int|float|str|bool|void|null|nil|true|false|do|end|begin|then|when|match|fn|func|pub|impl|struct|enum|trait|extends|implements|package|namespace|using|include|require|console|system|println|printf|async|await|yield|goto|pass|raise|typedef|sizeof|public|private|protected|static|final|abstract|virtual|override|this|self|super|base)\b/;
    // 编程运算符黑名单（双字符及以上；单字符 = + - * / < > 不拦截，数学中也常见）
    var CODE_OPERATOR_RE = /(?:==|!=|<=|>=|\+=|-=|\*=|\/=|%=|&=|\|=|\^=|=>|->|::|\+\+|--|&&|\|\||<<|>>|===|!==)/;
    // 方法调用模式：obj.method(
    var CODE_METHOD_RE = /[a-zA-Z_]\w*\.[a-zA-Z_]\w*\s*\(/;
    // 已正确格式化的数学定界符（$...$ / $$...$$ / \(...\) / \[...\]）
    // 若反引号内容已被这些定界符完整包裹，说明用户想展示 LaTeX 源码，保持 code
    var WRAPPED_MATH_RE = /^(?:\$\$[\s\S]+\$\$|\$[^$\n]+\$|\\\([\s\S]+\\\)|\\\[[\s\S]+\\\])$/;
    // 常见代码字面量形式：字符串 / 正则表达式（若被引号或斜杠完整包裹，应保持 code）
    var CODE_LITERAL_RE = /^(?:\"[^\"]*\"|'[^']*'|`[^`]*`|\/[^\/\n]+\/[gimsuy]*)$/;
    // 路径类代码：Windows 盘符前缀（C:\、D:/）或 3+ 个连续 \Word 形式的反斜杠路径
    // 这类内容中 \Users \Desktop \Projects 等词组会被误判为 LaTeX 指令，需前置排除
    // 仅 3+ 连续反斜杠词可排除：确保 \alpha\beta（仅 2 个）等合法 LaTeX 不被误伤
    var PATH_LIKE_RE = /[A-Za-z]:[\\\/]|(?:^|[^\\])((?:\\[A-Za-z][A-Za-z0-9_]*){3,})/;

    // ===================== marked 加载器 =====================
    var MARKED_LOCAL_URL = M.cfg.wwwroot + '/local/ai_programming_assistant/js/marked.min.js';
    var MARKED_CDN_URL = 'https://cdn.jsdelivr.net/npm/marked/marked.min.js';
    /** @var {boolean} marked 库是否已加载就绪 */
    var markedLoaded = false;
    /** @var {boolean} marked 库是否正在加载 */
    var markedLoading = false;
    /** @var {Array<function>} marked 加载完成后的回调队列 */
    var markedCallbacks = [];
    /** @var {Object|null} 缓存的 marked 自定义渲染器 */
    var cachedMarkedRenderer = null;
    /** @var {Object|null} 缓存的 marked.parse 选项对象（避免热路径上重复创建） */
    var cachedMarkedOptions = null;

    /**
     * 执行并清空 marked 加载回调队列
     * @param {function} [extraCallback] 额外的回调（在队列回调之后执行）
     */
    function flushMarkedCallbacks(extraCallback) {
        var cbs = markedCallbacks;
        markedCallbacks = [];
        cbs.forEach(function(cb) { try { cb(); } catch (e) { console.warn('[AI Programming Assistant]', e); } });
        if (extraCallback) { extraCallback(); }
    }

    /**
     * 从 CDN 动态加载 marked 库（类似 KaTeX 加载方式）
     * 优先从本地加载，CDN 作为备用。
     */
    function loadMarked(callback) {
        if (markedLoaded && typeof marked !== 'undefined') {
            if (callback) { callback(); }
            return;
        }
        if (markedLoading) {
            if (callback) { markedCallbacks.push(callback); }
            return;
        }
        /** @type {number|null} 超时回调 ID，加载成功后清理 */
        var markedTimeoutId = null;

        function clearMarkedTimeout() {
            if (markedTimeoutId !== null) {
                clearTimeout(markedTimeoutId);
                markedTimeoutId = null;
            }
        }

        // 检查是否已有 marked script 标签存在
        var existing = document.querySelector('script[src*="marked"]');
        if (existing) {
            markedLoading = true;
            // 如果 marked 已加载完成，直接回调
            if (typeof marked !== 'undefined') {
                markedLoaded = true;
                markedLoading = false;
                flushMarkedCallbacks(callback);
                return;
            }
            // 监听已存在的 script 标签的 load 事件
            var onLoadHandler = function() {
                clearMarkedTimeout();
                existing.removeEventListener('load', onLoadHandler);
                if (typeof marked !== 'undefined') {
                    markedLoaded = true;
                    markedLoading = false;
                    flushMarkedCallbacks(callback);
                }
            };
            existing.addEventListener('load', onLoadHandler);
            // 超时兜底
            markedTimeoutId = setTimeout(function() {
                markedTimeoutId = null;
                if (markedLoading) {
                    markedLoaded = true;
                    markedLoading = false;
                    console.warn('[AI Programming Assistant] Timed out waiting for marked library, falling back');
                    flushMarkedCallbacks(callback);
                }
            }, 15000);
            return;
        }
        markedLoading = true;

        function attachOnload(scriptEl) {
            scriptEl.onload = function() {
                clearMarkedTimeout();
                markedLoaded = true;
                markedLoading = false;
                flushMarkedCallbacks(callback);
            };
        }

        function tryLocal() {
            var script = document.createElement('script');
            script.src = MARKED_LOCAL_URL;
            attachOnload(script);
            script.onerror = function() {
                // 本地加载失败，回退到 CDN
                tryCDN();
            };
            document.head.appendChild(script);
        }

        function tryCDN() {
            var script = document.createElement('script');
            script.src = MARKED_CDN_URL;
            attachOnload(script);
            script.onerror = function() {
                clearMarkedTimeout();
                markedLoading = false;
                console.warn('[AI Programming Assistant] Failed to load marked library from CDN');
                flushMarkedCallbacks(callback);
            };
            document.head.appendChild(script);
        }

        markedTimeoutId = setTimeout(function() {
            markedTimeoutId = null;
            // 超时保护：8 秒后未加载完成则尝试 CDN（仅在本地路径时有效）
            if (markedLoading && typeof marked === 'undefined') {
                console.warn('[AI Programming Assistant] Timed out loading marked from local, trying CDN');
                tryCDN();
            }
        }, 8000);

        tryLocal();
    }

    // 模块初始化时立即开始加载 marked
    loadMarked();

    /**
     * 构建 marked 自定义渲染器，产出与 old internalMarkedParse 相同的 HTML 结构
     * @param {function} getString 多语言字符串获取函数
     * @returns {Object} marked renderer 对象
     */
    function buildMarkedRenderer(getString) {
        var gs = getString || function(k, fb) { return fb || k; };

        // 基于 marked.Renderer 扩展，确保所有方法都有默认实现
        // marked v15 的 renderer 方法接收 token 对象而非位置参数
        var renderer = new marked.Renderer();

        // 代码块：保留全部自定义输出（header + 行号 + 折叠 + 复制）
        renderer.code = function(token) {
            var lang = token.lang || '';
            var code = (token.text != null) ? String(token.text) : '';
            var trimmedCode = code.trim();
            var lineCount = (trimmedCode.match(/\n/g) || []).length + 1;
            var langLabel = lang
                ? '<span class="ai-code-lang" data-lang="' + escapeHtml(lang) + '">' + escapeHtml(lang) + '</span>'
                : '<span class="ai-code-lang">code</span>';
            var lineParts = [];
            for (var i = 1; i <= lineCount; i++) {
                lineParts.push('<span class="ai-code-line-num">' + i + '</span>');
            }
            var foldButton = '';
            var foldClass = '';
            if (lineCount > 15) {
                foldButton = '<button class="ai-code-fold" title="' + gs('expand', '展开') + '"><i class="fa fa-chevron-down"></i></button>';
                foldClass = ' ai-code-block-folded';
            }
            return '<div class="ai-code-block' + foldClass + '">'
                + '<div class="ai-code-header">' + langLabel
                + '<div class="ai-code-actions">'
                + '<button class="ai-code-copy">' + gs('copy', '复制') + '</button>'
                + foldButton
                + '</div></div>'
                + '<div class="ai-code-body">'
                + '<div class="ai-code-lines">' + lineParts.join('') + '</div>'
                + '<pre><code' + (lang ? ' class="language-' + escapeHtml(lang) + '"' : '') + '>'
                + escapeHtml(trimmedCode) + '</code></pre>'
                + '</div></div>';
        };

        // 安全解析内联内容：优先用 parser.parseInline 解析内联 tokens，
        // 使普通文本经过转义、内联 HTML 路由到下方过滤后的 renderer.html，
        // 避免直接把 marked v15 的原始 token.text 拼入 HTML 造成 XSS。
        // tokens 为空时回退为转义后的原始文本。
        function inlineHtml(token) {
            var tokens = token.tokens;
            if (tokens && tokens.length && this && this.parser) {
                return this.parser.parseInline(tokens);
            }
            return escapeHtml(String(token.text != null ? token.text : ''));
        }

        // 标题：marked v15 接收 token {text, depth, tokens}
        renderer.heading = function(token) {
            var level = token.depth;
            var tag = level === 1 ? 'h3' : level === 2 ? 'h4' : level === 3 ? 'h5' : 'h6';
            return '<' + tag + '>' + inlineHtml.call(this, token) + '</' + tag + '>\n';
        };

        // 链接：marked v15 接收 token {href, title, tokens}
        renderer.link = function(token) {
            var href = token.href;
            // 使用 parser 解析内联 tokens 获取文本（已转义并过滤内联 HTML）
            var text = inlineHtml.call(this, token);
            if (isDangerousUrl(href)) {
                return text !== '' ? text : (href || '');
            }
            var safeUrl = escapeAttr(href);
            return '<a href="' + safeUrl + '" target="_blank" rel="noopener">' + (text !== '' ? text : escapeHtml(href || '')) + '</a>';
        };

        // 表格：利用缓存的默认 renderer 生成 table 内容
        var defaultRenderer = new marked.Renderer();
        var defaultTableFn = defaultRenderer.table.bind(defaultRenderer);
        renderer.table = function(token) {
            var html = defaultTableFn(token);
            return '\n<div class="ai-table-wrapper">' + html + '</div>\n';
        };

        // 引用：marked v15 接收 token {text, tokens}
        renderer.blockquote = function(token) {
            return '<blockquote class="ai-blockquote">' + inlineHtml.call(this, token) + '</blockquote>\n';
        };

        // 列表项：marked v15 接收 token {text, task, checked, tokens}
        renderer.listitem = function(token) {
            var text = inlineHtml.call(this, token);
            var task = token.task;
            var checked = token.checked;
            if (task) {
                return '<div class="ai-task"><input type="checkbox"' + (checked ? ' checked' : '') + ' disabled> ' + text + '</div>\n';
            }
            return '<li>' + text + '</li>\n';
        };

        // 普通文本：marked v15 接收 token（可能有 tokens 或 text 属性）
        renderer.text = function(token) {
            var text = token.text;
            if (text == null || text === '') { return ''; }
            if (typeof text !== 'string') { text = String(text); }
            // 先识别裸链接，再整体转义，防止 URL 文本逃逸属性或注入 HTML
            var links = [];
            text = text.replace(MD_URL_RE, function(match, url) {
                if (isDangerousUrl(url)) {
                    return escapeHtml(url);
                }
                links.push('<a href="' + escapeAttr(url) + '" target="_blank" rel="noopener">' + escapeHtml(url) + '</a>');
                return '\u0001L' + (links.length - 1) + '\u0001';
            });
            text = escapeHtml(text);
            text = text.replace(/\u0001L(\d+)\u0001/g, function(m, i) {
                return links[parseInt(i, 10)];
            });
            return text;
        };

        // 段落：marked v15 必须
        renderer.paragraph = function(token) {
            var text = inlineHtml.call(this, token);
            if (text === '') { return '<p></p>\n'; }
            return '<p>' + text + '</p>\n';
        };

        // 原始 HTML：过滤危险标签和事件属性，防止 XSS
        renderer.html = function(token) {
            var html = token.text || '';
            // 移除危险标签及其内容（配对形式）
            html = html.replace(/<script[^>]*>[\s\S]*?<\/script>/gi, '');
            html = html.replace(/<style[^>]*>[\s\S]*?<\/style>/gi, '');
            html = html.replace(/<iframe[^>]*>[\s\S]*?<\/iframe>/gi, '');
            html = html.replace(/<(object|embed|applet|form|base|meta|link|template|slot)[^>]*>[\s\S]*?<\/\1>/gi, '');
            // 移除未闭合的危险标签开始标签：配对正则无法覆盖
            // <iframe src="javascript:..."> 等无结束标签的片段，浏览器仍会
            // 创建该元素并执行危险 src，需一并剥离（script 未闭合会吞掉后续
            // 内容导致渲染错乱，同样剥离）
            html = html.replace(/<(?:script|style|iframe)[^>]*\/?>/gi, '');
            html = html.replace(/<(object|embed|applet|form|base|meta|link|template|slot)[^>]*\/?>/gi, '');
            // 移除事件属性（on*）
            html = html.replace(/\s+on\w+\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+)/gi, '');
            // 移除 javascript:/vbscript:/data: URL 协议（带引号形式，大小写不敏感）
            html = html.replace(/((?:href|src|action|formaction)\s*=\s*)(?:"[^"]*(?:javascript|vbscript|data)\s*:[^"]*"|'[^']*(?:javascript|vbscript|data)\s*:[^']*')/gi, '$1""');
            // 移除无引号形式的危险协议属性值（<a href=javascript:alert(1)> 等绕过）
            html = html.replace(/((?:href|src|action|formaction)\s*=\s*)(?:javascript|vbscript|data)\s*:[^\s>]+/gi, '$1""');
            return html;
        };

        return renderer;
    }

    /**
     * 轻量级备用渲染器（marked 尚未加载完成时使用）
     * 仅处理最基本格式：代码块、粗体、链接、换行
     */
    function fallbackMarkedParse(content, getString, mathPlaceholders) {
        var gs = getString || function(k, fb) { return fb || k; };
        // 提取数学公式占位符（fallback 路径同样需要）
        // 如果 markedParse 已经预提取了数学公式，直接使用传入的占位符数组
        if (!mathPlaceholders || mathPlaceholders.length === 0) {
            mathPlaceholders = [];
            content = extractMathPlaceholders(content, mathPlaceholders);
        }
        // 提取反引号内联代码为占位符：避免后续列表/标题的 escapeHtml
        // 对已转义内容进行二次处理导致 &quot; → &amp;quot; 等双重转义
        var inlineCodeBlocks = [];
        content = content.replace(/`([^`\n]+)`/g, function(m, code) {
            var idx = inlineCodeBlocks.length;
            inlineCodeBlocks.push('<code>' + escapeHtml(code) + '</code>');
            return '@@AI_PA_INLINE_CODE_' + idx + '@@';
        });
        // 代码块
        var codeBlocks = [];
        content = content.replace(/```([\w#+.-]*)\n?([\s\S]*?)```/g, function(m, lang, code) {
            var idx = codeBlocks.length;
            var trimmedCode = code.trim();
            var lineCount = (trimmedCode.match(/\n/g) || []).length + 1;
            var lineNums = [];
            for (var i = 1; i <= lineCount; i++) { lineNums.push('<span class="ai-code-line-num">' + i + '</span>'); }
            codeBlocks.push(
                '<div class="ai-code-block"><div class="ai-code-header">'
                + '<span class="ai-code-lang">' + escapeHtml(lang || 'code') + '</span>'
                + '<div class="ai-code-actions"><button class="ai-code-copy">' + gs('copy', '复制') + '</button></div></div>'
                + '<div class="ai-code-body"><div class="ai-code-lines">' + lineNums.join('') + '</div>'
                + '<pre><code>' + escapeHtml(trimmedCode) + '</code></pre></div></div>'
            );
            return '@@AI_PA_CODEBLOCK_' + idx + '@@';
        });
        // 标题：### text → <h5>text</h5>（保持与 buildMarkedRenderer 一致的层级映射）
        content = content.replace(/^(#{1,6})\s+(.+)$/gm, function(m, hashes, text) {
            var level = Math.min(hashes.length, 6);
            var tag = level <= 1 ? 'h3' : level === 2 ? 'h4' : level === 3 ? 'h5' : 'h6';
            return '<' + tag + '>' + escapeHtml(text) + '</' + tag + '>';
        });
        // 无序列表：将连续的 - item 包裹在 <ul> 中
        content = content.replace(/(?:^- .+(?:\n|$))+/gm, function(match) {
            var items = match.trim().split('\n').map(function(line) {
                var text = line.replace(/^- (.+)$/, '$1');
                return '<li>' + escapeHtml(text) + '</li>';
            }).join('');
            return '<ul>' + items + '</ul>\n';
        });
        // 有序列表：将连续的 1. item 包裹在 <ol> 中
        content = content.replace(/(?:^\d+\.\s+.+(?:\n|$))+/gm, function(match) {
            var items = match.trim().split('\n').map(function(line) {
                var text = line.replace(/^\d+\.\s+(.+)$/, '$1');
                return '<li>' + escapeHtml(text) + '</li>';
            }).join('');
            return '<ol>' + items + '</ol>\n';
        });
        // 注：内联代码占位符已在入口处提取并一次性格式化，此处不再扫描反引号
        content = content.replace(/\*\*([^*]+)\*\*/g, function(m, text) { return '<strong>' + escapeHtml(text) + '</strong>'; });
        content = content.replace(/\*([^*\n]+)\*/g, function(m, text) { return '<em>' + escapeHtml(text) + '</em>'; });
        content = content.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function(match, text, href) {
            if (isDangerousUrl(href)) {
                return escapeHtml(text);
            }
            return '<a href="' + escapeHtml(href) + '" target="_blank" rel="noopener">' + escapeHtml(text) + '</a>';
        });
        content = content.replace(MD_URL_RE, function(match, url) {
            if (isDangerousUrl(url)) {
                return escapeHtml(url);
            }
            return '<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener">' + escapeHtml(url) + '</a>';
        });
        content = content.replace(/\n/g, '<br>');
        content = content.replace(/@@AI_PA_CODEBLOCK_(\d+)@@/g, function(m, idx) {
            return codeBlocks[parseInt(idx, 10)] || '';
        });
        // 恢复内联代码占位符（在列表等 escapeHtml 之后，确保只做一次 HTML 转义）
        content = content.replace(/@@AI_PA_INLINE_CODE_(\d+)@@/g, function(m, idx) {
            return inlineCodeBlocks[parseInt(idx, 10)] || '';
        });
        // 恢复数学公式占位符为 HTML
        content = restoreMathPlaceholders(content, mathPlaceholders);
        return content;
    }

    /**
     * 从内容中提取数学公式占位符（与 old internalMarkedParse 逻辑一致）
     * @param {string} content 原始内容
     * @param {Array} mathPlaceholders 输出占位符数组
     * @returns {string} 替换占位符后的内容
     */
    function extractMathPlaceholders(content, mathPlaceholders) {
        // 第一步：保护代码块和行内代码，防止其中的 _ 被误处理
        // 存储代码块占位符（独立于 mathPlaceholders）
        var codePlaceholders = [];
        // 提取围栏代码块 ```...```
        content = content.replace(/```[\s\S]*?```/g, function(match) {
            var idx = codePlaceholders.length;
            codePlaceholders.push(match);
            return '@@AI_PA_CODEBLOCK_INTERNAL_' + idx + '@@';
        });
        // 提取行内代码 `...`：智能识别其中的数学表达式，分流到 mathPlaceholders
        content = content.replace(/`[^`\n]+`/g, function(match) {
            var inner = match.slice(1, -1);  // 去掉首尾反引号
            if (isMathLikeCode(inner)) {
                // 识别为数学表达式 → mathPlaceholders 路径（KaTeX 渲染）
                var mathIdx = mathPlaceholders.length;
                mathPlaceholders.push({type: 'inline', tex: beautifyMathTex(cleanTex(inner))});
                return '@@AI_PA_MATH_INLINE_' + mathIdx + '@@';
            }
            // 普通代码 → codePlaceholders 路径（marked.js 渲染为 <code>）
            var codeIdx = codePlaceholders.length;
            codePlaceholders.push(match);
            return '@@AI_PA_CODEBLOCK_INTERNAL_' + codeIdx + '@@';
        });

        content = content.replace(MD_MATH_DISPLAY_DOLLAR_RE, function(match, tex) {
            var idx = mathPlaceholders.length;
            mathPlaceholders.push({type: 'display', tex: cleanTex(tex)});
            return '@@AI_PA_MATH_DISPLAY_' + idx + '@@';
        });
        content = content.replace(MD_MATH_INLINE_DOLLAR_RE, function(match, tex) {
            var idx = mathPlaceholders.length;
            mathPlaceholders.push({type: 'inline', tex: cleanTex(tex)});
            return '@@AI_PA_MATH_INLINE_' + idx + '@@';
        });
        content = content.replace(MD_MATH_DISPLAY_BRACKET_RE, function(match, tex) {
            var idx = mathPlaceholders.length;
            mathPlaceholders.push({type: 'display', tex: cleanTex(tex)});
            return '@@AI_PA_MATH_DISPLAY_' + idx + '@@';
        });
        content = content.replace(MD_MATH_INLINE_RE, function(match, tex) {
            var idx = mathPlaceholders.length;
            mathPlaceholders.push({type: 'inline', tex: cleanTex(tex)});
            return '@@AI_PA_MATH_INLINE_' + idx + '@@';
        });
        content = content.replace(MD_MATH_BRACKET_RE, function(match, tex, offset) {
            // 跳过 markdown 链接语法 [text](url) — 检查 ] 后是否紧跟 (
            var afterBracket = offset + match.length;
            if (afterBracket < content.length && content.charAt(afterBracket) === '(') {
                return match;
            }
            if (/\\[a-z]+/i.test(tex)) {
                var idx = mathPlaceholders.length;
                mathPlaceholders.push({type: 'display', tex: cleanTex(tex)});
                return '@@AI_PA_MATH_DISPLAY_' + idx + '@@';
            }
            return match;
        });

        // 恢复代码块占位符
        // 注意：不再对 LaTeX 下标 _ 做转义。marked.js 遵循 CommonMark 规范，
        // 当 _ 前置为字母/数字时（如 x_{n+1}、x_n），不会将其解析为强调标记，
        // 因此裸 LaTeX 下标能被 marked.js 原样保留。之前的 \_ 转义会导致双重转义
        // （\\_），使 marked.js 误判 * 为强调结束符，进而吞噬 * 并产生 <em>/<code> 错乱。
        content = content.replace(/@@AI_PA_CODEBLOCK_INTERNAL_(\d+)@@/g, function(m, idx) {
            return codePlaceholders[parseInt(idx, 10)] || '';
        });

        return content;
    }

    /**
     * 恢复数学公式占位符为 HTML
     * @param {string} html 含占位符的 HTML
     * @param {Array} mathPlaceholders 占位符数组
     * @returns {string} 恢复后的 HTML
     */
    function restoreMathPlaceholders(html, mathPlaceholders) {
        html = html.replace(/@@AI_PA_MATH_DISPLAY_(\d+)@@/g, function(m, idx) {
            var item = mathPlaceholders[parseInt(idx, 10)];
            if (!item) { return ''; }
            var safeAttr = item.tex.replace(/&/g, '&amp;').replace(/"/g, '&quot;');
            var safeBody = escapeHtml(item.tex);
            return '<div class="math math-display" data-tex="' + safeAttr + '">\\[' + safeBody + '\\]</div>';
        });
        html = html.replace(/@@AI_PA_MATH_INLINE_(\d+)@@/g, function(m, idx) {
            var item = mathPlaceholders[parseInt(idx, 10)];
            if (!item) { return ''; }
            var safeAttr = item.tex.replace(/&/g, '&amp;').replace(/"/g, '&quot;');
            var safeBody = escapeHtml(item.tex);
            return '<span class="math math-inline" data-tex="' + safeAttr + '">\\(' + safeBody + '\\)</span>';
        });
        return html;
    }

    /**
     * marked 核心渲染（提取数学 → marked 处理 → 恢复数学）
     * @param {string} content 已处理流式边缘情况的 markdown 文本
     * @param {function} getString 多语言字符串获取函数
     * @returns {string} 渲染后的 HTML
     */
    function renderWithMarked(content, getString, mathPlaceholders) {
        // 提取数学公式（marked 无法处理 LaTeX）
        // 如果 markedParse 已经预提取了数学公式，直接使用传入的占位符数组
        if (!mathPlaceholders || mathPlaceholders.length === 0) {
            mathPlaceholders = [];
            content = extractMathPlaceholders(content, mathPlaceholders);
        }

        // 按需构建并缓存渲染器（每次 gs 可能不同，拆分为按 getString 缓存）
        if (!cachedMarkedRenderer) {
            cachedMarkedRenderer = buildMarkedRenderer(getString);
            cachedMarkedOptions = {renderer: cachedMarkedRenderer, gfm: true, breaks: true};
        }

        content = marked.parse(content, cachedMarkedOptions);

        // 恢复数学公式
        content = restoreMathPlaceholders(content, mathPlaceholders);

        return content;
    }

    /**
     * 增强版 Markdown 渲染函数
     *
     * 优先使用 marked 库（CDN 加载），加载前使用 fallbackMarkedParse 兜底。
     * 保留原来的流式边缘情况处理（未闭合代码块、反引号、表格行）和纯 HTML 快速路径检测。
     */
    function markedParse(md, isStreaming, getString) {
        if (md == null || md === '') { return ''; }
        if (typeof md !== 'string') { md = String(md); }
        var content = md;

        // ===== 流式边缘情况处理（仅流式场景：修复未闭合的 ``` 和 `） =====
        if (isStreaming) {
            var codeBlockCount = 0;
            var backtickCount = 0;
            var inCodeBlock = false;
            var i = 0;
            var len = md.length;
            while (i < len) {
                if (md.charCodeAt(i) === 96) { // '`'
                    if (i + 2 < len && md.charCodeAt(i + 1) === 96 && md.charCodeAt(i + 2) === 96) {
                        codeBlockCount++;
                        inCodeBlock = !inCodeBlock;
                        i += 3;
                        continue;
                    }
                    // 仅在代码块外部计数行内反引号
                    if (!inCodeBlock) {
                        backtickCount++;
                    }
                }
                i++;
            }
            if (codeBlockCount % 2 !== 0) {
                content = md + '\n```';
            }
            if (backtickCount % 2 !== 0) {
                content = (content || md) + '`';
            }
            // 流式表格行修复（AI 逐 token 生成表格时）
            var lines = (content || md).split('\n');
            var lastLine = lines[lines.length - 1];
            if (lastLine && lastLine.startsWith('|') && /^\|.*\|$/.test(lastLine) && !lastLine.includes('---')) {
                content = (content || md) + '\n';
            }
        }

        // ===== 提前提取数学公式（纯 HTML 路径也会丢失数学公式） =====
        var mathPlaceholders = [];
        var hasMath = content.indexOf('$$') !== -1
            || content.indexOf('\\[') !== -1
            || content.indexOf('\\(') !== -1
            || content.indexOf('$') !== -1
            || content.indexOf('`') !== -1;
        if (hasMath) {
            content = extractMathPlaceholders(content, mathPlaceholders);
        }

        // ===== 纯 HTML 快速路径（仅非流式模式，流式响应始终为 Markdown） =====
        if (!isStreaming) {
            var trimmed = content.trim();
            var hasHtmlTags = MD_HTML_TAG_RE.test(trimmed);
            var hasMarkdown = MD_MARKDOWN_RE.test(trimmed);
            var isPureHtml = (hasHtmlTags || MD_HTML_START_RE.test(trimmed)) && !hasMarkdown;
            if (isPureHtml) {
                content = content.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function(match, text, href) {
                    if (isDangerousUrl(href)) {
                        return text;
                    }
                    return '<a href="' + escapeHtml(href) + '" target="_blank" rel="noopener">' + text + '</a>';
                });
                content = sanitizeHtml(content);
                if (mathPlaceholders.length > 0) {
                    content = restoreMathPlaceholders(content, mathPlaceholders);
                }
                return content;
            }
        }

        // 如果已经提取了数学公式，传递给 renderWithMarked/fallbackMarkedParse
        // ===== 优先使用 marked =====
        if (markedLoaded && typeof marked !== 'undefined') {
            var html = renderWithMarked(content, getString, mathPlaceholders);
            // 非流式模式：对完整响应应用 DOMParser 净化（防御纵深）。
            // renderWithMarked 内的 renderer.html 用正则过滤危险标签/属性，但正则无法
            // 覆盖所有绕过手法（嵌套标签、属性分割等）。DOMParser 方案更可靠。
            // 流式模式不应用此净化：增量更新时内容可能包含不完整标签，DOMParser 会
            // 破坏增量渲染；且流式 token 是碎片化的，XSS 风险远低于完整响应。
            if (!isStreaming && html) {
                html = sanitizeHtml(html);
            }
            return html;
        }

        // ===== marked 未加载完成时使用轻量级备用渲染 =====
        var fallbackHtml = fallbackMarkedParse(content, getString, mathPlaceholders);
        // 非流式模式：对备用渲染输出同样应用 DOMParser 净化（防御纵深）。
        // fallbackMarkedParse 的正则替换不覆盖原始 HTML（如 AI 响应中的 <script> 标签
        // 会原样通过），必须与 marked 主路径保持一致的 sanitizeHtml 防护。
        // 流式模式不应用此净化：增量更新时内容可能包含不完整标签，DOMParser 会
        // 破坏增量渲染。
        if (!isStreaming && fallbackHtml) {
            fallbackHtml = sanitizeHtml(fallbackHtml);
        }
        return fallbackHtml;
    }

    // ===================== KaTeX 加载和渲染 =====================

    var KATEX_LOCAL_JS = M.cfg.wwwroot + '/local/ai_programming_assistant/js/katex.min.js';
    var KATEX_LOCAL_CSS = M.cfg.wwwroot + '/local/ai_programming_assistant/js/katex.min.css';
    var KATEX_CDN_BASE = 'https://cdn.jsdelivr.net/npm/katex@0.16.11/dist';

    function loadKaTeX(callback) {
        if (typeof katex !== 'undefined') {
            if (callback) { callback(); }
            return;
        }
        var existingScript = document.querySelector('script[data-katex="loading"]');
        if (existingScript) {
            // 另一个 loadKaTeX 调用正在加载中：挂载 load 事件监听替代 setInterval 轮询
            var onKatexLoad = function() {
                existingScript.removeEventListener('load', onKatexLoad);
                if (callback) { callback(); }
            };
            existingScript.addEventListener('load', onKatexLoad);
            // 超时兜底：10 秒后仍未加载完成则直接回调走降级路径
            var katexTimeoutId = setTimeout(function() {
                existingScript.removeEventListener('load', onKatexLoad);
                if (callback) { callback(); }
            }, 10000);
            // load 成功时清理超时定时器
            existingScript.addEventListener('load', function() {
                clearTimeout(katexTimeoutId);
            }, {once: true});
            return;
        }
        var isLoading = true;

        function loadCSS(src) {
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = src;
            link.crossOrigin = 'anonymous';
            document.head.appendChild(link);
        }

        function loadScript(src, onloadFn, onerrorFn) {
            // 替换 define 配合 define.amd 保留策略：
            // - 保留 define.amd 标志，确保加载期间 RequireJS 管理的模块不受影响
            // - 命名 define('module/name', [...], fn) → 放行到 RequireJS
            // - 匿名 define([...], fn) → 手动执行 factory 初始化库（如 KaTeX），
            //   避免 RequireJS 拦截后报 "Mismatched anonymous define()"
            var savedDefine;
            var needsGuard = typeof window.define === 'function' && window.define.amd;
            if (needsGuard) {
                savedDefine = window.define;
                window.define = function(deps, factory) {
                    // 命名模块（第一个参数是字符串）→ 放行到 RequireJS
                    if (arguments.length >= 1 && typeof arguments[0] === 'string') {
                        return savedDefine.apply(window, arguments);
                    }
                    // 匿名 define() → 手动执行 factory 初始化
                    // KaTeX UMD: define(['exports'], factory)
                    if (Array.isArray(deps) && typeof factory === 'function') {
                        var mockExports = {};
                        factory(mockExports);
                        window.katex = mockExports;
                    } else if (typeof deps === 'function') {
                        // define(factory) 形式
                        var result = deps();
                        if (result) { window.katex = result; }
                    }
                };
                window.define.amd = savedDefine.amd;  // 保留 define.amd！
            }
            var script = document.createElement('script');
            script.src = src;
            script.crossOrigin = 'anonymous';
            script.setAttribute('data-katex', 'loading');
            script.onload = function() {
                script.removeAttribute('data-katex');
                if (needsGuard) { window.define = savedDefine; }
                if (onloadFn) { onloadFn(); }
            };
            script.onerror = function() {
                script.removeAttribute('data-katex');
                if (needsGuard) { window.define = savedDefine; }
                if (onerrorFn) { onerrorFn(); }
            };
            document.head.appendChild(script);
        }

        /** @type {number|null} 本地加载超时 ID */
        var localTimeoutId = null;

        function clearKaTeXTimeout() {
            if (localTimeoutId !== null) {
                clearTimeout(localTimeoutId);
                localTimeoutId = null;
            }
        }

        function tryLocal() {
            loadCSS(KATEX_LOCAL_CSS);
            loadScript(KATEX_LOCAL_JS, function() {
                clearKaTeXTimeout();
                isLoading = false;
                if (callback) { callback(); }
            }, function() {
                clearKaTeXTimeout();
                // 本地加载失败，切换到 CDN
                isLoading = false;
                tryCDN();
            });
            // 超时保护：10秒后仍未加载完成则切换到 CDN
            localTimeoutId = setTimeout(function() {
                localTimeoutId = null;
                if (isLoading && typeof katex === 'undefined') {
                    isLoading = false;
                    tryCDN();
                }
            }, 10000);
        }

        function tryCDN() {
            clearKaTeXTimeout();
            // 清除可能残留的 data-katex 标记
            var existingScript = document.querySelector('script[data-katex="loading"]');
            if (existingScript) { existingScript.removeAttribute('data-katex'); }
            loadCSS(KATEX_CDN_BASE + '/katex.min.css');
            loadScript(KATEX_CDN_BASE + '/katex.min.js', function() {
                if (callback) { callback(); }
            }, function() {
                // CDN 也加载失败，调用 callback 走降级路径
                if (callback) { callback(); }
            });
        }

        tryLocal();
    }

    function scanLatexInDom(container) {
        var LATEX_DISPLAY_RE = /\[\s*([^\[\]]*\\[a-zA-Z]+[^\[\]]*)\s*\]/g;
        var textNodes = [];
        var walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT, null, false);
        while (walker.nextNode()) {
            var node = walker.currentNode;
            if (node.parentNode && node.parentNode.classList && node.parentNode.classList.contains('math')) {
                continue;
            }
            LATEX_DISPLAY_RE.lastIndex = 0;
            if (LATEX_DISPLAY_RE.test(node.textContent)) {
                textNodes.push(node);
            }
        }
        for (var i = 0; i < textNodes.length; i++) {
            var node = textNodes[i];
            var text = node.textContent;
            LATEX_DISPLAY_RE.lastIndex = 0;
            var fragment = document.createDocumentFragment();
            var lastIdx = 0;
            var match;
            while ((match = LATEX_DISPLAY_RE.exec(text)) !== null) {
                if (match.index > lastIdx) {
                    fragment.appendChild(document.createTextNode(text.substring(lastIdx, match.index)));
                }
                var mathEl = document.createElement('div');
                mathEl.className = 'math math-display';
                mathEl.setAttribute('data-tex', match[1].trim());
                mathEl.textContent = match[0];
                fragment.appendChild(mathEl);
                lastIdx = match.index + match[0].length;
            }
            if (lastIdx < text.length) {
                fragment.appendChild(document.createTextNode(text.substring(lastIdx)));
            }
            node.parentNode.replaceChild(fragment, node);
        }
    }

    function renderMath(container) {
        container = container || document;
        function doRender() {
            // 每次执行时重新查询 DOM，不依赖闭包中捕获的静态 NodeList
            var elements = container.querySelectorAll('.math:not(.katex-rendered)');
            if (elements.length === 0) { return; }
            for (var i = 0; i < elements.length; i++) {
                var el = elements[i];
                if (el.classList.contains('katex-rendered')) { continue; }
                var tex = el.getAttribute('data-tex');
                if (tex === null) {
                    tex = el.textContent
                        .replace(/^\\\[/, '').replace(/\\\]$/, '')
                        .replace(/^\\\(/, '').replace(/\\\)$/, '')
                        .trim();
                } else {
                    tex = tex.replace(/\\+\s*$/, '').trim();
                }
                var isDisplay = el.classList.contains('math-display');
                try {
                    katex.render(tex, el, {displayMode: isDisplay, throwOnError: false});
                    el.classList.add('katex-rendered');
                } catch (e) {
                    console.warn('[ai] KaTeX error:', e.message, 'tex:', tex);
                    // 标记为已尝试渲染，避免重复尝试相同失败元素
                    el.classList.add('katex-rendered');
                }
            }
        }
        if (typeof katex === 'undefined') {
            loadKaTeX(doRender);
        } else {
            doRender();
        }
    }

    /** @type {MutationObserver|null} 监视 .math 元素进入 DOM 的观察器 */
    var mathObserver = null;

    /**
     * 启动 MutationObserver 监视：当日志容器中出现新的 .math 元素时自动渲染。
     * 作为 renderMath 显式调用的兜底，处理所有边界情况（包括历史上已加载的消息）。
     */
    function watchMath(logContainer) {
        if (mathObserver) {
            mathObserver.disconnect();
        }
        // 清理旧的防抖和重试定时器，防止断开后仍触发无意义回调
        if (watchMath._debounceTimer) {
            clearTimeout(watchMath._debounceTimer);
            watchMath._debounceTimer = null;
        }
        if (watchMath._retryTimer) {
            clearTimeout(watchMath._retryTimer);
            watchMath._retryTimer = null;
        }
        // 容器不存在时延迟重试（最多 20 次，共 10 秒）
        if (!logContainer || !logContainer.nodeType) {
            watchMath._retryCount = (watchMath._retryCount || 0) + 1;
            if (watchMath._retryCount <= 20) {
                watchMath._retryTimer = setTimeout(function() {
                    watchMath._retryTimer = null;
                    watchMath(document.getElementById('ai-assistant-log'));
                }, 500);
            }
            return;
        }
        // 容器有效时重置重试计数，为后续重新绑定保留重试能力
        watchMath._retryCount = 0;
        // 立即扫描一次：渲染容器内已有的 .math 元素
        renderMath(logContainer);
        watchMath._debounceTimer = null;
        mathObserver = new MutationObserver(function(mutations) {
            if (typeof window !== 'undefined' && typeof window.__aiRenderingHistory === 'function' && window.__aiRenderingHistory()) {
                return;
            }
            for (var m = 0; m < mutations.length; m++) {
                for (var n = 0; n < mutations[m].addedNodes.length; n++) {
                    var node = mutations[m].addedNodes[n];
                    if (node.nodeType === 1) {
                        if ((node.matches && node.matches('.math:not(.katex-rendered)')) ||
                            (node.querySelector && node.querySelector('.math:not(.katex-rendered)'))) {
                            if (watchMath._debounceTimer) { clearTimeout(watchMath._debounceTimer); }
                            watchMath._debounceTimer = setTimeout(function() {
                                renderMath(logContainer);
                                watchMath._debounceTimer = null;
                            }, 100);
                            return;
                        }
                    }
                }
            }
        });
        mathObserver.observe(logContainer, {childList: true, subtree: true});
    }

    // ===================== 知识库引用链接转换 =====================

    /**
     * 将文本中的知识库引用标注转为可点击的 HTML 链接（前端版本）
     *
     * 支持格式:
     *   【来源:文件名,第X段】       — 标准格式
     *   【来源:文件名,章节,第X段】  — AI 可能插入额外上下文
     *   【参考:文件名,第X段】       — 参考格式
     *   【来源:文件名】             — 无段落编号
     *
     * 使用双分支正则：
     *   分支1: 有段落编号 — 文件名在第一个逗号前，段落编号取最后一个 ,第N段
     *   分支2: 无段落编号 — 仅文件名
     *
     * @param {string} text 含引用标注的文本
     * @returns {string} 替换为 HTML 链接后的文本
     */
    /** @type {Object|null} 知识库 fileid→filename 映射（来自 SSE kb_metadata 事件） */
    var _kbFileMap = null;

    /**
     * 获取知识库文件在 kb_view.php 中的链接 URL
     *
     * 优先使用 fileid（确定且高效），无映射时回退到按 name 模糊查询。
     *
     * @param {string} fname 文件名
     * @param {number} chunkNum 段落编号（0 表示不指定）
     * @returns {string} 完整 URL
     */
    function getKbFileUrl(fname, chunkNum) {
        var KB_VIEW_URL = M.cfg.wwwroot + '/local/ai_programming_assistant/kb_view.php';
        var url;
        if (_kbFileMap && _kbFileMap[fname]) {
            url = KB_VIEW_URL + '?fileid=' + _kbFileMap[fname];
        } else {
            url = KB_VIEW_URL + '?name=' + encodeURIComponent(fname);
        }
        if (chunkNum > 0) { url += '#chunk-' + chunkNum; }
        return url;
    }

    function convertKbCitationLinks(text) {
        if (!text || text.indexOf('\u3010') === -1) { return text; }
        // 分支1: 【来源:FILENAME,EXTRA?,第N段】 — filename 不含逗号和】，extra 为中间可选上下文
        // 分支2: 【来源:FILENAME】 — 仅文件名，无段落编号
        return text.replace(
            /\u3010(?:\u6765\u6E90|\u53C2\u8003):([^,\u3011]+)(?:,[^\u3011]*?)?,\u7B2C(\d+)\u6BB5?\u3011|\u3010(?:\u6765\u6E90|\u53C2\u8003):([^,\u3011]+?)\u3011/g,
            function(match, fname1, chunkStr, fname2) {
                var fname = (fname1 || fname2 || '').replace(/\s+$/, '');
                if (!fname) { return match; }
                var chunkNum = chunkStr ? parseInt(chunkStr, 10) : 0;
                var suffix = chunkNum > 0 ? '(\u7B2C' + chunkNum + '\u6BB5)' : '';
                var url = getKbFileUrl(fname, chunkNum);
                return '\u3010\u6765\u6E90:<a href="' + escapeHtml(url)
                    + '" class="ai-kb-link" target="_blank" rel="noopener">'
                    + escapeHtml(fname) + '</a>' + suffix + '\u3011';
            }
        );
    }

    /**
     * 将元素文本节点中的知识库引用标注转为可点击的 HTML 链接
     *
     * 使用 TreeWalker 遍历所有文本节点，查找并替换引用标注。
     * 仅处理包含【来源:或【参考:的文本节点，避免不必要的 DOM 操作。
     *
     * @param {Element} el 要处理的 DOM 元素
     */
    /** @type {HTMLSpanElement|null} 可复用的临时元素，用于 convertKbCitationLinksInElement */
    var _citationTempSpan = null;

    function convertKbCitationLinksInElement(el) {
        if (!el || !el.isConnected) { return; }
        var CITATION_RE = /\u3010(?:\u6765\u6E90|\u53C2\u8003):/;
        var textNodes = [];
        var walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null, false);
        while (walker.nextNode()) {
            var node = walker.currentNode;
            if (node.parentNode && node.parentNode.closest && node.parentNode.closest('.ai-kb-link')) {
                continue;
            }
            if (CITATION_RE.test(node.textContent)) {
                textNodes.push(node);
            }
        }
        for (var i = 0; i < textNodes.length; i++) {
            var textNode = textNodes[i];
            var converted = convertKbCitationLinks(textNode.textContent);
            if (converted !== textNode.textContent) {
                if (!_citationTempSpan) {
                    _citationTempSpan = document.createElement('span');
                }
                _citationTempSpan.innerHTML = converted;
                var frag = document.createDocumentFragment();
                while (_citationTempSpan.firstChild) { frag.appendChild(_citationTempSpan.firstChild); }
                textNode.parentNode.replaceChild(frag, textNode);
            }
        }
    }

    // ===================== 流式渲染 =====================

    /**
     * 创建流式响应处理器
     * 封装通用的流式响应处理逻辑，减少重复代码
     */
    function createStreamHandler(options) {
        var contentId = options.contentId;
        var gs = options.getString || function(k, fb) { return fb || k; };
        var elapsedTimer = null;
        var renderTimer = null;
        var thinkingRenderTimer = null;
        var settled = false;
        var pendingContent = '';
        var pendingThinking = '';
        var lastRenderedContent = '';
        var lastRenderedThinking = '';
        var hasThinking = false;
        var cachedRaw = '';
        var cachedCdiv = null;
        var cachedStableEl = null;
        var cachedTailEl = null;
        var cachedLogEl = null;
        var cachedThinkingEl = null;
        var cachedThinkingContentEl = null;
        var lastTailRendered = '';
        var lastHighlightedStableIndex = 0;

        function getElements() {
            if (cachedCdiv && cachedCdiv.isConnected) {
                return {
                    cdiv: cachedCdiv, stableEl: cachedStableEl, tailEl: cachedTailEl,
                    logEl: cachedLogEl, thinkingEl: cachedThinkingEl, thinkingContentEl: cachedThinkingContentEl
                };
            }
            cachedCdiv = document.getElementById(contentId);
            if (!cachedCdiv) { return null; }
            cachedThinkingEl = cachedCdiv.querySelector('.ai-thinking-block');
            cachedThinkingContentEl = cachedCdiv.querySelector('.ai-thinking-content');
            var streamInner = cachedCdiv.querySelector('.ai-stream-inner');
            if (streamInner) {
                cachedStableEl = streamInner.children[0] || null;
                cachedTailEl = streamInner.children[1] || null;
            } else {
                cachedStableEl = null;
                cachedTailEl = null;
            }
            cachedLogEl = cachedCdiv.closest('.ai-assistant-log');
            return {
                cdiv: cachedCdiv, stableEl: cachedStableEl, tailEl: cachedTailEl,
                logEl: cachedLogEl, thinkingEl: cachedThinkingEl, thinkingContentEl: cachedThinkingContentEl
            };
        }

        function ensureStructure() {
            var els = getElements();
            if (!els || !els.cdiv) { return null; }
            // 首个 token 到达时移除超时提示（.ai-stream-timeout-hint），避免残留于回答上方
            var hintEls = els.cdiv.querySelectorAll('.ai-stream-timeout-hint');
            for (var hi = 0; hi < hintEls.length; hi++) {
                hintEls[hi].remove();
            }
            if (hasThinking && (!cachedThinkingEl || !cachedThinkingEl.isConnected)) {
                // 使用 insertAdjacentHTML 前置 thinking 块，保留已有内容
                // 避免 innerHTML 全量替换丢失已渲染的 stream 内容（thinking 晚于 token 到达的边界情况）
                els.cdiv.insertAdjacentHTML('afterbegin',
                    '<div class="ai-thinking-block ai-thinking-active">' +
                    '<div class="ai-thinking-header">' +
                    '<span class="ai-thinking-icon"><i class="fa fa-brain"></i></span>' +
                    '<span class="ai-thinking-label">' + escapeHtml(gs('thinking_in_progress', 'AI 正在思考...')) + '</span>' +
                    '<button class="ai-thinking-toggle btn btn-sm btn-link" type="button"><i class="fa fa-chevron-up"></i></button>' +
                    '</div><div class="ai-thinking-content"></div></div>'
                );
                // 确保 stream-inner 在末尾存在
                if (!els.cdiv.querySelector('.ai-stream-inner')) {
                    els.cdiv.insertAdjacentHTML('beforeend',
                        '<div class="ai-stream-inner"><div class="ai-stream-stable"></div><div class="ai-stream-tail"></div></div>'
                    );
                }
                cachedThinkingEl = els.cdiv.querySelector('.ai-thinking-block');
                cachedThinkingContentEl = els.cdiv.querySelector('.ai-thinking-content');
                var streamInner = els.cdiv.querySelector('.ai-stream-inner');
                if (streamInner) {
                    cachedStableEl = streamInner.children[0] || null;
                    cachedTailEl = streamInner.children[1] || null;
                }
                var toggleBtn = cachedThinkingEl.querySelector('.ai-thinking-toggle');
                if (toggleBtn) {
                    var thinkingBlockEl = cachedThinkingEl;
                    var toggleIconEl = toggleBtn.querySelector('i');
                    toggleBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        thinkingBlockEl.classList.toggle('ai-thinking-collapsed');
                        var isCollapsed = thinkingBlockEl.classList.contains('ai-thinking-collapsed');
                        thinkingBlockEl.dataset.userToggled = '1';
                        if (toggleIconEl) {
                            toggleIconEl.className = isCollapsed ? 'fa fa-chevron-down' : 'fa fa-chevron-up';
                        }
                    });
                }
            }
            if (!hasThinking && !els.cdiv.querySelector('.ai-stream-inner')) {
                // 使用 insertAdjacentHTML 追加而非全量替换，保留可能存在的加载指示器等已有内容
                els.cdiv.insertAdjacentHTML('beforeend',
                    '<div class="ai-stream-inner"><div class="ai-stream-stable"></div><div class="ai-stream-tail"></div></div>'
                );
                var streamEl = els.cdiv.querySelector('.ai-stream-inner');
                cachedStableEl = streamEl.children[0];
                cachedTailEl = streamEl.children[1];
            }
            return getElements();
        }

        function renderThinkingContent(content, isFinal) {
            var els = ensureStructure();
            if (!els || !els.thinkingContentEl) { return; }
            if (isFinal) {
                els.thinkingContentEl.innerHTML = markedParse(content, false, gs);
                scanLatexInDom(els.thinkingContentEl);
                renderMath(els.thinkingContentEl);
                ensurePrism(function() {
                    try { Prism.highlightAllUnder(els.thinkingContentEl); } catch (e) { console.warn('[AI Programming Assistant]', e); }
                });
            } else {
                els.thinkingContentEl.textContent = content;
            }
            if (els.logEl) { scrollToBottom(els.logEl); }
        }

        function scheduleThinkingRender() {
            if (thinkingRenderTimer) { return; }
            thinkingRenderTimer = setTimeout(function() {
                thinkingRenderTimer = null;
                var content = pendingThinking;
                if (content && content !== lastRenderedThinking) {
                    renderThinkingContent(content, false);
                    lastRenderedThinking = content;
                }
            }, RENDER_DEBOUNCE_DELAY);
        }

        // 缓存 findLastSafeBreak 的扫描状态，避免每次从头遍历
        var _safeBreakCache = {pos: 0, inCodeBlock: false, lastBreak: -1, contentLen: 0};

        function findLastSafeBreak(content) {
            var len = content.length;
            // 非递增场景（内容缩短或完全不同）重置缓存
            if (len < _safeBreakCache.contentLen || len === 0) {
                _safeBreakCache = {pos: 0, inCodeBlock: false, lastBreak: -1, contentLen: 0};
            }
            var i = _safeBreakCache.pos;
            var inCodeBlock = _safeBreakCache.inCodeBlock;
            var lastSafeBreak = _safeBreakCache.lastBreak;
            // 使用 charCodeAt 替代 substring 比较，避免每次迭代创建临时字符串
            // ``` = charCode 96, \n = charCode 10
            while (i < len) {
                if (content.charCodeAt(i) === 96 && content.charCodeAt(i + 1) === 96 && content.charCodeAt(i + 2) === 96) {
                    inCodeBlock = !inCodeBlock;
                    i += 3;
                    continue;
                }
                if (!inCodeBlock && content.charCodeAt(i) === 10 && content.charCodeAt(i + 1) === 10) {
                    lastSafeBreak = i;
                    i += 2;
                    continue;
                }
                i++;
            }
            _safeBreakCache = {pos: i, inCodeBlock: inCodeBlock, lastBreak: lastSafeBreak, contentLen: len};
            return lastSafeBreak;
        }

        function renderContent(content) {
            var els = ensureStructure();
            if (!els) { return; }
            var stableEl = els.stableEl;
            var tailEl = els.tailEl;
            var logEl = els.logEl;
            var lastBreak = findLastSafeBreak(content);
            var stableUpdated = false;
            var tailUpdated = false;
            if (lastBreak >= 0) {
                var stablePart = content.substring(0, lastBreak + 2);
                var tailPart = content.substring(lastBreak + 2);
                if (stablePart !== cachedRaw) {
                    var newDelta = stablePart.substring(cachedRaw.length);
                    if (newDelta) {
                        var beforeCount = stableEl.children.length;
                        stableEl.insertAdjacentHTML('beforeend', markedParse(newDelta, true, gs));
                        // 增量处理：仅对新子节点执行 KB 引用转换和 KaTeX 渲染，
                        // 避免全量 TreeWalker 遍历稳定区累积的子节点
                        for (var ci = beforeCount; ci < stableEl.children.length; ci++) {
                            var child = stableEl.children[ci];
                            convertKbCitationLinksInElement(child);
                            renderMath(child);
                        }
                        stableUpdated = true;
                        tailEl.textContent = '';
                        lastTailRendered = '';
                    }
                    cachedRaw = stablePart;
                }
                if (tailPart !== lastTailRendered) {
                    tailEl.innerHTML = markedParse(tailPart, true, gs);
                    convertKbCitationLinksInElement(tailEl);
                    renderMath(tailEl);
                    tailUpdated = true;
                    lastTailRendered = tailPart;
                }
            } else {
                if (content !== lastTailRendered) {
                    tailEl.innerHTML = markedParse(content, true, gs);
                    convertKbCitationLinksInElement(tailEl);
                    renderMath(tailEl);
                    tailUpdated = true;
                    lastTailRendered = content;
                }
            }
            // 注：stable 的 KB 引用转换已在增量路径中完成
            // 如果 tail 未更新，其 KB 引用转换在上次更新时已完成，无需重复 TreeWalker 遍历
            ensurePrism(function() {
                if (stableUpdated !== false) {
                    var children = stableEl.children;
                    for (var hi = lastHighlightedStableIndex; hi < children.length; hi++) {
                        try { Prism.highlightAllUnder(children[hi]); } catch (e) { console.warn('[AI Programming Assistant]', e); }
                    }
                    lastHighlightedStableIndex = children.length;
                }
                if (tailUpdated && tailEl.children.length > 0) {
                    try { Prism.highlightAllUnder(tailEl); } catch (e) { console.warn('[AI Programming Assistant]', e); }
                }
            });
            if (stableUpdated !== false || tailUpdated) {
                scrollToBottom(logEl);
            }
        }

        function scheduleRender() {
            if (renderTimer) { return; }
            renderTimer = setTimeout(function() {
                renderTimer = null;
                var content = pendingContent;
                if (content && content !== lastRenderedContent) {
                    renderContent(content);
                    lastRenderedContent = content;
                }
            }, RENDER_DEBOUNCE_DELAY);
        }

        function flushRender() {
            if (renderTimer) { clearTimeout(renderTimer); renderTimer = null; }
            if (thinkingRenderTimer) { clearTimeout(thinkingRenderTimer); thinkingRenderTimer = null; }
            if (pendingThinking && pendingThinking !== lastRenderedThinking) {
                renderThinkingContent(pendingThinking, true);
                lastRenderedThinking = pendingThinking;
            }
            if (pendingContent && pendingContent !== lastRenderedContent) {
                var els = getElements();
                if (els && els.stableEl && els.tailEl) {
                    var needsFullRender = cachedRaw !== '' && pendingContent !== cachedRaw;
                    if (needsFullRender) {
                        els.stableEl.textContent = '';
                        els.tailEl.textContent = '';
                        cachedRaw = '';
                        lastTailRendered = '';
                        _safeBreakCache = {pos: 0, inCodeBlock: false, lastBreak: -1, contentLen: 0};
                        lastHighlightedStableIndex = 0;
                    }
                }
                renderContent(pendingContent);
                lastRenderedContent = pendingContent;
                els = getElements();
                if (els && els.logEl) { scrollToBottom(els.logEl); }
            }
            finalizeThinking();
            // 清除所有闭包持有的引用，帮助 GC 回收内存
            cleanupRefs();
        }

        var thinkingFinalized = false;

        function finalizeThinking() {
            if (thinkingFinalized || !hasThinking || !cachedThinkingEl || !cachedThinkingEl.isConnected) {
                thinkingFinalized = true;
                return;
            }
            thinkingFinalized = true;
            if (pendingThinking && pendingThinking !== lastRenderedThinking) {
                renderThinkingContent(pendingThinking, true);
                lastRenderedThinking = pendingThinking;
            }
            cachedThinkingEl.classList.remove('ai-thinking-active');
            var lbl = cachedThinkingEl.querySelector('.ai-thinking-label');
            if (lbl) {
                var tlen = pendingThinking ? pendingThinking.length : 0;
                var lstr = tlen >= 1000 ? (tlen / 1000).toFixed(1) + 'k' : tlen.toString();
                lbl.textContent = gs('thinking_label', '思考过程') + ' ' + lstr;
            }
            if (cachedThinkingEl.dataset.userToggled !== '1') {
                cachedThinkingEl.classList.add('ai-thinking-collapsed');
                var ic = cachedThinkingEl.querySelector('.ai-thinking-toggle i');
                if (ic) { ic.className = 'fa fa-chevron-down'; }
            }
        }

        function startTimer() {
            elapsedTimer = setTimeout(function() {
                elapsedTimer = null;
                if (!settled) {
                    // 惰性解析容器：定时器触发时可能尚无任何渲染调用，cachedCdiv 仍为 null
                    var els = getElements();
                    if (els && els.cdiv && els.cdiv.isConnected) {
                        // 仅在仍等待首个 token（内容区尚无 stream/thinking 结构）时插入提示，
                        // 避免与已到达的部分内容冲突。原实现查找 .spinner-border（从未创建）
                        // 恒为 null 属死代码。
                        if (!els.cdiv.querySelector('.ai-stream-inner, .ai-thinking-block')) {
                            var hint = document.createElement('span');
                            hint.className = 'ai-stream-timeout-hint text-warning';
                            hint.textContent = '⏱ ' + gs('ai_feedback_loading', 'AI 正在分析...');
                            els.cdiv.appendChild(hint);
                        }
                    }
                }
            }, TIMEOUT_WARNING_DELAY);
        }

        function clearTimers() {
            if (elapsedTimer) { clearTimeout(elapsedTimer); elapsedTimer = null; }
            if (renderTimer) { clearTimeout(renderTimer); renderTimer = null; }
            if (thinkingRenderTimer) { clearTimeout(thinkingRenderTimer); thinkingRenderTimer = null; }
        }

        function cleanupRefs() {
            cachedRaw = '';
            lastTailRendered = '';
            pendingContent = '';
            pendingThinking = '';
            lastRenderedContent = '';
            lastRenderedThinking = '';
            _safeBreakCache = {pos: 0, inCodeBlock: false, lastBreak: -1, contentLen: 0};
            cachedCdiv = null;
            cachedStableEl = null;
            cachedTailEl = null;
            cachedLogEl = null;
            cachedThinkingEl = null;
            cachedThinkingContentEl = null;
        }

        startTimer();

        return {
            onThinking: function(token, fullThinking) {
                hasThinking = true;
                pendingThinking = fullThinking;
                scheduleThinkingRender();
            },
            onToken: function(token, fullContent) {
                if (hasThinking && !thinkingFinalized) { finalizeThinking(); }
                pendingContent = fullContent;
                scheduleRender();
            },
            onComplete: function(data) {
                settled = true;
                var finalThinking = data.thinkingContent || (data.result && data.result.thinking_content) || '';
                if (finalThinking) {
                    hasThinking = true;
                    pendingThinking = finalThinking;
                }
                var finalContent = data.content || (data.result && data.result.message) || '';
                if (finalContent) {
                    pendingContent = finalContent;
                }
                flushRender();
                clearTimers();
                if (options.onComplete) { options.onComplete(data); }
                // 外部回调完成后立即释放闭包中的 DOM 引用，不等 GC 自然回收
                cleanupRefs();
            },
            onError: function(message) {
                settled = true;
                clearTimers();
                if (cachedCdiv && cachedCdiv.isConnected) { cachedCdiv.remove(); }
                if (options.onError) { options.onError(message); }
                // 清除所有闭包持有的引用，防止内存泄漏
                cleanupRefs();
            },
            // 无内容时被中止（用户点击停止等）：静默清理并通知外部复位界面，
            // 避免与 onError 一样展示错误提示和重试按钮
            onAbort: function() {
                settled = true;
                clearTimers();
                if (cachedCdiv && cachedCdiv.isConnected) { cachedCdiv.remove(); }
                if (options.onAbort) { options.onAbort(); }
                cleanupRefs();
            }
        };
    }

    // ===================== SSE 流式连接 =====================

    function fetchSSE(url, body, callbacks) {
        var controller = new AbortController();
        body.sesskey = M.cfg.sesskey;
        // 清空上一轮会话的知识库文件映射，防止旧引用残留
        _kbFileMap = null;
        var lastDataTime = Date.now();
        var reader = null;
        var settleOnce = createSettleOnce(function() {
            clearTimeout(noDataTimer);
            clearTimeout(hardTimer);
            clearTimeout(connectTimer);
            if (reader) { try { reader.cancel().catch(function() {}); } catch (e) {} reader = null; }
        });
        var noDataTimer = setTimeout(function checkNoData() {
            var elapsed = Date.now() - lastDataTime;
            if (elapsed >= STREAM_NO_DATA_TIMEOUT) {
                controller.abort();
                settleOnce(callbacks.onError, gs('error_timeout_no_data', 'Request timeout') + ' (no data for ' + Math.round(elapsed / 1000) + 's)');
            } else {
                noDataTimer = setTimeout(checkNoData, STREAM_NO_DATA_TIMEOUT - elapsed);
            }
        }, STREAM_NO_DATA_TIMEOUT);
        var hardTimer = setTimeout(function() {
            controller.abort();
            settleOnce(callbacks.onError, gs('error_timeout_hard', 'Request timeout (hard limit)'));
        }, STREAM_HARD_TIMEOUT);
        var connectTimer = setTimeout(function() {
            controller.abort();
            settleOnce(callbacks.onError, gs('error_connection_timeout', 'Connection timeout'));
        }, STREAM_CONNECT_TIMEOUT);
        var parseState = {
            fullContent: '',
            thinkingContent: '',
            completeReceived: false,
            doneReceived: false
        };

        // 处理 AbortError：有内容时正常完成；无内容时走 onAbort 复位界面，
        // 不能静默 settle(null, null)，否则调用方无法复位发送状态导致界面卡死
        function handleAbortError() {
            if (!parseState.completeReceived && parseState.fullContent.length > 0) {
                settleOnce(callbacks.onComplete, {
                    result: {success: true, message: parseState.fullContent, thinking_content: parseState.thinkingContent, finish_reason: 'user_cancelled', truncated: true, model: '', from_cache: false},
                    content: parseState.fullContent, thinkingContent: parseState.thinkingContent
                });
            } else if (!parseState.completeReceived && !parseState.doneReceived) {
                settleOnce(callbacks.onAbort || callbacks.onError, '');
            }
        }
        fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(body),
            signal: controller.signal
        }).then(function(response) {
            clearTimeout(connectTimer);
            if (!response.ok) {
                response.text().then(function(text) {
                    var errMsg = 'HTTP ' + response.status;
                    if (text && text.trim()) {
                        try {
                            var errData = JSON.parse(text);
                            if (errData && errData.error) {
                                errMsg = errData.error;
                            } else if (errData && errData.message) {
                                errMsg = errData.message;
                            }
                        } catch (e) {
                            if (text.length < 200) {
                                errMsg = text;
                            }
                        }
                    }
                    settleOnce(callbacks.onError, errMsg);
                }).catch(function() {
                    settleOnce(callbacks.onError, 'HTTP ' + response.status);
                });
                return;
            }
            if (!response.body) {
                settleOnce(callbacks.onError, gs('error_no_body', 'Response has no body stream'));
                return;
            }
            reader = response.body.getReader();
            var decoder = new TextDecoder('utf-8', {stream: true});
            var buffer = '';

            function processEventBlock(eventBlock) {
                var eventType = '';
                var dataLines = [];
                var lines = eventBlock.split('\n');
                var hasContent = false;
                for (var i = 0; i < lines.length; i++) {
                    if (lines[i].indexOf('event: ') === 0) { eventType = lines[i].substring(7); hasContent = true; }
                    else if (lines[i].indexOf('data: ') === 0) { dataLines.push(lines[i].substring(6)); hasContent = true; }
                    else if (lines[i].charAt(0) === ':') { hasContent = true; }
                }
                if (!hasContent) { return; }
                var eventData = dataLines.join('\n');
                if (!eventData && eventType === '') { return; }
                if (!eventData) {
                    if (eventType === 'done') {
                        parseState.doneReceived = true;
                        settleOnce(callbacks.onError, gs('error_stream_ended', 'Stream ended without completion'));
                    }
                    return;
                }
                var parsed;
                try { parsed = JSON.parse(eventData); } catch (e) {
                    console.warn('[AI Programming Assistant] JSON parse error in SSE event:', e.message, 'Raw data:', eventData.substring(0, 200));
                    return;
                }
                switch (eventType) {
                    case 'thinking':
                        if (callbacks.onThinking) {
                            // 字符串守卫：服务端/代理异常时 content 可能缺失或非字符串，
                            // += undefined 会把字面量 "undefined" 拼进用户可见内容。
                            var t = typeof parsed.content === 'string' ? parsed.content : (parsed.content == null ? '' : String(parsed.content));
                            parseState.thinkingContent += t;
                            callbacks.onThinking(t, parseState.thinkingContent);
                        }
                        break;
                    case 'kb_metadata':
                        if (parsed && parsed.files) {
                            _kbFileMap = parsed.files;
                        }
                        break;
                    case 'token':
                        // 字符串守卫：同上，防止非字符串 content 污染回复正文
                        var c = typeof parsed.content === 'string' ? parsed.content : (parsed.content == null ? '' : String(parsed.content));
                        parseState.fullContent += c;
                        if (callbacks.onToken) { callbacks.onToken(c, parseState.fullContent); }
                        break;
                    case 'complete':
                        parseState.completeReceived = true;
                        if (callbacks.onComplete) {
                            settleOnce(callbacks.onComplete, {
                                result: parsed, content: parsed.message || parseState.fullContent, thinkingContent: parsed.thinking_content || parseState.thinkingContent
                            });
                        }
                        break;
                    case 'error':
                        if (callbacks.onError) {
                            settleOnce(callbacks.onError, getErrorMessage(parsed.message, gs('error_unknown', 'Unknown error from server')));
                        }
                        break;
                    case 'done':
                        parseState.doneReceived = true;
                        if (!parseState.completeReceived) {
                            var doneMessage = parsed.message || parseState.fullContent;
                            if (doneMessage.length > 0 && parsed.success) {
                                settleOnce(callbacks.onComplete, {
                                    result: {
                                        success: true, feedbackid: parsed.feedbackid || 0,
                                        message: doneMessage, thinking_content: parsed.thinking_content || parseState.thinkingContent,
                                        finish_reason: 'stop', truncated: false,
                                        model: parsed.model || '', multiturn: parsed.multiturn || false, from_cache: false
                                    },
                                    content: doneMessage, thinkingContent: parsed.thinking_content || parseState.thinkingContent
                                });
                            } else if (callbacks.onError) {
                                settleOnce(callbacks.onError, getErrorMessage(parsed.error, gs('error_stream_ended', 'Stream ended without completion')));
                            }
                        }
                        break;
                }
            }

            function processBuffer(bufferRef) {
                var sepPos;
                var pos = 0;
                while ((sepPos = bufferRef.indexOf('\n\n', pos)) !== -1) {
                    processEventBlock(bufferRef.substring(pos, sepPos));
                    pos = sepPos + 2;
                }
                // 仅在处理过事件时才创建残留缓冲区副本，避免无事件时的不必要分配
                return pos > 0 ? bufferRef.substring(pos) : bufferRef;
            }

            (function readLoop() {
                if (parseState.doneReceived) {
                    reader.cancel().catch(function() {});
                    return;
                }
                reader.read().then(function(result) {
                    lastDataTime = Date.now();
                    if (result.done) {
                        if (buffer) { buffer = processBuffer(buffer); }
                        if (!parseState.doneReceived && !parseState.completeReceived) {
                            if (parseState.fullContent.length > 0) {
                                // 流在收到 done/complete 之前 EOF（Nginx/PHP 超时、进程被杀、网络半关闭）。
                                // 保留已收到的部分内容，但必须标记 truncated，防止前端将其当作完整回答。
                                settleOnce(callbacks.onComplete, {
                                    result: {success: true, message: parseState.fullContent, thinking_content: parseState.thinkingContent, finish_reason: 'eof', truncated: true, model: '', from_cache: false},
                                    content: parseState.fullContent, thinkingContent: parseState.thinkingContent
                                });
                            } else if (buffer && buffer.trim()) {
                                settleOnce(callbacks.onError, gs('error_incomplete_stream', 'Stream ended with incomplete data'));
                            } else {
                                settleOnce(callbacks.onError, gs('error_empty_response', 'Server returned empty response'));
                            }
                        }
                        return;
                    }
                    buffer += decoder.decode(result.value);
                    // 防御性检查：缓冲区超过上限时中止连接，防止无分隔符数据导致 OOM
                    if (buffer.length > MAX_SSE_BUFFER_SIZE) {
                        controller.abort();
                        settleOnce(callbacks.onError, gs('error_buffer_overflow', '响应数据超出缓冲区限制'));
                        return;
                    }
                    buffer = processBuffer(buffer);
                    if (!parseState.doneReceived) {
                        setTimeout(readLoop, 0);
                    }
                }).catch(function(error) {
                    if (error.name === 'AbortError') {
                        handleAbortError();
                    } else {
                        settleOnce(callbacks.onError, getErrorMessage(error, gs('error_stream', 'Stream error')));
                    }
                });
            })();
        }).catch(function(error) {
            clearTimeout(connectTimer);
            if (error.name === 'AbortError') {
                handleAbortError();
            } else {
                settleOnce(callbacks.onError, getErrorMessage(error, gs('error_network', 'Network error')));
            }
        });
        return controller;
    }

    /**
     * 在指定元素上执行 Prism 语法高亮
     * 供外部模块（如 lib.js）使用，避免直接依赖 Prism 闭包变量
     * @param {Element} element 要高亮的容器元素
     */
    function highlightElement(element) {
        if (!element) { return; }
        ensurePrism(function() {
            try { Prism.highlightAllUnder(element); } catch (e) { console.warn('[AI Programming Assistant]', e); }
        });
    }

    /**
     * 销毁 mathObserver 并清理防抖定时器
     * 在页面卸载或面板销毁时调用，防止 MutationObserver 泄漏
     */
    function destroyMathObserver() {
        if (mathObserver) {
            mathObserver.disconnect();
            mathObserver = null;
        }
        if (watchMath._debounceTimer) {
            clearTimeout(watchMath._debounceTimer);
            watchMath._debounceTimer = null;
        }
        if (watchMath._retryTimer) {
            clearTimeout(watchMath._retryTimer);
            watchMath._retryTimer = null;
        }
        watchMath._retryCount = 0;
    }
    // 页面卸载时自动清理 mathObserver
    if (typeof window !== 'undefined' && window.addEventListener) {
        window.addEventListener('beforeunload', destroyMathObserver, {once: true});
    }

    return {
        gs: gs,
        ensurePrism: ensurePrism,
        escapeHtml: escapeHtml,
        scrollToBottom: scrollToBottom,
        fetchSSE: fetchSSE,
        createStreamHandler: createStreamHandler,
        markedParse: markedParse,
        sanitizeHtml: sanitizeHtml,
        getErrorMessage: getErrorMessage,
        loadKaTeX: loadKaTeX,
        scanLatexInDom: scanLatexInDom,
        renderMath: renderMath,
        watchMath: watchMath,
        highlightElement: highlightElement,
        destroyMathObserver: destroyMathObserver,
        convertKbCitationLinksInElement: convertKbCitationLinksInElement
    };
});