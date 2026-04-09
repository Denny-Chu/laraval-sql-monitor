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

/**
 * 在每次 HTTP 請求結束後收集統計資訊，
 * 並將摘要附加到 Response Header 中。
 */
class QueryMonitoringMiddleware
{
    public function __construct(
        protected RequestQueryManager    $manager,
        protected N1QueryDetector        $n1Detector,
        protected DuplicateQueryDetector $dupDetector,
        protected LiveQueryMonitor       $liveMonitor,
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

        // ─── N+1 偵測 ──────────────────────────────────────
        if (config('sql-monitor.n1_detection.enabled', true)) {
            $n1Patterns = $this->n1Detector->detect($this->manager->all());

            if (! empty($n1Patterns)) {
                $response->headers->set('X-Sql-Monitor-N1-Count', (string) count($n1Patterns));

                // 廣播 N+1 警告
                foreach ($n1Patterns as $pattern) {
                    $this->liveMonitor->broadcastN1Pattern(
                        $pattern->normalizedSql,
                        $pattern->count,
                        $pattern->suggestion,
                    );
                }
            }
        }

        // ─── 重複查詢偵測 ──────────────────────────────────
        if (config('sql-monitor.duplicate_detection.enabled', true)) {
            $duplicates = $this->dupDetector->detect($this->manager->all());

            if (! empty($duplicates)) {
                $response->headers->set('X-Sql-Monitor-Duplicate-Count', (string) count($duplicates));
            }
        }

        return $response;
    }
}
