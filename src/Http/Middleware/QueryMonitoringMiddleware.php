<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use LaravelSqlMonitor\Lifecycle\RequestQueryManager;
use LaravelSqlMonitor\Lifecycle\N1QueryDetector;
use LaravelSqlMonitor\Lifecycle\DuplicateQueryDetector;
use LaravelSqlMonitor\Monitor\LiveQueryMonitor;
use LaravelSqlMonitor\QueryListener;
use LaravelSqlMonitor\Storage\Contracts\QueryStoreInterface;
use LaravelSqlMonitor\Storage\MemoryQueryStore;

/**
 * 在每次 HTTP 請求結束後：
 *   1. 附加統計摘要到 Response Header
 *   2. N+1 / Duplicate 偵測 → 持久化代表查詢到 DB
 *   3. 剩餘正常查詢 → 寫入 Cache 層（MemoryQueryStore）
 */
class QueryMonitoringMiddleware
{
    public function __construct(
        protected RequestQueryManager    $manager,
        protected N1QueryDetector        $n1Detector,
        protected DuplicateQueryDetector $dupDetector,
        protected LiveQueryMonitor       $liveMonitor,
        protected ?QueryStoreInterface   $store = null,
        protected ?MemoryQueryStore      $memoryStore = null,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! config('sql-monitor.enabled', true)) {
            return $response;
        }

        // ─── 統計摘要 ───────────────────────────────────────
        $stats = $this->manager->getStats();

        $response->headers->set('X-Sql-Monitor-Query-Count', (string) $stats['total_queries']);
        $response->headers->set('X-Sql-Monitor-Total-Time',  (string) $stats['total_time_ms']);
        $response->headers->set('X-Sql-Monitor-Slow-Count',  (string) $stats['slow_query_count']);

        $allQueries = $this->manager->all();

        // ── 暫停 QueryListener，避免以下 persist 觸發無限迴圈 ──
        QueryListener::pauseHandling();

        try {
            // ─── N+1 偵測 + 持久化 ────────────────────────────
            if (config('sql-monitor.n1_detection.enabled', true)) {
                $n1Patterns = $this->n1Detector->detect($allQueries);

                if (! empty($n1Patterns)) {
                    $response->headers->set('X-Sql-Monitor-N1-Count', (string) count($n1Patterns));

                    foreach ($n1Patterns as $pattern) {
                        // 廣播 N+1 警告
                        try {
                            $this->liveMonitor->broadcastN1Pattern(
                                $pattern->normalizedSql,
                                $pattern->count,
                                $pattern->suggestion,
                            );
                        } catch (\Throwable) {
                            // 廣播失敗不中斷
                        }

                        // 持久化代表查詢
                        if ($this->store && ! empty($pattern->queries)) {
                            $representative = $pattern->queries[0];
                            $representative->isN1 = true;
                            $representative->n1Count = $pattern->count;
                            $representative->n1Suggestion = $pattern->suggestion;

                            $this->store->persist($representative);
                            $representative->persisted = true;
                        }
                    }
                }
            }

            // ─── 重複查詢偵測 + 持久化 ────────────────────────
            if (config('sql-monitor.duplicate_detection.enabled', true)) {
                $duplicates = $this->dupDetector->detect($allQueries);

                if (! empty($duplicates)) {
                    $response->headers->set('X-Sql-Monitor-Duplicate-Count', (string) count($duplicates));

                    foreach ($duplicates as $group) {
                        if ($this->store && ! empty($group->queries)) {
                            $representative = $group->queries[0];
                            $representative->isDuplicate = true;
                            $representative->duplicateCount = $group->count;

                            $this->store->persist($representative);
                            $representative->persisted = true;
                        }
                    }
                }
            }
        } finally {
            QueryListener::resumeHandling();
        }

        // ─── Cache 層：剩餘正常查詢 ───────────────────────────
        if ($this->memoryStore && config('sql-monitor.memory.enabled', true)) {
            $remaining = array_filter($allQueries, fn($q) => ! $q->persisted);
            if (! empty($remaining)) {
                $this->memoryStore->pushBatch(array_values($remaining));
            }
        }

        return $response;
    }
}
