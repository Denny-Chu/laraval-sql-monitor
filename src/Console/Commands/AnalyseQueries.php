<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use LaravelSqlMonitor\StaticAnalysis\Ast\AstAnalyser;
use LaravelSqlMonitor\StaticAnalysis\Ast\QueryCallSite;
use LaravelSqlMonitor\StaticAnalysis\CallSiteAnalyser;
use LaravelSqlMonitor\StaticAnalysis\CallSiteReport;
use LaravelSqlMonitor\StaticAnalysis\IndexInspector;

/**
 * php artisan sql-monitor:analyse
 *
 * 靜態分析程式碼中的所有資料庫查詢呼叫，
 * 預測查詢成本、偵測索引使用問題並提供最佳化建議。
 *
 * 使用範例：
 *   php artisan sql-monitor:analyse                           # 掃描整個 app/ 目錄
 *   php artisan sql-monitor:analyse --path=app/Models         # 掃描指定目錄
 *   php artisan sql-monitor:analyse --path=app/Http/Controllers/UserController.php
 *   php artisan sql-monitor:analyse --class=App\\Models\\User
 *   php artisan sql-monitor:analyse --class=UserController    # 短名稱（自動搜尋）
 *   php artisan sql-monitor:analyse --format=json             # JSON 輸出
 *   php artisan sql-monitor:analyse --min-severity=warning    # 只顯示 warning 以上
 *   php artisan sql-monitor:analyse --no-index-check          # 不查資料庫索引
 *   php artisan sql-monitor:analyse --sort=cost               # 按成本排序
 */
class AnalyseQueries extends Command
{
    protected $signature = 'sql-monitor:analyse
        {--path=          : 分析指定的檔案或目錄路徑（相對於專案根目錄）}
        {--class=         : 分析指定的類別（完整命名空間或短名稱）}
        {--format=table   : 輸出格式：table / json / summary}
        {--min-severity=info : 最低顯示嚴重度：info / warning / critical}
        {--no-index-check : 略過資料庫索引檢查（不需要資料庫連線）}
        {--sort=file      : 排序方式：file / cost / complexity / severity}';

    protected $description = '靜態分析程式碼中的資料庫查詢，預測執行成本並偵測索引使用問題';

    /** 嚴重度圖示 */
    private const SEVERITY_ICONS = [
        'critical' => '●',
        'warning'  => '▲',
        'info'     => 'ℹ',
        'ok'       => '✓',
    ];

    /** 嚴重度顏色標籤 */
    private const SEVERITY_COLORS = [
        'critical' => 'red',
        'warning'  => 'yellow',
        'info'     => 'cyan',
        'ok'       => 'green',
    ];

    /** 成本顏色標籤 */
    private const COST_COLORS = [
        'VERY HIGH' => 'red',
        'HIGH'      => 'red',
        'MEDIUM'    => 'yellow',
        'LOW'       => 'green',
    ];

    /** 是否為 JSON 輸出模式（裝飾輸出導向 stderr，避免污染 JSON） */
    private bool $jsonMode = false;

    // ─── 主要流程 ──────────────────────────────────────────────

    public function handle(): int
    {
        $this->jsonMode = $this->option('format') === 'json';

        $this->printBanner();

        // 1. 解析目標檔案
        $files = $this->resolveTargetFiles();

        if (empty($files)) {
            $this->noticeError('  找不到可分析的 PHP 檔案。');
            return self::FAILURE;
        }

        $this->notice("  找到 " . count($files) . " 個 PHP 檔案，開始掃描...");
        $this->noticeNewLine();

        // 2. AST 掃描：提取所有查詢呼叫點
        $astAnalyser = new AstAnalyser();
        $callSites   = $this->scanFiles($astAnalyser, $files);

        if (empty($callSites)) {
            $this->notice('  未偵測到任何資料庫查詢呼叫。');
            return self::SUCCESS;
        }

        $this->noticeNewLine();
        $this->notice("  偵測到 " . count($callSites) . " 個查詢呼叫點，開始分析...");
        $this->noticeNewLine();

        // 3. 深度分析每個呼叫點
        $indexInspector  = $this->option('no-index-check') ? null : $this->makeIndexInspector();
        $siteAnalyser   = new CallSiteAnalyser($indexInspector);
        $reports         = $siteAnalyser->analyseMany($callSites);

        // 4. 排序
        $reports = $this->sortReports($reports);

        // 5. 輸出
        $format = $this->option('format');

        return match ($format) {
            'json'    => $this->outputJson($reports),
            'summary' => $this->outputSummary($reports),
            default   => $this->outputTable($reports),
        };
    }

    // ─── 檔案解析 ──────────────────────────────────────────────

    /**
     * 根據 --path / --class 選項解析要分析的檔案清單。
     *
     * @return string[]
     */
    private function resolveTargetFiles(): array
    {
        $pathOption  = $this->option('path');
        $classOption = $this->option('class');

        if ($classOption) {
            return $this->resolveClassFiles($classOption);
        }

        if ($pathOption) {
            return $this->resolvePathFiles($pathOption);
        }

        // 預設：掃描 app/ 目錄
        $appPath = base_path('app');

        if (! is_dir($appPath)) {
            $this->warn('  app/ 目錄不存在，嘗試掃描 src/ 目錄...');
            $appPath = base_path('src');
        }

        if (! is_dir($appPath)) {
            return [];
        }

        $this->noticeLine("  <comment>目標：</comment> {$appPath}");

        return $this->scanDirectory($appPath);
    }

    /**
     * 解析 --path 選項。
     */
    private function resolvePathFiles(string $path): array
    {
        // 支援絕對路徑和相對路徑
        $absolutePath = str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : base_path($path);

        if (is_file($absolutePath) && str_ends_with($absolutePath, '.php')) {
            $this->noticeLine("  <comment>目標檔案：</comment> {$absolutePath}");
            return [$absolutePath];
        }

        if (is_dir($absolutePath)) {
            $this->noticeLine("  <comment>目標目錄：</comment> {$absolutePath}");
            return $this->scanDirectory($absolutePath);
        }

        $this->noticeError("  路徑不存在或非 PHP 檔案：{$path}");

        return [];
    }

    /**
     * 解析 --class 選項。
     */
    private function resolveClassFiles(string $className): array
    {
        // 嘗試直接載入完整命名空間
        $file = $this->resolveClassFile($className);

        if ($file) {
            $this->noticeLine("  <comment>目標類別：</comment> {$className} → {$file}");
            return [$file];
        }

        // 短名稱：搜尋常用命名空間
        if (! str_contains($className, '\\')) {
            $namespaces = [
                "App\\{$className}",
                "App\\Models\\{$className}",
                "App\\Http\\Controllers\\{$className}",
                "App\\Services\\{$className}",
                "App\\Repositories\\{$className}",
                "App\\Jobs\\{$className}",
            ];

            foreach ($namespaces as $fqcn) {
                $file = $this->resolveClassFile($fqcn);
                if ($file) {
                    $this->noticeLine("  <comment>目標類別：</comment> {$fqcn} → {$file}");
                    return [$file];
                }
            }

            // 最後嘗試：在 app/ 目錄中搜尋檔名匹配
            $found = $this->findFileByClassName($className);
            if ($found) {
                $this->noticeLine("  <comment>目標檔案（檔名匹配）：</comment> {$found}");
                return [$found];
            }
        }

        $this->noticeError("  找不到類別：{$className}");

        return [];
    }

    /**
     * 透過 ReflectionClass 取得類別的檔案路徑。
     */
    private function resolveClassFile(string $className): ?string
    {
        try {
            $reflection = new \ReflectionClass($className);
            $file = $reflection->getFileName();

            return $file !== false ? $file : null;
        } catch (\ReflectionException) {
            return null;
        }
    }

    /**
     * 在 app/ 目錄中依檔名搜尋類別。
     */
    private function findFileByClassName(string $shortName): ?string
    {
        $target   = "{$shortName}.php";
        $basePath = base_path('app');

        if (! is_dir($basePath)) {
            return null;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->getFilename() === $target) {
                return $file->getPathname();
            }
        }

        return null;
    }

    /**
     * 遞迴掃描目錄中的所有 .php 檔案。
     *
     * @return string[]
     */
    private function scanDirectory(string $dir): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    // ─── AST 掃描 ──────────────────────────────────────────────

    /**
     * 掃描所有檔案，提取查詢呼叫點。
     *
     * @param  string[] $files
     * @return QueryCallSite[]
     */
    private function scanFiles(AstAnalyser $analyser, array $files): array
    {
        $callSites  = [];
        $errorCount = 0;

        $progressOutput = $this->jsonMode ? $this->stderrOutput() : $this->output;
        $bar = new \Symfony\Component\Console\Helper\ProgressBar($progressOutput, count($files));
        $bar->setFormat('  %current%/%max% [%bar%] %percent:3s%% %message%');
        $bar->setMessage('');

        foreach ($files as $filePath) {
            $shortPath = $this->shortenPath($filePath);
            $bar->setMessage($shortPath);

            try {
                $sites     = $analyser->analyseFile($filePath);
                $callSites = array_merge($callSites, $sites);
            } catch (\Throwable $e) {
                $errorCount++;
                // 不中斷掃描，繼續下一個檔案
            }

            $bar->advance();
        }

        $bar->setMessage('<info>完成</info>');
        $bar->finish();

        if ($errorCount > 0) {
            $this->noticeNewLine();
            $this->noticeWarn("  ⚠  有 {$errorCount} 個檔案解析失敗（已跳過）");
        }

        return $callSites;
    }

    // ─── 索引檢查器 ────────────────────────────────────────────

    /**
     * 嘗試建立 IndexInspector，若資料庫不可用則回傳 null。
     *
     * 分兩階段檢查：
     *   1. 連線測試 (SELECT 1)：判斷連線是否可達
     *   2. 索引查詢測試：判斷索引查詢 SQL 本身是否可執行
     * 兩者分開才能避免把 SQL 錯誤誤報為連線錯誤。
     */
    private function makeIndexInspector(): ?IndexInspector
    {
        try {
            \Illuminate\Support\Facades\DB::connection()->select('SELECT 1');
        } catch (\Throwable $e) {
            $this->noticeWarn('  ⚠  無法連線資料庫，索引檢查已停用 (' . $e->getMessage() . ')');
            return null;
        }

        try {
            $inspector = new IndexInspector();
            $inspector->getTableIndexes('__test_connection_ping__');

            return $inspector;
        } catch (\Throwable $e) {
            $this->noticeWarn('  ⚠  索引檢查初始化失敗，已停用 (' . $e->getMessage() . ')');
            return null;
        }
    }

    // ─── 排序 ──────────────────────────────────────────────────

    /**
     * @param  CallSiteReport[] $reports
     * @return CallSiteReport[]
     */
    private function sortReports(array $reports): array
    {
        $sort = $this->option('sort');

        usort($reports, match ($sort) {
            'cost'       => fn(CallSiteReport $a, CallSiteReport $b) => $b->estimatedCost <=> $a->estimatedCost,
            'complexity' => fn(CallSiteReport $a, CallSiteReport $b) => $b->complexityScore <=> $a->complexityScore,
            'severity'   => function (CallSiteReport $a, CallSiteReport $b) {
                $ranks = ['ok' => 0, 'info' => 1, 'warning' => 2, 'critical' => 3];
                return ($ranks[$b->severity()] ?? 0) <=> ($ranks[$a->severity()] ?? 0);
            },
            default => fn(CallSiteReport $a, CallSiteReport $b)
                => $a->callSite->filePath <=> $b->callSite->filePath
                ?: $a->callSite->startLine <=> $b->callSite->startLine,
        });

        return $reports;
    }

    // ─── 輸出：Table 格式 ─────────────────────────────────────

    private function outputTable(array $reports): int
    {
        $minSeverity = $this->option('min-severity');
        $displayed   = 0;

        // 按檔案分組輸出
        $grouped = $this->groupByFile($reports);

        foreach ($grouped as $filePath => $fileReports) {
            $shortPath    = $this->shortenPath($filePath);
            $headerPrinted = false;

            foreach ($fileReports as $report) {
                /** @var CallSiteReport $report */
                $filteredIssues = $report->issuesAbove($minSeverity);

                // 若無符合嚴重度的問題且不是 OK，跳過
                if (empty($filteredIssues) && $report->severity() !== 'ok') {
                    continue;
                }

                // 若嚴重度低於門檻，跳過
                if (! $this->meetsSeverity($report->severity(), $minSeverity)) {
                    continue;
                }

                if (! $headerPrinted) {
                    $this->newLine();
                    $this->line("  <fg=white;options=bold>═══ {$shortPath}</>");
                    $headerPrinted = true;
                }

                $this->printReportEntry($report, $filteredIssues);
                $displayed++;
            }
        }

        if ($displayed === 0) {
            $this->newLine();
            $this->info('  ✓ 所有查詢在指定嚴重度下均無問題。');
        }

        // 摘要統計
        $this->printSummaryStats($reports);

        return $this->hasFailures($reports) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 印出單一報告條目。
     */
    private function printReportEntry(CallSiteReport $report, array $issues): void
    {
        $site     = $report->callSite;
        $severity = $report->severity();
        $color    = self::SEVERITY_COLORS[$severity] ?? 'white';
        $icon     = self::SEVERITY_ICONS[$severity] ?? '?';

        $this->newLine();

        // 位置資訊
        $location = $site->className && $site->methodName
            ? "{$site->className}::{$site->methodName}()"
            : basename($site->filePath);

        $this->line("  <fg={$color}>{$icon}</> <fg=white;options=bold>{$location}</> <fg=gray>行 {$site->startLine}</>");

        // 查詢鏈摘要
        $chain = $site->chainSummary();
        if (strlen($chain) > 100) {
            $chain = substr($chain, 0, 97) . '...';
        }
        $this->line("    <fg=gray>{$chain}</>");

        // 指標行
        $table      = $report->primaryTable ?? '(unknown)';
        $complexity = $report->complexityScore;
        $compLabel  = $report->complexityLabel();
        $compColor  = match ($compLabel) {
            'CRITICAL' => 'red',
            'WARNING'  => 'yellow',
            default    => 'green',
        };

        $cost      = $report->estimatedCost;
        $costLabel = $report->costLabel();
        $costColor = self::COST_COLORS[$costLabel] ?? 'white';

        $this->line(
            "    <fg=gray>表：</><fg=white>{$table}</>"
            . "  <fg=gray>│ 複雜度：</><fg={$compColor}>{$complexity}/100 [{$compLabel}]</>"
            . "  <fg=gray>│ 成本：</><fg={$costColor}>{$cost} [{$costLabel}]</>"
        );

        // 問題清單
        foreach ($issues as $issue) {
            $iColor = self::SEVERITY_COLORS[$issue['severity']] ?? 'white';
            $iIcon  = self::SEVERITY_ICONS[$issue['severity']] ?? '?';
            $this->line("    <fg={$iColor}>{$iIcon}  {$issue['code']}</> {$issue['message']}");
        }
    }

    // ─── 輸出：JSON 格式 ──────────────────────────────────────

    private function outputJson(array $reports): int
    {
        $minSeverity = $this->option('min-severity');

        $data = [
            'generated_at' => now()->toIso8601String(),
            'total_sites'  => count($reports),
            'summary'      => $this->buildSummaryArray($reports),
            'reports'      => [],
        ];

        foreach ($reports as $report) {
            if (! $this->meetsSeverity($report->severity(), $minSeverity)) {
                continue;
            }
            $data['reports'][] = $report->toArray();
        }

        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $this->hasFailures($reports) ? self::FAILURE : self::SUCCESS;
    }

    // ─── 輸出：Summary 格式 ──────────────────────────────────

    private function outputSummary(array $reports): int
    {
        $this->printSummaryStats($reports);

        // 列出 top 10 最高成本查詢
        $sorted = $reports;
        usort($sorted, fn(CallSiteReport $a, CallSiteReport $b) => $b->estimatedCost <=> $a->estimatedCost);

        $top = array_slice($sorted, 0, 10);

        if (! empty($top)) {
            $this->newLine();
            $this->line('  <fg=white;options=bold>═══ 成本最高的查詢（Top 10）</>');

            $rows = [];
            foreach ($top as $i => $report) {
                $site      = $report->callSite;
                $location  = $site->className && $site->methodName
                    ? "{$site->className}::{$site->methodName}()"
                    : basename($site->filePath) . ":{$site->startLine}";

                $rows[] = [
                    $i + 1,
                    $this->truncate($location, 40),
                    $report->primaryTable ?? '-',
                    $report->estimatedCost,
                    "{$report->complexityScore}/100",
                    strtoupper($report->severity()),
                    count($report->issues),
                ];
            }

            $this->table(
                ['#', '位置', '表', '成本', '複雜度', '嚴重度', '問題數'],
                $rows
            );
        }

        return $this->hasFailures($reports) ? self::FAILURE : self::SUCCESS;
    }

    // ─── 統計摘要 ──────────────────────────────────────────────

    private function printSummaryStats(array $reports): void
    {
        $stats = $this->buildSummaryArray($reports);

        $this->newLine();
        $this->line('  <fg=white;options=bold>═══ 分析摘要</>');
        $this->newLine();
        $this->line("    查詢總數：  <fg=white;options=bold>{$stats['total']}</>");
        $this->line("    <fg=red>●</> Critical：  {$stats['critical']}");
        $this->line("    <fg=yellow>▲</> Warning：   {$stats['warning']}");
        $this->line("    <fg=cyan>ℹ</> Info：      {$stats['info']}");
        $this->line("    <fg=green>✓</> OK：        {$stats['ok']}");
        $this->newLine();
        $this->line("    平均複雜度：{$stats['avg_complexity']}/100");
        $this->line("    平均成本：  {$stats['avg_cost']}");

        if ($stats['critical'] > 0 || $stats['warning'] > 0) {
            $this->newLine();
            $this->warn("  ⚠  發現 {$stats['critical']} 個嚴重問題、{$stats['warning']} 個警告，建議逐一檢視修正。");
        } else {
            $this->newLine();
            $this->info('  ✓ 未發現嚴重問題。');
        }

        $this->newLine();
    }

    /**
     * 建構摘要數據陣列。
     */
    private function buildSummaryArray(array $reports): array
    {
        $total    = count($reports);
        $critical = 0;
        $warning  = 0;
        $info     = 0;
        $ok       = 0;
        $totalComplexity = 0;
        $totalCost       = 0.0;

        foreach ($reports as $report) {
            /** @var CallSiteReport $report */
            match ($report->severity()) {
                'critical' => $critical++,
                'warning'  => $warning++,
                'info'     => $info++,
                default    => $ok++,
            };

            $totalComplexity += $report->complexityScore;
            $totalCost       += $report->estimatedCost;
        }

        return [
            'total'          => $total,
            'critical'       => $critical,
            'warning'        => $warning,
            'info'           => $info,
            'ok'             => $ok,
            'avg_complexity' => $total > 0 ? round($totalComplexity / $total, 1) : 0,
            'avg_cost'       => $total > 0 ? round($totalCost / $total, 2) : 0,
        ];
    }

    // ─── 輔助方法 ──────────────────────────────────────────────

    private function printBanner(): void
    {
        $this->noticeNewLine();
        $this->noticeLine('  <fg=white;options=bold>┌──────────────────────────────────────────┐</>');
        $this->noticeLine('  <fg=white;options=bold>│  SQL Monitor — Static Query Analyser     │</>');
        $this->noticeLine('  <fg=white;options=bold>└──────────────────────────────────────────┘</>');
        $this->noticeNewLine();
    }

    // ─── 裝飾輸出路由（JSON 模式導向 stderr，避免污染 stdout）──────────────

    private function notice(string $message): void
    {
        $this->noticeLine("<info>{$message}</info>");
    }

    private function noticeLine(string $message): void
    {
        if ($this->jsonMode) {
            $this->stderrOutput()->writeln($message);
        } else {
            $this->line($message);
        }
    }

    private function noticeNewLine(): void
    {
        if ($this->jsonMode) {
            $this->stderrOutput()->writeln('');
        } else {
            $this->newLine();
        }
    }

    private function noticeWarn(string $message): void
    {
        $this->noticeLine("<comment>{$message}</comment>");
    }

    private function noticeError(string $message): void
    {
        if ($this->jsonMode) {
            $this->stderrOutput()->writeln("<error>{$message}</error>");
        } else {
            $this->error($message);
        }
    }

    private ?\Symfony\Component\Console\Output\OutputInterface $stderrOutput = null;

    private function stderrOutput(): \Symfony\Component\Console\Output\OutputInterface
    {
        if ($this->stderrOutput === null) {
            $this->stderrOutput = new \Symfony\Component\Console\Output\StreamOutput(
                fopen('php://stderr', 'w'),
                \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL,
                false // 無色彩，避免寫入檔案時混入 ANSI 碼
            );
        }

        return $this->stderrOutput;
    }

    /**
     * 將絕對路徑縮短為相對於專案根目錄的路徑。
     */
    private function shortenPath(string $path): string
    {
        $base = base_path() . DIRECTORY_SEPARATOR;

        if (str_starts_with($path, $base)) {
            return substr($path, strlen($base));
        }

        return $path;
    }

    /**
     * 按檔案路徑分組報告。
     *
     * @param  CallSiteReport[] $reports
     * @return array<string, CallSiteReport[]>
     */
    private function groupByFile(array $reports): array
    {
        $grouped = [];

        foreach ($reports as $report) {
            $file = $report->callSite->filePath;
            $grouped[$file][] = $report;
        }

        return $grouped;
    }

    /**
     * 判斷嚴重度是否達到最低門檻。
     */
    private function meetsSeverity(string $severity, string $minSeverity): bool
    {
        $ranks = ['ok' => 0, 'info' => 1, 'warning' => 2, 'critical' => 3];

        return ($ranks[$severity] ?? 0) >= ($ranks[$minSeverity] ?? 0);
    }

    /**
     * 判斷報告中是否有嚴重問題（用於決定 exit code）。
     */
    private function hasFailures(array $reports): bool
    {
        foreach ($reports as $report) {
            if ($report->hasCritical()) {
                return true;
            }
        }

        return false;
    }

    /**
     * 截斷字串到指定長度。
     */
    private function truncate(string $str, int $max): string
    {
        if (mb_strlen($str) <= $max) {
            return $str;
        }

        return mb_substr($str, 0, $max - 3) . '...';
    }
}
