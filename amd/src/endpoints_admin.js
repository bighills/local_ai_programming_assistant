/**
 * AI 编程助手 - 管理后台多端点配置界面 JS
 *
 * 负责：
 * - 新增/删除端点配置行
 * - 将表单行序列化为 JSON 并同步到隐藏字段
 * - 动态更新端点选择器（apiendpoint_selector）下拉选项
 *
 * @module     local_ai_programming_assistant/endpoints_admin
 * @package    local_ai_programming_assistant
 */
define(['jquery'], function($) {

    var ADD_BTN_SELECTOR = '[data-action="add-endpoint-row"]';
    var REMOVE_BTN_SELECTOR = '[data-action="remove-endpoint-row"]';
    var ROW_SELECTOR = '.ai-endpoint-row';
    var FIELD_SELECTORS = {
        name: '.ai-endpoint-name',
        endpoint: '.ai-endpoint-url',
        apikey: '.ai-endpoint-key',
        model: '.ai-endpoint-model'
    };
    var JSON_STORE_SELECTOR = '.ai-endpoint-json-store';
    var WRAPPER_SELECTOR = '.ai-endpoint-wrapper';
    var ROWS_CONTAINER_SELECTOR = '.ai-endpoint-rows-container';

    /**
     * 获取指定容器的端点数组
     *
     * @param {HTMLElement} wrapper 容器元素
     * @return {Array<Object>} 端点数组
     */
    function collect_endpoints(wrapper) {
        var rows = wrapper.querySelectorAll(ROW_SELECTOR);
        var result = [];
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            var endpoint = trim_value(row.querySelector(FIELD_SELECTORS.endpoint));
            if (endpoint === '') {
                continue;
            }
            result.push({
                name: trim_value(row.querySelector(FIELD_SELECTORS.name)),
                endpoint: endpoint,
                apikey: trim_value(row.querySelector(FIELD_SELECTORS.apikey)),
                model: trim_value(row.querySelector(FIELD_SELECTORS.model))
            });
        }
        return result;
    }

    /**
     * 从输入元素获取并 trim 文本值
     *
     * @param {HTMLElement|null} el 输入元素
     * @return {string}
     */
    function trim_value(el) {
        if (!el) {
            return '';
        }
        return (el.value || '').replace(/^\s+|\s+$/g, '');
    }

    /**
     * 为端点选择器构建新的选项 HTML
     *
     * @param {HTMLElement} wrapper 容器元素（含本地化字符串 data-* 属性）
     * @param {Array<Object>} endpoints 端点数组
     * @return {string} HTML
     */
    function build_selector_options_html(wrapper, endpoints) {
        var parts = [];
        var defaultLabel = wrapper.getAttribute('data-opt-default') || 'Default';
        var roundrobinLabel = wrapper.getAttribute('data-opt-roundrobin') || 'Round-robin';
        parts.push('<option value="__default__">' + escape_html(defaultLabel) + '</option>');
        if (endpoints.length > 0) {
            parts.push('<option value="__roundrobin__">' + escape_html(roundrobinLabel) + '</option>');
            for (var i = 0; i < endpoints.length; i++) {
                var ep = endpoints[i];
                var label = (ep.name && ep.name !== '') ? ep.name : ('endpoint-' + (i + 1));
                var host = '';
                try {
                    var urlObj = new URL(ep.endpoint);
                    host = urlObj.hostname || '';
                } catch (e) {
                    // ignore invalid URL
                }
                var suffix = host ? ' (' + host + ')' : '';
                parts.push('<option value="ep:' + i + '">' + escape_html(label + suffix) + '</option>');
            }
        }
        return parts.join('');
    }

    /**
     * HTML 转义
     *
     * @param {string} str 原始字符串
     * @return {string} 转义后字符串
     */
    var _escapeDiv = document.createElement('div');
    function escape_html(str) {
        _escapeDiv.textContent = str;
        return _escapeDiv.innerHTML;
    }

    /**
     * 判断一行端点是否填写了除 URL 外的其他字段
     *
     * @param {HTMLElement} row 端点行元素
     * @return {boolean}
     */
    function row_has_data(row) {
        var fields = ['name', 'apikey', 'model'];
        for (var i = 0; i < fields.length; i++) {
            var input = row.querySelector(FIELD_SELECTORS[fields[i]]);
            if (trim_value(input) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * 根据当前端点状态管理 URL 输入的 required 属性
     *
     * 规则：
     * - 没有任何有效端点（允许保存空配置）时，移除所有 required，
     *   避免 HTML5 校验阻止空配置保存
     * - 存在有效端点时，仅对已填写部分数据的行保留 required，
     *   防止部分数据被保存时静默丢弃
     *
     * @param {HTMLElement} wrapper 容器元素
     * @param {Array<Object>} endpoints 当前有效端点数组
     */
    function sync_required(wrapper, endpoints) {
        var hasValid = endpoints.length > 0;
        wrapper.querySelectorAll(ROW_SELECTOR).forEach(function(row) {
            var urlInput = row.querySelector(FIELD_SELECTORS.endpoint);
            if (!urlInput) {
                return;
            }
            if (hasValid && row_has_data(row)) {
                urlInput.setAttribute('required', 'required');
            } else {
                urlInput.removeAttribute('required');
            }
        });
    }

    /**
     * 同步所有端点容器：收集数据、更新隐藏字段、更新选择器、更新 required 状态
     */
    function sync_all() {
        document.querySelectorAll(WRAPPER_SELECTOR).forEach(function(wrapper) {
            var endpoints = collect_endpoints(wrapper);

            // 更新隐藏的 JSON 存储字段
            var store = wrapper.querySelector(JSON_STORE_SELECTOR);
            if (store) {
                store.value = JSON.stringify(endpoints);
            }

            // 根据当前状态更新 URL 输入的 required 属性
            sync_required(wrapper, endpoints);

            // 更新端点选择器下拉选项（id 遵循 Moodle admin_setting 约定：id_s_<plugin>_<name>）
            var selectorName = wrapper.getAttribute('data-selector-name');
            if (selectorName) {
                var selectorId = 'id_s_local_ai_programming_assistant_' + selectorName;
                var selector = document.getElementById(selectorId);
                if (selector) {
                    var currentVal = selector.value;
                    selector.innerHTML = build_selector_options_html(wrapper, endpoints);
                    // 恢复之前选择的值（如果仍然有效）
                    var hasCurrent = false;
                    for (var i = 0; i < selector.options.length; i++) {
                        if (selector.options[i].value === currentVal) {
                            hasCurrent = true;
                            break;
                        }
                    }
                    if (hasCurrent) {
                        selector.value = currentVal;
                    } else {
                        selector.value = '__default__';
                    }
                }
            }
        });
    }

    /**
     * 创建一个新的端点行
     *
     * @param {HTMLElement} wrapper 容器元素（含本地化字符串 data-* 属性）
     */
    function add_row(wrapper) {
        var container = wrapper.querySelector(ROWS_CONTAINER_SELECTOR);
        if (!container) {
            return;
        }
        var index = container.querySelectorAll(ROW_SELECTOR).length;

        // 从容器 data-* 属性读取本地化标签（由 PHP 端 get_string 注入），
        // 避免硬编码中文覆盖英文管理界面。
        var labels = {
            name: wrapper.getAttribute('data-label-name') || 'Name',
            url: wrapper.getAttribute('data-label-url') || 'Endpoint URL',
            key: wrapper.getAttribute('data-label-key') || 'API Key',
            model: wrapper.getAttribute('data-label-model') || 'Model',
            remove: wrapper.getAttribute('data-label-remove') || 'Remove',
            namePh: wrapper.getAttribute('data-ph-name') || 'Endpoint name (optional)'
        };

        var row = document.createElement('div');
        row.className = 'ai-endpoint-row card p-2 mb-2 bg-light';
        row.setAttribute('data-row-index', index);

        // 名称
        var row1 = document.createElement('div');
        row1.className = 'row mb-2';
        row1.appendChild(make_label(labels.name, 2));
        row1.appendChild(make_text_input('ai-endpoint-name col-sm-10', labels.namePh, 'name'));
        row.appendChild(row1);

        // URL
        var row2 = document.createElement('div');
        row2.className = 'row mb-2';
        row2.appendChild(make_label(labels.url, 2));
        var urlInput = make_text_input('ai-endpoint-url col-sm-10', 'https://api.example.com/v1/chat/completions', 'endpoint');
        urlInput.type = 'url';
        urlInput.setAttribute('required', 'required');
        row2.appendChild(urlInput);
        row.appendChild(row2);

        // API Key
        var row3 = document.createElement('div');
        row3.className = 'row mb-2';
        row3.appendChild(make_label(labels.key, 2));
        var keyInput = make_text_input('ai-endpoint-key col-sm-10', 'sk-...', 'apikey');
        keyInput.type = 'password';
        keyInput.setAttribute('autocomplete', 'off');
        row3.appendChild(keyInput);
        row.appendChild(row3);

        // Model + Remove
        var row4 = document.createElement('div');
        row4.className = 'row';
        row4.appendChild(make_label(labels.model, 2));
        var modelCol = document.createElement('div');
        modelCol.className = 'col-sm-8';
        modelCol.appendChild(make_text_input('ai-endpoint-model', 'gpt-4o', 'model'));
        row4.appendChild(modelCol);

        var removeCol = document.createElement('div');
        removeCol.className = 'col-sm-2';
        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn btn-outline-danger btn-sm';
        removeBtn.setAttribute('data-action', 'remove-endpoint-row');
        removeBtn.textContent = labels.remove;
        removeCol.appendChild(removeBtn);
        row4.appendChild(removeCol);

        row.appendChild(row4);
        container.appendChild(row);

        sync_all();

        // 聚焦到新行的 URL 输入
        var urlField = row.querySelector(FIELD_SELECTORS.endpoint);
        if (urlField) {
            setTimeout(function() { urlField.focus(); }, 0);
        }
    }

    /**
     * 创建 label 元素
     */
    function make_label(text, col) {
        var label = document.createElement('label');
        label.className = 'col-form-label col-sm-' + col;
        label.textContent = text;
        return label;
    }

    /**
     * 创建 text input 元素
     */
    function make_text_input(classname, placeholder, field) {
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control form-control-sm ' + classname;
        input.placeholder = placeholder;
        input.setAttribute('data-field', field);
        return input;
    }

    /**
     * 删除端点行
     *
     * @param {HTMLElement} row 要删除的行
     */
    function remove_row(row) {
        var container = row.parentElement;
        var wrapper = container ? container.closest(WRAPPER_SELECTOR) : null;
        row.remove();

        // 如果删除后没有剩余行，添加一行空白行
        if (container && container.querySelectorAll(ROW_SELECTOR).length === 0) {
            if (wrapper) {
                add_row(wrapper);
            }
        } else {
            sync_all();
        }
    }

    /**
     * 是否已初始化（防止 js_call_amd 多次调用 init 导致事件重复绑定）
     */
    var inited = false;

    /**
     * 初始化所有端点配置容器
     */
    function init() {
        if (inited) {
            return;
        }
        inited = true;

        // 绑定"新增端点"按钮（事件委托）
        document.addEventListener('click', function(e) {
            var addBtn = e.target.closest(ADD_BTN_SELECTOR);
            if (addBtn) {
                e.preventDefault();
                var targetId = addBtn.getAttribute('data-target');
                var wrapper = targetId ? document.getElementById(targetId) : null;
                if (!wrapper) {
                    wrapper = addBtn.closest(WRAPPER_SELECTOR);
                }
                if (wrapper) {
                    add_row(wrapper);
                }
                return;
            }

            var removeBtn = e.target.closest(REMOVE_BTN_SELECTOR);
            if (removeBtn) {
                e.preventDefault();
                var row = removeBtn.closest(ROW_SELECTOR);
                if (row) {
                    remove_row(row);
                }
            }
        });

        // 绑定输入事件：任何字段变化时同步 JSON
        document.addEventListener('input', function(e) {
            var field = e.target;
            if (field.closest && field.closest(WRAPPER_SELECTOR)) {
                var wrapper = field.closest(WRAPPER_SELECTOR);
                if (wrapper) {
                    // 实时同步（防抖）
                    clearTimeout(wrapper._syncTimer);
                    wrapper._syncTimer = setTimeout(function() {
                        sync_all();
                    }, 200);
                }
            }
        });

        // 拦截 form 提交，确保在保存前同步数据
        document.querySelectorAll('form.mform').forEach(function(form) {
            form.addEventListener('submit', function() {
                sync_all();
            });
        });

        // 页面加载完成后立即同步一次
        sync_all();
    }

    return {
        init: init
    };
});