<?php
/**
 * AI 编程助手 - 知识库文件内容查看页面
 *
 * 显示知识库中某个文件的元数据和所有文本分块内容。
 * 供 AI 回复中的引用链接跳转查看完整教材内容。
 *
 * @package    local_ai_programming_assistant
 */

require(__DIR__ . '/../../config.php');

use local_ai_programming_assistant\kb_service;

$fileid = optional_param('fileid', 0, PARAM_INT);
$name = optional_param('name', '', PARAM_TEXT);

require_login();

$syscontext = context_system::instance();
// viewknowledgebase 由学生/教师/管理员 archetype 默认拥有（db/access.php），
// 但角色通常只分配在课程上下文，系统上下文 require_capability 会误拒绝学生（403）。
// 使用兼容回退的辅助函数：系统上下文或任意课程上下文拥有该能力即放行。
if (!local_ai_programming_assistant_has_view_knowledgebase_capability()) {
    throw new \required_capability_exception($syscontext, 'local/ai_programming_assistant:viewknowledgebase', 'nopermissions', '');
}

// 支持通过 fileid 或 name 参数定位文件
if ($fileid > 0) {
    $file = kb_service::get_file($fileid);
} elseif (!empty(trim($name))) {
    global $DB;
    // 通过名称查找文件（精确匹配 name 或 filename 字段）
    // name/filename 字段无唯一约束，使用 get_records + limit 1 避免
    // get_record 在多条匹配时触发 "found more than one record" 调试警告
    $files = $DB->get_records('local_ai_prog_assist_kb_files', ['name' => trim($name)], 'id ASC', '*', 0, 1);
    $file = $files ? reset($files) : null;
    if (!$file) {
        $files = $DB->get_records('local_ai_prog_assist_kb_files', ['filename' => trim($name)], 'id ASC', '*', 0, 1);
        $file = $files ? reset($files) : null;
    }
    if (!$file) {
        // 模糊匹配：处理 AI 引用时省略扩展名的情况
        $likeparam = $DB->sql_like_escape(trim($name)) . '.%';
        $file = $DB->get_records_select(
            'local_ai_prog_assist_kb_files',
            $DB->sql_like('name', ':likename') . ' OR ' . $DB->sql_like('filename', ':likefilename'),
            ['likename' => $likeparam, 'likefilename' => $likeparam],
            'id ASC',
            '*',
            0,
            1
        );
        $file = $file ? reset($file) : null;
    }
    if (!$file) {
        // 包含匹配：处理 AI 引用名称与文件名部分匹配的情况（如 AI 省略了部分后缀或前后缀）
        $trimmed = trim($name);
        if (mb_strlen($trimmed, 'UTF-8') >= 2) {
            $likeparam = '%' . $DB->sql_like_escape($trimmed) . '%';
            $file = $DB->get_records_select(
                'local_ai_prog_assist_kb_files',
                $DB->sql_like('name', ':likename') . ' OR ' . $DB->sql_like('filename', ':likefilename'),
                ['likename' => $likeparam, 'likefilename' => $likeparam],
                'id ASC',
                '*',
                0,
                1
            );
            $file = $file ? reset($file) : null;
        }
    }
    if ($file) {
        $fileid = (int)$file->id;
    }
} else {
    throw new \moodle_exception('missingparam', 'error');
}

if (!$file) {
    throw new \moodle_exception('knowledgebase_file_not_found', 'local_ai_programming_assistant');
}

$PAGE->set_url('/local/ai_programming_assistant/kb_view.php', ['fileid' => $fileid]);
$PAGE->set_context($syscontext);

// set_title/set_heading 内部会做输出转义，此处传原始文件名，避免双重转义
$PAGE->set_title(get_string('knowledgebase_view_title', 'local_ai_programming_assistant', $file->name));
$PAGE->set_heading(get_string('knowledgebase_view_title', 'local_ai_programming_assistant', $file->name));

// 获取所有分块
global $DB;
$chunks = $DB->get_records('local_ai_prog_assist_kb_chunks', ['fileid' => $fileid], 'chunkindex ASC');

$output = $PAGE->get_renderer('core');
echo $output->header();

// 返回链接
// 管理员返回知识库管理页，学生返回来源页或首页
if (has_capability('local/ai_programming_assistant:config', $syscontext)) {
    $backurl = new \moodle_url('/local/ai_programming_assistant/kb_manage.php');
} else {
    $referer = optional_param('referer', '', PARAM_LOCALURL);
    if (!empty($referer)) {
        $backurl = new \moodle_url($referer);
    } else {
        // 仅使用站内来源 URL，防止开放重定向到外部站点
        $localreferer = get_local_referer(false);
        if ($localreferer) {
            $backurl = new \moodle_url($localreferer);
        } else {
            $backurl = new \moodle_url('/');
        }
    }
}
echo \html_writer::link($backurl, get_string('back'), ['class' => 'btn btn-sm btn-secondary mb-3']);

// 文件信息卡片
echo \html_writer::start_div('card mb-4');
echo \html_writer::tag('h4', get_string('knowledgebase_file_info', 'local_ai_programming_assistant'), ['class' => 'card-header']);
echo \html_writer::start_div('card-body');
echo \html_writer::start_tag('dl', ['class' => 'row mb-0']);

echo \html_writer::tag('dt', get_string('knowledgebase_file_name', 'local_ai_programming_assistant'), ['class' => 'col-sm-3']);
echo \html_writer::tag('dd', s($file->name), ['class' => 'col-sm-9']);

echo \html_writer::tag('dt', get_string('knowledgebase_file_filename', 'local_ai_programming_assistant'), ['class' => 'col-sm-3']);
echo \html_writer::tag('dd', s($file->filename), ['class' => 'col-sm-9']);

echo \html_writer::tag('dt', get_string('knowledgebase_file_size', 'local_ai_programming_assistant'), ['class' => 'col-sm-3']);
echo \html_writer::tag('dd', display_size($file->filesize), ['class' => 'col-sm-9']);

echo \html_writer::tag('dt', get_string('knowledgebase_file_chunks', 'local_ai_programming_assistant'), ['class' => 'col-sm-3']);
echo \html_writer::tag('dd', (int)$file->chunkcount, ['class' => 'col-sm-9']);

echo \html_writer::tag('dt', get_string('knowledgebase_file_timecreated', 'local_ai_programming_assistant'), ['class' => 'col-sm-3']);
echo \html_writer::tag('dd', userdate($file->timecreated), ['class' => 'col-sm-9']);

echo \html_writer::tag('dt', get_string('knowledgebase_file_download', 'local_ai_programming_assistant'), ['class' => 'col-sm-3']);
$downloadurl = kb_service::get_download_url($fileid);
if ($downloadurl) {
    $downloadlink = \html_writer::link(
        $downloadurl,
        s($file->filename),
        ['class' => 'btn btn-sm btn-primary', 'target' => '_blank']
    );
    echo \html_writer::tag('dd', $downloadlink, ['class' => 'col-sm-9']);
} else {
    echo \html_writer::tag('dd',
        \html_writer::tag('span', get_string('knowledgebase_file_not_saved', 'local_ai_programming_assistant'), ['class' => 'text-muted']),
        ['class' => 'col-sm-9']
    );
}

echo \html_writer::end_tag('dl');
echo \html_writer::end_div();
echo \html_writer::end_div();

// 文件预览
$downloadurl = kb_service::get_download_url($fileid);
$previewurl = kb_service::get_preview_url($fileid);
if ($downloadurl) {
    $ext = strtolower(pathinfo($file->filename, PATHINFO_EXTENSION));
    echo \html_writer::start_div('card mb-4');
    echo \html_writer::tag('h4', get_string('knowledgebase_file_preview', 'local_ai_programming_assistant'), ['class' => 'card-header']);
    echo \html_writer::start_div('card-body');

    if ($ext === 'pdf' && $previewurl) {
        // PDF 嵌入式预览（使用不强制下载的 URL）
        echo \html_writer::tag('div',
            \html_writer::tag('iframe', '', [
                'src' => $previewurl,
                'style' => 'width: 100%; height: 650px; border: 1px solid #dee2e6; border-radius: 4px;',
                'title' => s($file->name),
                'allowfullscreen' => 'true',
            ]),
            ['style' => 'margin: -0.5rem;']
        );
    } elseif (in_array($ext, ['txt', 'md', 'markdown'])) {
        // 纯文本内容显示
        $rawcontent = kb_service::get_file_raw_content($fileid);
        if ($rawcontent !== null) {
            $lines = substr_count($rawcontent, "\n") + 1;
            $maxheight = min(650, max(200, $lines * 22));
            echo \html_writer::tag('pre', s($rawcontent), [
                'style' => 'max-height: ' . $maxheight . 'px; overflow-y: auto; white-space: pre-wrap; word-wrap: break-word; margin: 0;',
                'class' => 'bg-light p-3 rounded',
            ]);
        } else {
            echo \html_writer::tag('p',
                get_string('knowledgebase_preview_not_available', 'local_ai_programming_assistant'),
                ['class' => 'text-muted mb-0']
            );
        }
    } else {
        // 其他格式（DOCX 等）提示无法预览
        echo \html_writer::tag('p',
            get_string('knowledgebase_preview_not_available', 'local_ai_programming_assistant'),
            ['class' => 'text-muted']
        );
        echo \html_writer::link(
            $downloadurl,
            get_string('download'),
            ['class' => 'btn btn-primary', 'target' => '_blank']
        );
    }

    echo \html_writer::end_div();
    echo \html_writer::end_div();
}

// 文本分块内容
echo \html_writer::start_div('card');
echo \html_writer::tag('h4', get_string('knowledgebase_file_content', 'local_ai_programming_assistant'), ['class' => 'card-header']);

if (empty($chunks)) {
    echo \html_writer::div(
        \html_writer::tag('p', get_string('knowledgebase_no_chunks', 'local_ai_programming_assistant'), ['class' => 'text-muted text-center my-3']),
        'card-body'
    );
} else {
    echo \html_writer::start_div('card-body p-0');
    $chunknum = 0;
    foreach ($chunks as $chunk) {
        $chunknum++;
        echo \html_writer::start_div('kb-chunk' . ($chunknum > 1 ? ' border-top' : ''), ['id' => 'chunk-' . $chunknum]);
        echo \html_writer::tag('div',
            get_string('knowledgebase_chunk_label', 'local_ai_programming_assistant', $chunknum),
            ['class' => 'kb-chunk-label']
        );
        echo \html_writer::tag('pre', s($chunk->content), ['class' => 'kb-chunk-content']);
        echo \html_writer::end_div();
    }
    echo \html_writer::end_div();
}

echo \html_writer::end_div();

echo $output->footer();