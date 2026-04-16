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
use LaravelSqlMonitor\Storage\Contracts\QueryStoreInterface;

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

    private const SEVERITY_ORDER = [
        'low'      => 0,
        'info'     => 1,
        'warning'  => 2,
        'critical' => 3,
    ];

    public function __construct(
        protected QueryAnalyzer         $analyzer,
        protected ComplexityDetector    $complexityDetector,
        protected OptimizationSuggester $suggester,
        protected StackTraceCollector   $traceCollector,
        protected RequestQueryManager   $manager,
        protected SlowQueryTracker      $slowTracker,
        protected LiveQueryMonitor      $liveMonitor,
        protected ?QueryStoreInterface  $store = null,
    ) {}

    /**
     * 暫停事件處理（供 Middleware persist 時使用，避免無限迴圈）。
     */
    public static function pauseHandling(): void
    {
        self::$handling = true;
    }

    /**
     * 恢復事件處理。
     */
    public static function resumeHandling(): void
    {
        self::$handling = false;
    }

    /**
     * 處理 QueryExecuted 事件。
     */
    public function handle(QueryExecuted $event): void
    {
        // ── 無限迴圈防護 ────────────────────────────────────
        if (self::$handling) {
            return;
        }

        // 忽略本套件自身對 SQLite 的操作（保留原有保護）
        if ($event->connectionName === 'sql_monitor') {
            return;
        }

        // ── 連線過濾 ─────────────────────────────────────────
        $excluded = (array) config('sql-monitor.excluded_connections', []);
        if ($excluded !== [] && in_array($event->connectionName, $excluded, true)) {
            return;
        }

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

            // ─── 4. 即時持久化判斷（DB 層）─────────────────────
            $thresholdMs = (float) config('sql-monitor.slow_query.threshold_ms', 100);
            $isSlow      = config('sql-monitor.slow_query.enabled', true)
                           && $record->isSlow($thresholdMs);
            $isComplex   = $this->meetsComplexityThreshold($record);

            if (($isSlow || $isComplex) && $this->store) {
                $this->store->persist($record);
                $record->persisted = true;
            }

            // ─── 5. 慢查詢追蹤 ─────────────────────────────────
            if (config('sql-monitor.slow_query.enabled', true)) {
                $isSlowTracked = $this->slowTracker->track($record);

                if ($isSlowTracked) {
                    try {
                        $this->liveMonitor->broadcastSlowQuery($record);
                    } catch (\Throwable) {
                        // 廣播失敗靜默忽略
                    }
                }
            }

            // ─── 6. 即時廣播 ───────────────────────────────────
            if (config('sql-monitor.live_monitor.enabled', true)) {
                try {
                    $this->liveMonitor->broadcastQuery($record);
                } catch (\Throwable) {
                    // 廣播失敗不中斷監控
                }
            }
        } finally {
            self::$handling = false;
        }
    }

    /**
     * 判斷查詢的複雜度是否達到持久化閾值。
     */
    private function meetsComplexityThreshold(QueryRecord $record): bool
    {
        if (! $record->complexity) {
            return false;
        }

        $threshold = config('sql-monitor.complexity.persist_severity', 'warning');
        $actual    = $record->complexity->severity;

        return (self::SEVERITY_ORDER[$actual] ?? 0) >= (self::SEVERITY_ORDER[$threshold] ?? 2);
    }
}
