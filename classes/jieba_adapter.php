<?php
/**
 * AI 编程助手 - Jieba 中文分词适配器
 *
 * 通过调用 cli/jieba_segment.py 使用 Python Jieba 精确模式分词，
 * 作为知识库倒排索引构建时的主分词引擎（kb_segmenter 为降级备用）。
 *
 * 协议（与 cli/jieba_segment.py 保持一致）：
 * - stdin 每行一条待分词文本
 * - stdout 每行输出空格分隔的分词结果
 * - 空行输出为空行
 *
 * @package    local_ai_programming_assistant
 * @author zfl
 */
namespace local_ai_programming_assistant;

defined('MOODLE_INTERNAL') || die();

class jieba_adapter {

    /** @var string jieba 分词脚本路径 */
    private const SCRIPT = __DIR__ . '/../cli/jieba_segment.py';

    /** @var int 单次批量调用超时（秒），大文档批量分词时预留充分时间 */
    private const TIMEOUT = 120;

    /** @var bool|null 可用性缓存（进程内只探测一次） */
    private static ?bool $available = null;

    /**
     * 检查 Jieba 分词是否可用
     *
     * 要求：python3 命令存在且 cli/jieba_segment.py 存在。
     * 不在此处验证 jieba 库是否已安装——缺失时 Python 脚本会以非零退出，
     * 由 batch_segment() 捕获后回退到内置分词器。
     *
     * @return bool
     */
    public static function is_available(): bool {
        if (self::$available !== null) {
            return self::$available;
        }
        self::$available = false;
        if (!is_file(self::SCRIPT)) {
            return self::$available;
        }
        $python = trim((string)@shell_exec('command -v python3 2>/dev/null'));
        self::$available = ($python !== '');
        return self::$available;
    }

    /**
     * 对单段文本分词
     *
     * @param string $text 待分词文本
     * @return array 分词结果（扁平词数组）
     */
    public static function segment(string $text): array {
        $batch = self::batch_segment([$text]);
        return $batch[0] ?? [];
    }

    /**
     * 批量分词（一次 Python 进程调用完成所有文本）
     *
     * @param array $texts 待分词文本数组
     * @return array 每项为该文本的分词结果数组；失败时返回空数组
     */
    public static function batch_segment(array $texts): array {
        if (empty($texts) || !self::is_available()) {
            return [];
        }

        // 清理文本中的换行，避免破坏逐行协议
        $texts = array_map(function (string $text): string {
            return str_replace(["\r", "\n"], ' ', $text);
        }, $texts);

        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $cmd = 'python3 ' . escapeshellarg(self::SCRIPT);
        $process = @proc_open($cmd, $descriptorspec, $pipes);
        if (!is_resource($process)) {
            return [];
        }

        // 边写 stdin 边排空 stdout/stderr：Linux 管道缓冲默认约 64KB，
        // 若先写满全部输入再读输出，Python 写 stdout 被阻塞后将停止读 stdin，
        // 双方互等造成死锁。因此写入循环内通过 stream_select 同时读取两个输出流。
        $stdin = $pipes[0];
        $stdout = $pipes[1];
        $stderrpipe = $pipes[2];
        stream_set_blocking($stdin, false);
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderrpipe, false);

        $pending = implode("\n", $texts) . "\n";
        $offset = 0;
        $timelimit = microtime(true) + self::TIMEOUT;
        $output = '';
        $stderr = '';

        while ($offset < strlen($pending)) {
            if (microtime(true) > $timelimit) {
                break;
            }
            $read = [$stdout, $stderrpipe];
            $write = [$stdin];
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, 200000);
            if ($ready === false) {
                break;
            }
            if ($write) {
                $written = @fwrite($stdin, substr($pending, $offset));
                if ($written === false) {
                    break;
                }
                if ($written > 0) {
                    $offset += $written;
                }
            }
            foreach ($read as $stream) {
                $chunk = @fread($stream, 65536);
                if ($chunk !== false && $chunk !== '') {
                    if ($stream === $stdout) {
                        $output .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }
            }
        }
        @fclose($stdin);

        // 排空进程退出前剩余的输出。
        // 注意：stdout/stderr 仍为非阻塞，直接 fread 会因数据未到立即返回空导致输出丢失；
        // 若此时 fclose 管道，Python 后续写入会收到 SIGPIPE 被杀死（proc_close 返回非零）。
        // 因此必须用 stream_select 持续读取，直到两个管道都到达 EOF。
        while (microtime(true) < $timelimit) {
            $read = [$stdout, $stderrpipe];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, 200000);
            if ($ready === false) {
                break;
            }
            $botheof = stream_get_meta_data($stdout)['eof'] && stream_get_meta_data($stderrpipe)['eof'];
            if ($botheof) {
                break;
            }
            if ($ready === 0) {
                continue;
            }
            foreach ($read as $stream) {
                $chunk = @fread($stream, 65536);
                if ($chunk !== false && $chunk !== '') {
                    if ($stream === $stdout) {
                        $output .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }
            }
        }
        fclose($stdout);
        fclose($stderrpipe);

        // 若排空循环因超时退出（进程尚未自然结束），proc_close() 会同步阻塞等待子进程退出，
        // 且无任何超时保护——Python 子进程挂起时 PHP 进程会在此无限阻塞。
        // 超时后先 proc_terminate() 发送 SIGTERM，确保 proc_close() 能立即返回。
        if (microtime(true) >= $timelimit) {
            @proc_terminate($process);
            \local_ai_programming_assistant\config_manager::debug_log('jieba batch_segment timed out, process terminated', [
                'text_count' => count($texts),
                'output_len' => strlen($output),
            ]);
        }

        $exitcode = @proc_close($process);

        if ($exitcode !== 0) {
            // jieba 库缺失或脚本异常：回退由调用方使用内置分词器
            if ($stderr !== '') {
                \local_ai_programming_assistant\config_manager::debug_log('jieba batch_segment failed', $stderr);
            }
            self::$available = false;
            return [];
        }

        $result = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '') {
                $result[] = [];
                continue;
            }
            $result[] = explode(' ', $line);
        }
        // 输入以 "\n" 结尾会令 Python 多输出一个空行（N 行输入 → N+1 行输出），
        // 截断为与输入等长，避免调用方依据行数做回退判断时误判为失败。
        if (count($result) > count($texts)) {
            array_pop($result);
        }
        return $result;
    }
}
