<?php

declare(strict_types=1);

namespace LaravelSqlMonitor;

use Illuminate\Database\Events\QueryExecuted;
use LaravelSqlMonitor\Core\QueryAnalyzer;
use LaravelSqlMonitor\Core\ComplexityDetector;
use LaravelSqlMonitor\Core\OptimizationSuggester;
use LaravelSqlMonitor\Core\StackTraceCollector;
use LaravelSqlMonitor\Lifecycle\QueryRecord;
use LaravelSqlMonitor\Lifecycle\RequestQueryManager;
use LaravelSqlMonitor\Monitor\SlowQueryTracker;
use LaravelSqlMonitor\Monitor\LiveQueryMonitor;

/**
 * 核心事件監聽器 — 接收 QueryExecuted 並觸發整個分析管線。
 */
class QueryListener
{
    /**
     * 防止 storage 寫入觸發新事件時造成無限遞迴。
     * 當 storage.driver = database 且連線為 MySQL 時尤為重要。
     */
    private static bool $handling = false;

    public function __construct(
        protected QueryAnalyzer         $analyzer,
        protected ComplexityDetector    $complexityDetector,
        protected OptimizationSuggester $suggester,
        protected StackTraceCollector   $traceCollector,
        protected RequestQueryManager   $manager,
        protected SlowQueryTracker      $slowTracker,
        protected LiveQueryMonitor      $liveMonitor,
    ) {}

    /**
     * 處理 QueryExecuted 事件。
     */
    public function handle(QueryExecuted $event): void
    {
        // ── 無限迴圈防護 ────────────────────────────────────
        // 當 storage.driver = database 時，persist() 本身也會觸發
        // QueryExecuted 事件（同一條 MySQL 連線）。
        // 使用 static flag 確保不會重新進入，無論 storage 使用哪條連線。
        if (self::$handling) {
            return;
        }

        // 忽略本套件自身對 SQLite 的操作（保留原有保護）
        if ($event->connectionName === 'sql_monitor') {
            return;
        }

        // ── 連線過濾 ─────────────────────────────────────────
        // excluded_connections：永遠不監控（IndexInspector / storage 專用連線）
        $excluded = (array) config('sql-monitor.excluded_connections', []);
        if ($excluded !== [] && in_array($event->connectionName, $excluded, true)) {
            return;
        }

        // connections：白名單，空陣列 = 監控所有連線
        $monitored = (array) config('sql-monitor.connections', []);
        if ($monitored !== [] && ! in_array($event->connectionName, $monitored, true)) {
            return;
        }

        self::$handling = true;

        try {
            // ─── 1. 建立查詢記錄 ────────────────────────────────
            $stackTrace = config('sql-monitor.stack_trace.enabled', true)
                ? $this->traceCollector->collect()
                : [];

            $record = QueryRecord::fromEvent(
                sql:        $event->sql,
                bindings:   $event->bindings,
                time:       $event->time,
                connection: $event->connectionName,
                stackTrace: $stackTrace,
            );

            // ─── 2. SQL 結構分析 ────────────────────────────────
            if (config('sql-monitor.complexity.enabled', true)) {
                $record->analysis   = $this->analyzer->analyze($event->sql, $event->bindings);
                $record->complexity = $this->complexityDetector->detect($record->analysis);
                $record->suggestions = $this->suggester->suggest($record->analysis, $record->complexity);
            }

            // ─── 3. 加入請求緩衝區 ─────────────────────────────
            $this->manager->add($record);

            // ─── 4. 慢查詢追蹤 ─────────────────────────────────
            if (config('sql-monitor.slow_query.enabled', true)) {
                $isSlow = $this->slowTracker->track($record);

                if ($isSlow) {
                    $this->liveMonitor->broadcastSlowQuery($record);
                }
            }

            // ─── 5. 即時廣播 ───────────────────────────────────
            if (config('sql-monitor.live_monitor.enabled', true)) {
                $this->liveMonitor->broadcastQuery($record);
            }
        } finally {
            self::$handling = false;
        }
    }
}
