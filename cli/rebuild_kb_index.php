<?php
/**
 * AI 编程助手 - 重建知识库倒排索引
 *
 * 本脚本扫描所有已存在的知识库分块，重新构建倒排索引和 BM25 集合统计。
 * 在以下情况下需要运行：
 * - 升级到新版分词器后（如 kb_segmenter 词典或算法变更）
 * - 手动修复损坏的索引
 * - 批量导入知识库文件后确保索引完整
 *
 * 使用方法：
 *   sudo -u www-data php local/ai_programming_assistant/cli/rebuild_kb_index.php
 *
 * @package    local_ai_programming_assistant
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_ai_programming_assistant\kb_service;

global $DB;

cli_heading('Rebuilding knowledge base inverted index for local_ai_programming_assistant');

// 检查倒排索引表是否存在
if (!kb_service::has_inverted_index_table()) {
    cli_writeln('[ERROR] Inverted index table does not exist. Please run the plugin upgrade first.');
    exit(1);
}

// 获取所有知识库文件
$files = $DB->get_records('local_ai_prog_assist_kb_files', [], 'id ASC');
$filecount = count($files);

if ($filecount === 0) {
    cli_writeln('[INFO] No knowledge base files found. Nothing to rebuild.');
    exit(0);
}

cli_writeln("Found {$filecount} knowledge base file(s).");

// 清空现有倒排索引（避免残留数据）
// 与 kb_service::rebuild_all_indexes() 保持一致：三步清理包入事务，
// 防止重建中途中断（超时/进程被杀）导致索引与 term_count 处于半状态。
cli_writeln('[...] Clearing existing inverted index...');
$transaction = $DB->start_delegated_transaction();
try {
    $DB->delete_records('local_ai_prog_assist_kb_terms');
    $DB->delete_records('local_ai_prog_assist_kb_coll_stats');
    // 重置所有分块的 term_count
    $DB->execute("UPDATE {local_ai_prog_assist_kb_chunks} SET term_count = 0");
    $transaction->allow_commit();
} catch (\Throwable $e) {
    $transaction->rollback($e);
    throw $e;
}

$totalchunks = 0;
$totalterms = 0;
$indexed = 0;
$errors = 0;

foreach ($files as $file) {
    cli_writeln("[...] Indexing file #{$file->id}: {$file->name} ({$file->filename})");

    try {
        // 传入 false：跳过每次循环的全表统计重建（O(n²)），循环结束后统一重建一次
        kb_service::build_index_for_file($file->id, false);
        $indexed++;

        // 统计进度
        $chunks = $DB->count_records('local_ai_prog_assist_kb_chunks', ['fileid' => $file->id]);
        $totalchunks += $chunks;

        cli_writeln("      -> {$chunks} chunk(s) indexed.");

        // 每处理完一个文件，主动释放内存防止大型知识库累积
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    } catch (\Throwable $e) {
        cli_writeln("[ERROR] Failed to index file #{$file->id}: " . $e->getMessage());
        $errors++;
    }
}

// 全部文件重建完成后统一重建一次 BM25 集合统计
cli_writeln('[...] Rebuilding collection statistics...');
kb_service::rebuild_collection_stats();

// 最终统计
$totalterms = $DB->get_field_sql(
    "SELECT COALESCE(SUM(frequency), 0) FROM {local_ai_prog_assist_kb_terms}"
);
$maxmodified = $DB->get_field_sql("SELECT MAX(timemodified) FROM {local_ai_prog_assist_kb_files}");

cli_heading('Rebuild complete!');
cli_writeln("  Files processed:     {$indexed} / {$filecount}");
cli_writeln("  Errors:              {$errors}");
cli_writeln("  Total chunks:        {$totalchunks}");
cli_writeln("  Total indexed terms: {$totalterms}");
cli_writeln("  Content hash:        " . hash('sha256', ($maxmodified ?: '0') . ':' . $filecount));

if ($errors > 0) {
    cli_writeln('');
    cli_writeln('[WARNING] Some files failed to index. Check the error messages above.');
}

cli_writeln('');
cli_writeln('[OK] Knowledge base inverted index has been rebuilt successfully.');