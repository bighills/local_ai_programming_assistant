<?php
/**
 * AI 编程助手 - 知识库数据表安装脚本
 *
 * 本脚本直接创建知识库所需的全部数据表（含 v2 倒排索引），
 * 用于绕过 CLI upgrade 的环境检查限制。
 *
 * 使用方法：
 *   sudo -u www-data php local/ai_programming_assistant/cli/install_kb_tables.php
 *
 * @package    local_ai_programming_assistant
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

global $DB;

$dbman = $DB->get_manager();

cli_heading('Installing knowledge base tables for local_ai_programming_assistant');

// ==== 1) local_ai_prog_assist_kb_files 表 ====
$table = new xmldb_table('local_ai_prog_assist_kb_files');

if ($dbman->table_exists($table)) {
    cli_writeln('[OK] Table local_ai_prog_assist_kb_files already exists.');

    // 检查 tag 字段是否存在（v2026072101 新增）
    $tagfield = new xmldb_field('tag', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '数据与计算', 'timemodified');
    if (!$dbman->field_exists($table, $tagfield)) {
        $dbman->add_field($table, $tagfield);
        $tagindex = new xmldb_index('tag', XMLDB_INDEX_NOTUNIQUE, ['tag']);
        if (!$dbman->index_exists($table, $tagindex)) {
            $dbman->add_index($table, $tagindex);
        }
        cli_writeln('[OK] Added tag field and index to kb_files.');
    } else {
        cli_writeln('[OK] tag field already exists in kb_files.');
    }
} else {
    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
    $table->add_field('filename', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
    $table->add_field('filesize', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('filehash', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
    $table->add_field('chunkcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('tag', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '数据与计算');
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
    $table->add_index('filehash', XMLDB_INDEX_NOTUNIQUE, ['filehash']);
    $table->add_index('tag', XMLDB_INDEX_NOTUNIQUE, ['tag']);
    $dbman->create_table($table);
    cli_writeln('[OK] Created table local_ai_prog_assist_kb_files (with tag field).');
}

// ==== 2) local_ai_prog_assist_kb_chunks 表 ====
$table = new xmldb_table('local_ai_prog_assist_kb_chunks');

if ($dbman->table_exists($table)) {
    cli_writeln('[OK] Table local_ai_prog_assist_kb_chunks already exists.');

    // 检查 term_count 字段是否存在（v2026072100 新增）
    $tcfield = new xmldb_field('term_count', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'content');
    if (!$dbman->field_exists($table, $tcfield)) {
        $dbman->add_field($table, $tcfield);
        cli_writeln('[OK] Added term_count field to kb_chunks.');
    } else {
        cli_writeln('[OK] term_count field already exists in kb_chunks.');
    }
} else {
    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $table->add_field('fileid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('chunkindex', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('content', XMLDB_TYPE_TEXT, 'big', null, XMLDB_NOTNULL, null, null);
    $table->add_field('term_count', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table->add_key('fileid', XMLDB_KEY_FOREIGN, ['fileid'], 'local_ai_prog_assist_kb_files', ['id']);
    $table->add_index('fileid-chunkindex', XMLDB_INDEX_UNIQUE, ['fileid', 'chunkindex']);
    $dbman->create_table($table);
    cli_writeln('[OK] Created table local_ai_prog_assist_kb_chunks (with term_count).');
}

// ==== 3) local_ai_prog_assist_kb_terms 倒排索引表（v2） ====
$table = new xmldb_table('local_ai_prog_assist_kb_terms');
if (!$dbman->table_exists($table)) {
    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $table->add_field('term', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
    $table->add_field('chunkid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('frequency', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table->add_index('term-idx', XMLDB_INDEX_NOTUNIQUE, ['term']);
    $table->add_index('chunkid-idx', XMLDB_INDEX_NOTUNIQUE, ['chunkid']);
    $table->add_index('term-chunk-uix', XMLDB_INDEX_UNIQUE, ['term', 'chunkid']);
    $dbman->create_table($table);
    cli_writeln('[OK] Created table local_ai_prog_assist_kb_terms (inverted index).');
} else {
    cli_writeln('[OK] Table local_ai_prog_assist_kb_terms already exists.');
}

// ==== 4) local_ai_prog_assist_kb_coll_stats BM25 集合统计表（v2） ====
$table = new xmldb_table('local_ai_prog_assist_kb_coll_stats');
if (!$dbman->table_exists($table)) {
    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $table->add_field('stat_name', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
    $table->add_field('stat_value', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table->add_index('stat-name-uix', XMLDB_INDEX_UNIQUE, ['stat_name']);
    $dbman->create_table($table);
    cli_writeln('[OK] Created table local_ai_prog_assist_kb_coll_stats (BM25 stats).');
} else {
    cli_writeln('[OK] Table local_ai_prog_assist_kb_coll_stats already exists.');
}

// 不修改 config_plugins 中的插件版本号：回退版本会触发 Moodle 重复执行
// db/upgrade.php，造成升级状态错乱。表结构变更应通过 db/install.xml / db/upgrade.php 管理。

cli_heading('Done! All knowledge base tables installed successfully (v2 with inverted index + BM25).');