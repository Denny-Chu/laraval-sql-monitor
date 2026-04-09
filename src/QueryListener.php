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
        // 忽略本套件自身對 SQLite 的操作，避免無限遞迴
        if ($event->connectionName === 'sql_monitor') {
            return;
        }

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
    }
}
