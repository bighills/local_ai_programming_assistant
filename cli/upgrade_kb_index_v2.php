<?php
/**
 * AI 编程助手 - 知识库 v2 倒排索引升级脚本
 *
 * 直接执行数据库升级（绕过 Moodle CLI upgrade 环境检查限制）。
 *
 * 使用方法：
 *   sudo -u www-data php local/ai_programming_assistant/cli/upgrade_kb_index_v2.php
 *
 * @package    local_ai_programming_assistant
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

global $DB;

$dbman = $DB->get_manager();

cli_heading('Upgrading knowledge base to v2 (inverted index + BM25)');

// 1) 添加 term_count 字段到 kb_chunks
cli_writeln('[1/3] Adding term_count field to kb_chunks...');
$table = new xmldb_table('local_ai_prog_assist_kb_chunks');
$field = new xmldb_field('term_count', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'content');
if (!$dbman->field_exists($table, $field)) {
    $dbman->add_field($table, $field);
    cli_writeln('  -> Created term_count field.');
} else {
    cli_writeln('  -> term_count field already exists.');
}

// 2) 创建倒排索引表
cli_writeln('[2/3] Creating inverted index table...');
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
    cli_writeln('  -> Created kb_terms table.');
} else {
    cli_writeln('  -> kb_terms table already exists.');
}

// 3) 创建 BM25 集合统计表
cli_writeln('[3/3] Creating collection stats table...');
$table = new xmldb_table('local_ai_prog_assist_kb_coll_stats');
if (!$dbman->table_exists($table)) {
    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $table->add_field('stat_name', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
    $table->add_field('stat_value', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table->add_index('stat-name-uix', XMLDB_INDEX_UNIQUE, ['stat_name']);
    $dbman->create_table($table);
    cli_writeln('  -> Created kb_coll_stats table.');
} else {
    cli_writeln('  -> kb_coll_stats table already exists.');
}

// 不修改 config_plugins 中的插件版本号：回退版本会触发 Moodle 重复执行
// db/upgrade.php，造成升级状态错乱。表结构变更应通过 db/install.xml / db/upgrade.php 管理。

cli_heading('Database upgrade complete. Now rebuilding inverted index...');

// 4) 运行索引重建（使用已安装的表和新的分词器）
$files = $DB->get_records('local_ai_prog_assist_kb_files', [], 'id ASC');
$filecount = count($files);

if ($filecount === 0) {
    cli_writeln('[INFO] No knowledge base files found. Index rebuild skipped.');
} else {
    cli_writeln("Found {$filecount} knowledge base file(s). Rebuilding inverted index...");

    // 清空旧索引
    $DB->delete_records('local_ai_prog_assist_kb_terms');
    $DB->delete_records('local_ai_prog_assist_kb_coll_stats');
    $DB->execute("UPDATE {local_ai_prog_assist_kb_chunks} SET term_count = 0");

    $totalchunks = 0;
    $errors = 0;

    foreach ($files as $file) {
        cli_write("  [{$file->id}] {$file->name}... ");
        try {
            // 传入 false：跳过每次循环的全表统计重建（O(n²)），循环结束后统一重建一次
            \local_ai_programming_assistant\kb_service::build_index_for_file($file->id, false);
            $chunks = $DB->count_records('local_ai_prog_assist_kb_chunks', ['fileid' => $file->id]);
            $totalchunks += $chunks;
            cli_writeln("{$chunks} chunks OK");
        } catch (\Throwable $e) {
            cli_writeln("ERROR: " . $e->getMessage());
            $errors++;
        }
    }

    // 全部文件重建完成后统一重建一次 BM25 集合统计
    \local_ai_programming_assistant\kb_service::rebuild_collection_stats();

    $totalterms = $DB->get_field_sql(
        "SELECT COALESCE(SUM(frequency), 0) FROM {local_ai_prog_assist_kb_terms}"
    );

    cli_heading('Rebuild summary');
    cli_writeln("  Files:       {$filecount}");
    cli_writeln("  Chunks:      {$totalchunks}");
    cli_writeln("  Terms:       {$totalterms}");
    cli_writeln("  Errors:      {$errors}");
}

cli_heading('Done! Knowledge base v2 (inverted index + BM25) is now active.');