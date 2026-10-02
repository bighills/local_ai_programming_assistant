<?php
/**
 * AI 编程助手 - 多端点配置自定义设置控件
 *
 * 提供可视化的端点增删改配置界面，替代原始 JSON 文本域。
 * 管理员可通过"新增端点"按钮动态添加端点行，并填写
 * 名称、端点 URL、API 密钥、模型等字段。
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */

namespace local_ai_programming_assistant;

defined('MOODLE_INTERNAL') || die();

/**
 * 多端点配置的自定义 admin_setting。
 *
 * 数据格式：JSON 数组，每个元素包含 name / endpoint / apikey / model。
 * 保存时序列化到配置项 apiendpoint_list，读取时解析 JSON 并渲染为表单行。
 */
class admin_setting_endpoints extends \admin_setting {

    /**
     * @var string 存储 JSON 数据的隐藏字段名称
     */
    protected $jsonfieldname;

    /**
     * @var string 端点选择器配置项名称（用于动态更新下拉选项）
     */
    protected $selectorname;

    /**
     * @var string 路由策略配置项名称
     */
    protected $strategyname;

    /**
     * @param string $name 配置项名称（apiendpoint_list）
     * @param string $visiblename 可见名称
     * @param string $description 描述
     * @param string $defaultsetting 默认值
     * @param string $selectorname 端点选择器配置项名称
     * @param string $strategyname 路由策略配置项名称
     */
    public function __construct(
        $name,
        $visiblename,
        $description,
        $defaultsetting,
        $selectorname = '',
        $strategyname = ''
    ) {
        parent::__construct($name, $visiblename, $description, $defaultsetting);
        $this->jsonfieldname = $name;
        $this->selectorname = $selectorname;
        $this->strategyname = $strategyname;
    }

    /**
     * 获取当前设置值（解析 JSON 返回端点数组）
     *
     * @return array 端点数组
     */
    public function get_setting() {
        $raw = $this->config_read($this->name);
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        return $decoded;
    }

    /**
     * 写入设置值
     *
     * 接收两种格式：
     * - 数组（直接调用或 PHP 单元测试）
     * - JSON 字符串（表单提交时隐藏字段的值）
     * 统一过滤空条目后序列化为 JSON 存储。
     *
     * @param mixed $data 端点数组或 JSON 字符串
     * @return string 错误信息或空字符串
     */
    public function write_setting($data) {
        // 表单提交时，隐藏字段的值是 JSON 字符串
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (is_array($decoded)) {
                $data = $decoded;
            } else {
                // 空字符串或无效 JSON，视为空数组
                $data = [];
            }
        }

        if (!is_array($data)) {
            $data = [];
        }

        // 过滤掉空 endpoint 条目
        $filtered = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $endpoint = trim((string)($row['endpoint'] ?? ''));
            if ($endpoint === '') {
                continue;
            }
            $filtered[] = [
                'name' => trim((string)($row['name'] ?? '')),
                'endpoint' => $endpoint,
                'apikey' => trim((string)($row['apikey'] ?? '')),
                'model' => trim((string)($row['model'] ?? '')),
            ];
        }
        $json = json_encode($filtered, JSON_UNESCAPED_UNICODE);
        $result = $this->config_write($this->name, $json);
        return $result ? '' : get_string('errorsetting', 'admin');
    }

    /**
     * 输出 HTML 表单控件
     *
     * @param array $data 当前端点数组
     * @param string $query 查询参数
     * @return string HTML 代码
     */
    public function output_html($data, $query = '') {
        if (!is_array($data)) {
            $data = [];
        }

        $fullname = $this->get_full_name();
        $id = 'admin-setting-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $fullname);

        $rowindex = 0;
        $rowshtml = '';
        foreach ($data as $ep) {
            if (!is_array($ep)) {
                continue;
            }
            $rowshtml .= $this->render_endpoint_row($rowindex, $ep);
            $rowindex++;
        }

        // 至少渲染一行空白行，方便用户看到如何添加
        if ($rowindex === 0) {
            $rowshtml = $this->render_endpoint_row(0, []);
        }

        $addbtn = \html_writer::tag('button',
            get_string('apiendpoint_add_btn', 'local_ai_programming_assistant'),
            [
                'type' => 'button',
                'class' => 'btn btn-secondary btn-sm mt-1',
                'data-action' => 'add-endpoint-row',
                'data-target' => $id,
            ]
        );

        $tablehtml = \html_writer::start_div('ai-endpoint-wrapper', [
            'id' => $id,
            'data-selector-name' => $this->selectorname,
            'data-strategy-name' => $this->strategyname,
            // 注入本地化字符串供 JS 动态生成端点行/选择器选项使用，
            // 避免 endpoints_admin.js 硬编码中文覆盖英文管理界面的标签。
            'data-opt-default' => get_string('apiendpoint_default_selector', 'local_ai_programming_assistant'),
            'data-opt-roundrobin' => get_string('apiendpoint_roundrobin_selector', 'local_ai_programming_assistant'),
            'data-label-name' => get_string('apiendpoint_name_label', 'local_ai_programming_assistant'),
            'data-label-url' => get_string('apiendpoint_url_label', 'local_ai_programming_assistant'),
            'data-label-key' => get_string('apiendpoint_key_label', 'local_ai_programming_assistant'),
            'data-label-model' => get_string('apiendpoint_model_label', 'local_ai_programming_assistant'),
            'data-label-remove' => get_string('apiendpoint_remove_btn', 'local_ai_programming_assistant'),
            'data-ph-name' => get_string('apiendpoint_name_placeholder', 'local_ai_programming_assistant'),
        ]);

        $tablehtml .= \html_writer::start_div('', [
            'class' => 'ai-endpoint-rows-container',
        ]);

        $tablehtml .= $rowshtml;

        $tablehtml .= \html_writer::end_div(); // .ai-endpoint-rows-container

        $tablehtml .= $addbtn;

        // 隐藏字段：存储 JSON 数据，通过 JavaScript 在提交时填充
        $tablehtml .= \html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => $fullname,
            'id' => $id . '-json',
            'value' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'class' => 'ai-endpoint-json-store',
        ]);

        $tablehtml .= \html_writer::end_div(); // .ai-endpoint-wrapper

        // 加载并初始化 JavaScript 模块
        global $PAGE;
        if ($PAGE) {
            $PAGE->requires->js_call_amd('local_ai_programming_assistant/endpoints_admin', 'init');
        }

        return format_admin_setting(
            $this,
            $this->visiblename,
            $tablehtml,
            $this->description,
            false,
            '',
            null,
            $query
        );
    }

    /**
     * 渲染单个端点配置行
     *
     * @param int $index 行索引
     * @param array $ep 端点数据
     * @return string HTML
     */
    protected function render_endpoint_row(int $index, array $ep): string {
        $base = 'endpoint_' . $index;
        $rowhtml = \html_writer::start_div('ai-endpoint-row card p-2 mb-2 bg-light', [
            'data-row-index' => $index,
        ]);

        // 名称输入
        $rowhtml .= \html_writer::start_div('row mb-2');
        $rowhtml .= \html_writer::tag('label',
            get_string('apiendpoint_name_label', 'local_ai_programming_assistant'),
            ['class' => 'col-form-label col-sm-2']
        );
        $rowhtml .= \html_writer::empty_tag('input', [
            'type' => 'text',
            'class' => 'form-control form-control-sm ai-endpoint-name col-sm-10',
            'placeholder' => get_string('apiendpoint_name_placeholder', 'local_ai_programming_assistant'),
            'value' => (string)($ep['name'] ?? ''),
            'data-field' => 'name',
        ]);
        $rowhtml .= \html_writer::end_div();

        // 端点 URL 输入
        $rowhtml .= \html_writer::start_div('row mb-2');
        $rowhtml .= \html_writer::tag('label',
            get_string('apiendpoint_url_label', 'local_ai_programming_assistant'),
            ['class' => 'col-form-label col-sm-2']
        );
        // 仅当行内已有端点数据时才标记 required，避免空白行阻塞空配置保存；
        // 具体 required 状态由 JS 在 sync_all 时动态维护。
        $urlattrs = [
            'type' => 'url',
            'class' => 'form-control form-control-sm ai-endpoint-url col-sm-10',
            'placeholder' => 'https://api.example.com/v1/chat/completions',
            'value' => (string)($ep['endpoint'] ?? ''),
            'data-field' => 'endpoint',
        ];
        if (!empty($ep['endpoint'])) {
            $urlattrs['required'] = 'required';
        }
        $rowhtml .= \html_writer::empty_tag('input', $urlattrs);
        $rowhtml .= \html_writer::end_div();

        // API 密钥输入
        $rowhtml .= \html_writer::start_div('row mb-2');
        $rowhtml .= \html_writer::tag('label',
            get_string('apiendpoint_key_label', 'local_ai_programming_assistant'),
            ['class' => 'col-form-label col-sm-2']
        );
        $rowhtml .= \html_writer::empty_tag('input', [
            'type' => 'password',
            'class' => 'form-control form-control-sm ai-endpoint-key col-sm-10',
            'placeholder' => 'sk-...',
            'value' => (string)($ep['apikey'] ?? ''),
            'data-field' => 'apikey',
            'autocomplete' => 'off',
        ]);
        $rowhtml .= \html_writer::end_div();

        // 模型输入 + 删除按钮
        $rowhtml .= \html_writer::start_div('row');
        $rowhtml .= \html_writer::tag('label',
            get_string('apiendpoint_model_label', 'local_ai_programming_assistant'),
            ['class' => 'col-form-label col-sm-2']
        );
        $rowhtml .= \html_writer::start_div('col-sm-8');
        $rowhtml .= \html_writer::empty_tag('input', [
            'type' => 'text',
            'class' => 'form-control form-control-sm ai-endpoint-model',
            'placeholder' => 'gpt-4o',
            'value' => (string)($ep['model'] ?? ''),
            'data-field' => 'model',
        ]);
        $rowhtml .= \html_writer::end_div();

        // 删除按钮
        $rowhtml .= \html_writer::start_div('col-sm-2');
        $rowhtml .= \html_writer::tag('button',
            get_string('apiendpoint_remove_btn', 'local_ai_programming_assistant'),
            [
                'type' => 'button',
                'class' => 'btn btn-outline-danger btn-sm ai-endpoint-remove',
                'data-action' => 'remove-endpoint-row',
            ]
        );
        $rowhtml .= \html_writer::end_div();

        $rowhtml .= \html_writer::end_div(); // .row (model + delete)

        $rowhtml .= \html_writer::end_div(); // .ai-endpoint-row

        return $rowhtml;
    }

    /**
     * 构建端点选择器的下拉选项（基于当前端点数组）
     *
     * @param array $data 端点数组
     * @return array 选项数组
     */
    protected function build_selector_options(array $data): array {
        $options = [
            '__default__' => get_string('apiendpoint_default_selector', 'local_ai_programming_assistant'),
        ];
        if (!empty($data)) {
            $options['__roundrobin__'] = get_string('apiendpoint_roundrobin_selector', 'local_ai_programming_assistant');
            foreach ($data as $idx => $ep) {
                $label = !empty($ep['name']) ? $ep['name'] : ('endpoint-' . ($idx + 1));
                $endpoint = (string)($ep['endpoint'] ?? '');
                $host = parse_url($endpoint, PHP_URL_HOST);
                $suffix = $host ? ' (' . $host . ')' : '';
                $options['ep:' . $idx] = $label . $suffix;
            }
        }
        return $options;
    }
}