<?php
/**
 * AI 编程助手 - 知识库管理页面
 *
 * 允许管理员：
 * - 查看知识库文件列表
 * - 上传 PDF 教材
 * - 删除知识库文件
 * - 查看知识库统计信息
 *
 * @package    local_ai_programming_assistant
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');

use local_ai_programming_assistant\kb_service;
use local_ai_programming_assistant\config_manager;

$action = optional_param('action', '', PARAM_ALPHA);
$fileid = optional_param('fileid', 0, PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);
$tag = optional_param('tag', '', PARAM_TEXT);
$page = max(0, optional_param('page', 0, PARAM_INT));
$perpage = optional_param('perpage', 10, PARAM_INT);
$perpageoptions = [10, 20, 50];
if (!in_array($perpage, $perpageoptions)) {
    $perpage = 10;
}

$syscontext = context_system::instance();
require_login();
require_capability('local/ai_programming_assistant:config', $syscontext);

admin_externalpage_setup('local_ai_programming_assistant_kb_manage');

$PAGE->set_url('/local/ai_programming_assistant/kb_manage.php', ['search' => $search, 'tag' => $tag, 'page' => $page, 'perpage' => $perpage]);

// 获取所有标签（用于筛选和上传表单的 datalist）
$tags = kb_service::get_all_tags();
$PAGE->set_title(get_string('knowledgebase_manage_title', 'local_ai_programming_assistant'));
$PAGE->set_heading(get_string('knowledgebase_manage_title', 'local_ai_programming_assistant'));

// 处理删除
if ($action === 'delete' && $fileid > 0 && confirm_sesskey()) {
    kb_service::delete_file($fileid);
    \core\notification::success(get_string('knowledgebase_file_deleted', 'local_ai_programming_assistant'));
    redirect($PAGE->url);
}

// 处理单文件重建索引
if ($action === 'rebuildfile' && $fileid > 0 && confirm_sesskey()) {
    $file = $DB->get_record('local_ai_prog_assist_kb_files', ['id' => $fileid]);
    if ($file) {
        try {
            kb_service::build_index_for_file($fileid);
            \core\notification::success(get_string(
                'knowledgebase_file_rebuild_success',
                'local_ai_programming_assistant',
                $file->name
            ));
        } catch (\Throwable $e) {
            \core\notification::error(get_string(
                'knowledgebase_file_rebuild_failed',
                'local_ai_programming_assistant',
                $file->name
            ) . ': ' . $e->getMessage());
        }
    }
    redirect($PAGE->url);
}

// 处理重建索引
if ($action === 'rebuild' && confirm_sesskey()) {
    @set_time_limit(0);
    $rebuildresult = kb_service::rebuild_all_indexes();
    if ($rebuildresult['errors'] > 0) {
        $errornames = implode(', ', array_column($rebuildresult['error_files'], 'name'));
        \core\notification::warning(get_string(
            'knowledgebase_rebuild_partial',
            'local_ai_programming_assistant',
            ['indexed' => $rebuildresult['indexed'], 'errors' => $rebuildresult['errors'], 'errornames' => $errornames]
        ));
    } else {
        \core\notification::success(get_string(
            'knowledgebase_rebuild_success',
            'local_ai_programming_assistant',
            $rebuildresult['indexed']
        ));
    }
    redirect($PAGE->url);
}

// 处理重命名（AJAX）
if ($action === 'rename' && $fileid > 0 && confirm_sesskey()) {
    header('Content-Type: application/json; charset=utf-8');
    $newname = required_param('newname', PARAM_TEXT);
    if (!empty(trim($newname))) {
        $renamed = kb_service::update_file_name($fileid, trim($newname));
        if ($renamed) {
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['success' => false, 'error' => get_string('knowledgebase_rename_failed', 'local_ai_programming_assistant')], JSON_UNESCAPED_UNICODE);
        }
    } else {
        echo json_encode(['success' => false, 'error' => get_string('knowledgebase_name_empty', 'local_ai_programming_assistant')], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 处理批量删除
if ($action === 'batchdelete' && confirm_sesskey()) {
    $fileids = required_param('fileids', PARAM_SEQUENCE);
    $ids = array_filter(explode(',', $fileids), function($id) { return $id > 0; });
    if (!empty($ids)) {
        $deleted = kb_service::batch_delete_files($ids);
        \core\notification::success(get_string('knowledgebase_batch_delete_success', 'local_ai_programming_assistant', $deleted));
    }
    redirect($PAGE->url);
}

// 上传表单
class kb_upload_form extends \moodleform {
    /** @var array 所有已有的标签列表（用于 datalist） */
    private array $tags = [];

    public function __construct($action = null, $customdata = null, $method = 'post', $target = '', $attributes = null, $editable = true, $ajaxformdata = null) {
        $this->tags = $customdata['tags'] ?? [];
        parent::__construct($action, $customdata, $method, $target, $attributes, $editable, $ajaxformdata);
    }

    public function definition() {
        $mform = $this->_form;

        $mform->addElement('text', 'name', get_string('knowledgebase_file_name', 'local_ai_programming_assistant'));
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'maxlength', 255, 'client');
        $mform->setDefault('name', '');

        $mform->addElement('text', 'tag', get_string('knowledgebase_file_tag', 'local_ai_programming_assistant'));
        $mform->setType('tag', PARAM_TEXT);
        $mform->setDefault('tag', '数据与计算');
        // 使用 datalist 提供已有的标签选项
        $datalistid = 'id_tag_datalist';
        $mform->addElement('html', \html_writer::start_tag('datalist', ['id' => $datalistid]));
        foreach ($this->tags as $t) {
            $mform->addElement('html', \html_writer::tag('option', '', ['value' => s($t)]));
        }
        $mform->addElement('html', \html_writer::end_tag('datalist'));
        // 默认无法直接给 text 元素添加 list 属性，通过 JS 附加
        $mform->addElement('html', \html_writer::tag('script', '
            document.addEventListener("DOMContentLoaded", function() {
                var tagInput = document.getElementById("id_tag");
                if (tagInput) {
                    tagInput.setAttribute("list", "' . $datalistid . '");
                }
            });
        '));
 
        $mform->addElement('filemanager', 'kbfile', get_string('knowledgebase_select_file', 'local_ai_programming_assistant'), null,
            ['accepted_types' => kb_service::get_accepted_types(), 'maxbytes' => 50 * 1024 * 1024, 'maxfiles' => 1]);
        $mform->addRule('kbfile', null, 'required', null, 'client');

        // 当文件被选中时，自动提取文件名（去除扩展名）填入资源名称
        $mform->addElement('html', \html_writer::tag('script', '
            document.addEventListener("DOMContentLoaded", function() {
                var nameInput = document.getElementById("id_name");
                if (!nameInput) return;

                // 查找 filemanager 容器（Moodle 渲染后会有 .filemanager 类）
                function findFmContainer() {
                    var fm = document.querySelector("#id_kbfile_filemanager, #menakbfile_filemanager, .filemanager");
                    if (fm) return fm;
                    // 回退：查找包含 filemanager 的父容器
                    var wrapper = document.querySelector("#fitem_id_kbfile, #id_kbfile");
                    return wrapper;
                }

                function extractFilename(text) {
                    // 去除路径，只保留文件名
                    text = text.replace(/^.*[\\/]/, "").trim();
                    // 去除扩展名
                    return text.replace(/\.[^.]+$/, "");
                }

                function tryFillName() {
                    if (nameInput.value.trim() !== "") return; // 用户已填写，不覆盖

                    var fm = findFmContainer();
                    if (!fm) return;

                    // 尝试从 filemanager UI 提取文件名
                    var fileEl = fm.querySelector(".fp-filename") || fm.querySelector(".filemanager-filename") || fm.querySelector(".fp-file-name");
                    if (fileEl) {
                        var fname = extractFilename(fileEl.textContent);
                        if (fname) {
                            nameInput.value = fname;
                        }
                    }
                }

                // 使用 MutationObserver 监听 filemanager 内容变化
                var observer = new MutationObserver(function() {
                    tryFillName();
                });

                var fmContainer = findFmContainer();
                if (fmContainer) {
                    observer.observe(fmContainer, { childList: true, subtree: true, characterData: true });
                } else {
                    // 容器尚未渲染，延迟重试
                    setTimeout(function() {
                        fmContainer = findFmContainer();
                        if (fmContainer) {
                            observer.observe(fmContainer, { childList: true, subtree: true, characterData: true });
                        }
                    }, 1000);
                }

                // 用户手动编辑名称后停止自动填充
                nameInput.addEventListener("input", function() {
                    observer.disconnect();
                });
            });
        '));

        $this->add_action_buttons(false, get_string('knowledgebase_upload_button', 'local_ai_programming_assistant'));
    }
}

// 批量上传表单
class kb_batch_upload_form extends \moodleform {
    /** @var array 所有已有的标签列表（用于 datalist） */
    private array $tags = [];

    public function __construct($action = null, $customdata = null, $method = 'post', $target = '', $attributes = null, $editable = true, $ajaxformdata = null) {
        $this->tags = $customdata['tags'] ?? [];
        parent::__construct($action, $customdata, $method, $target, $attributes, $editable, $ajaxformdata);
    }

    public function definition() {
        $mform = $this->_form;

        $mform->addElement('text', 'tag', get_string('knowledgebase_file_tag', 'local_ai_programming_assistant'));
        $mform->setType('tag', PARAM_TEXT);
        $mform->setDefault('tag', '数据与计算');
        // 使用 datalist 提供已有的标签选项
        $datalistid = 'id_tag_datalist_batch';
        $mform->addElement('html', \html_writer::start_tag('datalist', ['id' => $datalistid]));
        foreach ($this->tags as $t) {
            $mform->addElement('html', \html_writer::tag('option', '', ['value' => s($t)]));
        }
        $mform->addElement('html', \html_writer::end_tag('datalist'));
        $mform->addElement('html', \html_writer::tag('script', '
            document.addEventListener("DOMContentLoaded", function() {
                var inputs = document.querySelectorAll("input[name=\'tag\']");
                inputs.forEach(function(inp) {
                    if (!inp.getAttribute("list")) {
                        inp.setAttribute("list", "' . $datalistid . '");
                    }
                });
            });
        '));

        $mform->addElement('filemanager', 'kbfiles', get_string('knowledgebase_batch_select_files', 'local_ai_programming_assistant'), null,
            ['accepted_types' => kb_service::get_accepted_types(), 'maxbytes' => 50 * 1024 * 1024, 'maxfiles' => 20]);
        $mform->addRule('kbfiles', null, 'required', null, 'client');

        $this->add_action_buttons(false, get_string('knowledgebase_batch_upload_button', 'local_ai_programming_assistant'));
    }
}

$mform = new kb_upload_form(null, ['tags' => $tags]);
$batchform = new kb_batch_upload_form(null, ['tags' => $tags]);

if ($mform->is_cancelled() || $batchform->is_cancelled()) {
    redirect($PAGE->url);
}

// 处理单文件上传
if ($data = $mform->get_data()) {
    require_sesskey();

    // 将 draft 暂存文件移入插件自己的文件域（避免占用 user/draft 区域，防止升级后被清理）
    $systemcontext = \context_system::instance();
    $draftid = file_get_submitted_draft_itemid('kbfile');

    file_save_draft_area_files($draftid, $systemcontext->id, 'local_ai_programming_assistant', 'kb_upload', $draftid);
    $fs = get_file_storage();
    $files = $fs->get_area_files($systemcontext->id, 'local_ai_programming_assistant', 'kb_upload', $draftid, 'filename ASC', false);

    $uploaded = false;
    foreach ($files as $file) {
        $filepath = $file->copy_content_to_temp();
        $filename = $file->get_filename();

        // 未填写资源名称时，自动使用文件名（去除扩展名）作为名称
        $name = !empty(trim($data->name)) ? trim($data->name) : kb_service::strip_extension($filename);
        $tag = !empty(trim($data->tag)) ? trim($data->tag) : '数据与计算';

        try {
            kb_service::upload_file($name, $filepath, $filename, $tag);
            $uploaded = true;
        } catch (\Throwable $e) {
            \core\notification::error($filename . ': ' . $e->getMessage());
        }

        @unlink($filepath);
    }

    // 清理本次上传的暂存文件，避免在插件文件域中堆积
    $fs->delete_area_files($systemcontext->id, 'local_ai_programming_assistant', 'kb_upload', $draftid);

    if ($uploaded) {
        \core\notification::success(get_string('knowledgebase_upload_success', 'local_ai_programming_assistant'));
    } else {
        \core\notification::error(get_string('knowledgebase_upload_failed', 'local_ai_programming_assistant'));
    }

    redirect($PAGE->url);
}

// 处理批量上传
if ($data = $batchform->get_data()) {
    require_sesskey();
    @set_time_limit(0);

    $systemcontext = \context_system::instance();
    $draftid = file_get_submitted_draft_itemid('kbfiles');

    file_save_draft_area_files($draftid, $systemcontext->id, 'local_ai_programming_assistant', 'kb_upload', $draftid);
    $fs = get_file_storage();
    $files = $fs->get_area_files($systemcontext->id, 'local_ai_programming_assistant', 'kb_upload', $draftid, 'filename ASC', false);

    $success = 0;
    $skipped = 0;
    $errors = 0;
    $batchtag = !empty(trim($data->tag)) ? trim($data->tag) : '数据与计算';

    foreach ($files as $file) {
        $filepath = $file->copy_content_to_temp();
        $filename = $file->get_filename();
        // 自动提取资源名称：去除文件扩展名
        $autoname = kb_service::strip_extension($filename);

        try {
            kb_service::upload_file($autoname, $filepath, $filename, $batchtag);
            $success++;
        } catch (\moodle_exception $e) {
            // 重复文件异常单独处理，显示为"跳过"而非"错误"
            $errcode = $e->errorcode ?? '';
            if ($errcode === 'knowledgebase_file_duplicate') {
                $skipped++;
            } else {
                $errors++;
                \core\notification::error($filename . ': ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            $errors++;
            \core\notification::error($filename . ': ' . $e->getMessage());
        }

        @unlink($filepath);
    }

    // 清理本次上传的暂存文件，避免在插件文件域中堆积
    $fs->delete_area_files($systemcontext->id, 'local_ai_programming_assistant', 'kb_upload', $draftid);

    if ($success > 0) {
        \core\notification::success(get_string(
            'knowledgebase_batch_upload_success',
            'local_ai_programming_assistant',
            ['success' => $success, 'skipped' => $skipped, 'errors' => $errors]
        ));
    } else if ($skipped > 0 && $errors === 0) {
        \core\notification::info(get_string(
            'knowledgebase_batch_upload_all_skipped',
            'local_ai_programming_assistant',
            $skipped
        ));
    }

    redirect($PAGE->url);
}

// 获取知识库状态
$stats = kb_service::get_stats();
$pagedresult = kb_service::get_files_paged($search, $page, $perpage, $tag);
$files = $pagedresult['files'];
$totalfiles = $pagedresult['total'];
$totalpages = $pagedresult['pages'];
$pdftotextavail = kb_service::is_pdftotext_available();
$docxavail = kb_service::is_docx_available();
$kbenabled = kb_service::is_enabled();

$output = $PAGE->get_renderer('core');

echo $output->header();

// 显示状态卡片
echo \html_writer::start_div('row mb-4');
echo \html_writer::start_div('col-md-4');
echo \html_writer::start_div('card');
echo \html_writer::div(
    \html_writer::tag('h5', get_string('knowledgebase_stats_files', 'local_ai_programming_assistant'), ['class' => 'card-title']) .
    \html_writer::tag('p', $stats['filecount'], ['class' => 'display-4 text-center']),
    'card-body text-center'
);
echo \html_writer::end_div() . \html_writer::end_div();

echo \html_writer::start_div('col-md-4');
echo \html_writer::start_div('card');
echo \html_writer::div(
    \html_writer::tag('h5', get_string('knowledgebase_stats_chunks', 'local_ai_programming_assistant'), ['class' => 'card-title']) .
    \html_writer::tag('p', $stats['chunkcount'], ['class' => 'display-4 text-center']),
    'card-body text-center'
);
echo \html_writer::end_div() . \html_writer::end_div();

echo \html_writer::start_div('col-md-4');
echo \html_writer::start_div('card');
$parsercolors = [];
$parsericons = [];
$parsericons[] = $pdftotextavail ? 'text-success' : 'text-danger';
$parsericons[] = $docxavail ? 'text-success' : 'text-danger';
$parsertips = [];
$parsertips[] = $pdftotextavail
    ? get_string('knowledgebase_pdftotext_available', 'local_ai_programming_assistant')
    : get_string('knowledgebase_pdftotext_unavailable', 'local_ai_programming_assistant');
$parsertips[] = $docxavail
    ? get_string('knowledgebase_docx_available', 'local_ai_programming_assistant')
    : get_string('knowledgebase_docx_unavailable', 'local_ai_programming_assistant');
echo \html_writer::div(
    \html_writer::tag('h5', get_string('knowledgebase_stats_parsers', 'local_ai_programming_assistant'), ['class' => 'card-title']) .
    \html_writer::tag('p',
        \html_writer::tag('span', 'PDF ' . ($pdftotextavail ? '✓' : '✗'), ['class' => "me-3 {$parsericons[0]}", 'title' => $parsertips[0]]) .
        \html_writer::tag('span', 'DOCX ' . ($docxavail ? '✓' : '✗'), ['class' => "me-3 {$parsericons[1]}", 'title' => $parsertips[1]]) .
        \html_writer::tag('span', 'MD/TXT' . ' ✓', ['class' => "text-success", 'title' => get_string('knowledgebase_no_external_dependency', 'local_ai_programming_assistant')]),
        ['class' => 'display-6 text-center']
    ),
    'card-body text-center'
);
echo \html_writer::end_div() . \html_writer::end_div() . \html_writer::end_div();
echo \html_writer::end_div();

// 上传表单
echo \html_writer::start_div('card mb-4');
echo \html_writer::div(
    \html_writer::tag('h4', get_string('knowledgebase_upload_heading', 'local_ai_programming_assistant'), ['class' => 'card-header']) .
    \html_writer::div($mform->render(), 'card-body'),
    ''
);
echo \html_writer::end_div();

// 批量上传表单
echo \html_writer::start_div('card mb-4');
echo \html_writer::div(
    \html_writer::tag('h4', get_string('knowledgebase_batch_upload_heading', 'local_ai_programming_assistant'), ['class' => 'card-header']) .
    \html_writer::div($batchform->render(), 'card-body'),
    ''
);
echo \html_writer::end_div();

// 文件列表
echo \html_writer::start_div('card');
$rebuildurl = new \moodle_url($PAGE->url, [
    'action' => 'rebuild',
    'sesskey' => sesskey(),
]);
$rebuildbtn = \html_writer::link(
    $rebuildurl,
    get_string('knowledgebase_rebuild_button', 'local_ai_programming_assistant'),
    [
        'class' => 'btn btn-sm btn-warning',
        'data-confirm' => get_string('knowledgebase_confirm_rebuild', 'local_ai_programming_assistant'),
    ]
);
echo \html_writer::div(
    \html_writer::tag('h4', get_string('knowledgebase_file_list', 'local_ai_programming_assistant'), ['class' => 'mb-0']) . $rebuildbtn,
    'card-header d-flex justify-content-between align-items-center'
);

// 搜索表单
echo \html_writer::start_div('card-body');
$searchform = \html_writer::start_tag('form', ['method' => 'get', 'class' => 'form-inline d-flex gap-2 flex-wrap']);
$searchform .= \html_writer::tag('input', '', [
    'type' => 'text',
    'name' => 'search',
    'value' => s($search),
    'placeholder' => get_string('knowledgebase_search_placeholder', 'local_ai_programming_assistant'),
    'class' => 'form-control flex-grow-1',
]);
$searchform .= \html_writer::tag('input', '', [
    'type' => 'hidden',
    'name' => 'page',
    'value' => $page,
]);
$searchform .= \html_writer::tag('button', get_string('search'), ['type' => 'submit', 'class' => 'btn btn-primary']);
if (!empty($search)) {
    $searchform .= \html_writer::link(
        new \moodle_url($PAGE->url, ['search' => '', 'page' => 0]),
        get_string('knowledgebase_search_clear', 'local_ai_programming_assistant'),
        ['class' => 'btn btn-secondary']
    );
}
// 标签筛选
$searchform .= \html_writer::tag('label', get_string('knowledgebase_file_tag', 'local_ai_programming_assistant'), ['class' => 'align-self-center mb-0']);
$searchform .= \html_writer::start_tag('select', ['name' => 'tag', 'class' => 'form-select', 'onchange' => 'this.form.submit();']);
$searchform .= \html_writer::tag('option', get_string('knowledgebase_tag_all', 'local_ai_programming_assistant'), ['value' => '']);
foreach ($tags as $t) {
    $optionattrs = ['value' => s($t)];
    if ($t === $tag) {
        $optionattrs['selected'] = 'selected';
    }
    $searchform .= \html_writer::tag('option', s($t), $optionattrs);
}
$searchform .= \html_writer::end_tag('select');
$searchform .= \html_writer::tag('label', get_string('knowledgebase_perpage', 'local_ai_programming_assistant'), ['class' => 'align-self-center mb-0']);
$searchform .= \html_writer::start_tag('select', ['name' => 'perpage', 'class' => 'form-select', 'onchange' => 'this.form.submit();']);
foreach ($perpageoptions as $option) {
    $optionattrs = ['value' => $option];
    if ($option === $perpage) {
        $optionattrs['selected'] = 'selected';
    }
    $searchform .= \html_writer::tag('option', $option, $optionattrs);
}
$searchform .= \html_writer::end_tag('select');
$searchform .= \html_writer::end_tag('form');
echo $searchform;
echo \html_writer::end_div();

if (empty($files)) {
    $emptymsg = !empty($search)
        ? get_string('knowledgebase_no_search_results', 'local_ai_programming_assistant')
        : get_string('knowledgebase_no_files', 'local_ai_programming_assistant');
    echo \html_writer::div(
        \html_writer::tag('p', $emptymsg, ['class' => 'text-muted text-center my-3']),
        ''
    );
} else {
    echo \html_writer::start_div('table-responsive');
    echo \html_writer::start_tag('form', ['id' => 'batchdeleteform', 'method' => 'post', 'action' => $PAGE->url]);
    echo \html_writer::start_tag('table', ['class' => 'table table-striped table-hover mb-0']);
    echo \html_writer::start_tag('thead') . \html_writer::start_tag('tr');
    echo \html_writer::tag('th', \html_writer::tag('input', '', ['type' => 'checkbox', 'id' => 'selectall']));
    echo \html_writer::tag('th', get_string('knowledgebase_file_name', 'local_ai_programming_assistant'));
    echo \html_writer::tag('th', get_string('knowledgebase_file_filename', 'local_ai_programming_assistant'));
    echo \html_writer::tag('th', get_string('knowledgebase_file_tag', 'local_ai_programming_assistant'));
    echo \html_writer::tag('th', get_string('knowledgebase_file_size', 'local_ai_programming_assistant'));
    echo \html_writer::tag('th', get_string('knowledgebase_file_chunks', 'local_ai_programming_assistant'));
    echo \html_writer::tag('th', get_string('knowledgebase_file_header_original', 'local_ai_programming_assistant'));
    echo \html_writer::tag('th', get_string('knowledgebase_file_timecreated', 'local_ai_programming_assistant'));
    echo \html_writer::tag('th', get_string('actions', 'local_ai_programming_assistant'));
    echo \html_writer::end_tag('tr') . \html_writer::end_tag('thead');
    echo \html_writer::start_tag('tbody');

    foreach ($files as $file) {
        echo \html_writer::start_tag('tr');
        echo \html_writer::tag('td', \html_writer::tag('input', '', ['type' => 'checkbox', 'name' => 'fileids[]', 'value' => $file->id, 'class' => 'file-select']));
        $editableicon = \html_writer::tag('i', '', ['class' => 'fa fa-pencil ms-1 text-muted edit-icon', 'style' => 'cursor:pointer;font-size:0.8em;', 'title' => get_string('edit')]);
        echo \html_writer::tag('td',
            \html_writer::tag('span', s($file->name), ['class' => 'editable-name', 'data-fileid' => $file->id]) .
            $editableicon
        );
        echo \html_writer::tag('td', s($file->filename));
        echo \html_writer::tag('td', s($file->tag));
        echo \html_writer::tag('td', display_size($file->filesize));
        echo \html_writer::tag('td', (int)$file->chunkcount);
        // 原始文件状态
        $downloadurl = kb_service::get_download_url($file->id);
        if ($downloadurl) {
            $fileicon = \html_writer::link(
                $downloadurl,
                get_string('knowledgebase_file_hasfile', 'local_ai_programming_assistant'),
                ['class' => 'text-success', 'target' => '_blank']
            );
        } else {
            $fileicon = \html_writer::tag('span',
                get_string('knowledgebase_file_not_saved', 'local_ai_programming_assistant'),
                ['class' => 'text-muted']
            );
        }
        echo \html_writer::tag('td', $fileicon);
        echo \html_writer::tag('td', userdate($file->timecreated));
        $viewurl = kb_service::get_file_view_url($file->id);
        $deleteurl = new \moodle_url($PAGE->url, [
            'action' => 'delete',
            'fileid' => $file->id,
            'sesskey' => sesskey(),
        ]);
        $rebuildfileurl = new \moodle_url($PAGE->url, [
            'action' => 'rebuildfile',
            'fileid' => $file->id,
            'sesskey' => sesskey(),
        ]);
        echo \html_writer::tag('td',
            \html_writer::link($viewurl,
                get_string('view'),
                ['class' => 'btn btn-sm btn-secondary me-1', 'target' => '_blank']
            ) .
            \html_writer::link($rebuildfileurl,
                get_string('knowledgebase_file_rebuild_button', 'local_ai_programming_assistant'),
                ['class' => 'btn btn-sm btn-outline-warning me-1',
                 // 传原始文件名，由 html_writer 统一转义，避免双重转义导致 confirm 弹窗显示乱码
                 'data-confirm' => get_string('knowledgebase_file_confirm_rebuild', 'local_ai_programming_assistant', $file->name)]
            ) .
            \html_writer::link($deleteurl,
                get_string('delete'),
                ['class' => 'btn btn-sm btn-danger', 'data-confirm' => get_string('knowledgebase_confirm_delete', 'local_ai_programming_assistant', $file->name)]
            )
        );
        echo \html_writer::end_tag('tr');
    }

    echo \html_writer::end_tag('tbody') . \html_writer::end_tag('table');
    echo \html_writer::end_div();

    // 批量删除按钮
    echo \html_writer::start_div('card-body');
    echo \html_writer::tag('button', get_string('knowledgebase_batch_delete_button', 'local_ai_programming_assistant'), [
        'type' => 'button',
        'id' => 'batchdeletebtn',
        'class' => 'btn btn-danger',
        'disabled' => 'disabled',
    ]);
    echo \html_writer::end_div();
    echo \html_writer::end_tag('form');

    // 分页导航
    if ($totalpages > 1) {
        echo \html_writer::start_div('card-body');
        echo \html_writer::start_tag('nav', ['aria-label' => 'Pagination']);
        echo \html_writer::start_tag('ul', ['class' => 'pagination justify-content-center']);

        // 上一页
        if ($page > 0) {
            $prevurl = new \moodle_url($PAGE->url, ['page' => $page - 1, 'search' => $search, 'tag' => $tag]);
            echo \html_writer::tag('li',
                \html_writer::link($prevurl, get_string('previous'), ['class' => 'page-link']),
                ['class' => 'page-item']
            );
        } else {
            echo \html_writer::tag('li',
                \html_writer::tag('span', get_string('previous'), ['class' => 'page-link disabled']),
                ['class' => 'page-item disabled']
            );
        }

        // 页码
        for ($i = 0; $i < $totalpages; $i++) {
            $pageurl = new \moodle_url($PAGE->url, ['page' => $i, 'search' => $search, 'tag' => $tag]);
            $activeclass = $i === $page ? 'active' : '';
            echo \html_writer::tag('li',
                \html_writer::link($pageurl, $i + 1, ['class' => 'page-link']),
                ['class' => "page-item {$activeclass}"]
            );
        }

        // 下一页
        if ($page < $totalpages - 1) {
            $nexturl = new \moodle_url($PAGE->url, ['page' => $page + 1, 'search' => $search, 'tag' => $tag]);
            echo \html_writer::tag('li',
                \html_writer::link($nexturl, get_string('next'), ['class' => 'page-link']),
                ['class' => 'page-item']
            );
        } else {
            echo \html_writer::tag('li',
                \html_writer::tag('span', get_string('next'), ['class' => 'page-link disabled']),
                ['class' => 'page-item disabled']
            );
        }

        echo \html_writer::end_tag('ul') . \html_writer::end_tag('nav');
        echo \html_writer::end_div();
    }
}
echo \html_writer::end_div();

// 确认删除的 JS
$PAGE->requires->js_call_amd('local_ai_programming_assistant/kb_manage', 'init');

echo $output->footer();