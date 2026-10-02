#!/usr/bin/env php
<?php
/**
 * AI 编程助手 - 知识库搜索测试脚本
 *
 * 运行代表性查询并输出搜索结果,用于验证搜索优化效果。
 *
 * Usage:
 *     sudo -u www-data php local/ai_programming_assistant/cli/test_search.php
 *     sudo -u www-data php local/ai_programming_assistant/cli/test_search.php "查询词"
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_ai_programming_assistant\kb_service;

// 代表性测试查询
$testQueries = [
    'binary tree traversal',
    'quick sort time complexity',
    'hash table collision resolution',
    'dynamic programming fundamentals',
    'linked list vs array',
    'graph depth-first traversal',
    'stack and queue applications',
    'B+ tree characteristics',
];

// 如果命令行传入了查询词,仅测试该查询
if (!empty($argv[1])) {
    $testQueries = [$argv[1]];
}

cli_heading('AI Programming Assistant - Knowledge Base Search Test');

if (!kb_service::is_enabled()) {
    cli_problem('Knowledge base is not enabled');
    exit(1);
}

foreach ($testQueries as $query) {
    echo str_repeat('=', 70) . "\n";
    echo "Query: {$query}\n";
    echo str_repeat('-', 70) . "\n";

    $results = kb_service::search($query);

    if (empty($results)) {
        echo "[No results]\n\n";
        continue;
    }

    echo "Matched chunks: " . count($results) . "\n\n";

    foreach ($results as $idx => $result) {
        $relevance = isset($result['relevance']) ? sprintf('%.4f', $result['relevance']) : 'N/A';
        $isNeighbor = !empty($result['is_neighbor']) ? ' (neighboring)' : '';
        $chunkNum = isset($result['chunkindex']) ? ((int)$result['chunkindex'] + 1) : '?';
        $rank = $idx + 1;

        echo "[{$rank}] relevance: {$relevance} | file: {$result['filename']} | chunk #{$chunkNum}{$isNeighbor}\n";

        // 内容预览(前 200 字符)
        $preview = mb_substr($result['content'], 0, 200, 'UTF-8');
        $preview = str_replace("\n", ' ', $preview);
        echo "        {$preview}\n";
        echo "\n";
    }
}

echo str_repeat('=', 70) . "\n";
echo "Test completed.\n";