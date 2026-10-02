/**
 * AI 编程助手 - 代码语法高亮模块
 *
 * 应用 Prism.js 语法高亮到 AI 回复中的代码块。
 *
 * @module     local_ai_programming_assistant/code_highlight
 * @package
 * @author zfl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['filter_codehighlighter/prism'], function(PrismJS) {
    'use strict';
    return {
        init: function(containerSelector) {
            // 详情页有多条 AI 回复时每条渲染为独立的匹配容器，
            // 必须遍历全部容器而非仅取第一个，否则其余回复的代码块无语法高亮。
            let containers = document.querySelectorAll(containerSelector);
            if (containers.length === 0) {
                containers = [document];
            }

            containers.forEach(function(container) {
                // Find <code class="python"> etc., add language- prefix.
                const codeBlocks = container.querySelectorAll('pre code[class]');
                codeBlocks.forEach(function(code) {
                    const classes = code.className.trim().split(/\s+/);
                    // Skip if already has language- prefix.
                    if (classes.some(function(c) { return c.includes('language-'); })) {
                        return;
                    }
                    // 取第一个匹配语言名形态（小写字母开头、1-20 个字母数字，不含连字符）
                    // 的 class 作为语言，避免 <code class="python line-numbers"> 等多 class
                    // 元素因整串不匹配而被跳过。
                    let lang = '';
                    for (let i = 0; i < classes.length; i++) {
                        if (/^[a-z][a-z0-9]{0,19}$/.test(classes[i])) {
                            lang = classes[i];
                            break;
                        }
                    }
                    if (lang) {
                        // 使用 classList.add 保留原有 class（如 line-numbers 等），避免整体覆盖
                        code.classList.add('language-' + lang);
                        const pre = code.parentNode;
                        if (pre && pre.tagName === 'PRE') {
                            pre.classList.add('language-' + lang);
                        }
                    }
                });

                // Highlight all code blocks within the container.
                if (typeof PrismJS !== 'undefined') {
                    if (container !== document) {
                        PrismJS.highlightAllUnder(container);
                    } else {
                        PrismJS.highlightAll();
                    }
                }
            });
        }
    };
});