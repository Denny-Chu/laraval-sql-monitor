<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Lifecycle;

/**
 * 管理單次 HTTP 請求（或 Artisan 命令）中的所有查詢記錄。
 * 作為單例綁定在 Service Container 中。
 */
class RequestQueryManager
{
    /** @var QueryRecord[] */
    protected array $queries = [];

    /** 請求開始時間 */
    protected float $startedAt;

    /** 最大緩衝數量 */
    protected int $maxBuffer;

    public function __construct(int $maxBuffer = 1000)
    {
        $this->startedAt = microtime(true);
        $this->maxBuffer = $maxBuffer;
    }

    /**
     * 新增一條查詢記錄。
     */
    public function add(QueryRecord $record): void
    {
        if (count($this->queries) >= $this->maxBuffer) {
            return; // 超出上限則忽略（避免記憶體耗盡）
        }

        $this->queries[] = $record;
    }

    /**
     * 取得所有已記錄的查詢。
     *
     * @return QueryRecord[]
     */
    public function all(): array
    {
        return $this->queries;
    }

    /**
     * 查詢數量。
     */
    public function count(): int
    {
        return count($this->queries);
    }

    /**
     * 清空緩衝區並回傳所有記錄。
     *
     * @return QueryRecord[]
     */
    public function flush(): array
    {
        $queries       = $this->queries;
        $this->queries = [];

        return $queries;
    }

    /**
     * 取得請求級別的統計摘要。
     */
    public function getStats(): array
    {
        $totalTime       = 0.0;
        $uniqueQueries   = [];
        $slowCount       = 0;
        $thresholdMs     = config('sql-monitor.slow_query.threshold_ms', 100);

        foreach ($this->queries as $q) {
            $totalTime += $q->executionTimeMs;
            $uniqueQueries[$q->normalizedSql] = true;

            if ($q->isSlow($thresholdMs)) {
                $slowCount++;
            }
        }

        return [
            'total_queries'       => $this->count(),
            'unique_queries'      => count($uniqueQueries),
            'total_time_ms'       => round($totalTime, 2),
            'avg_time_ms'         => $this->count() > 0 ? round($totalTime / $this->count(), 2) : 0,
            'slow_query_count'    => $slowCount,
            'elapsed_since_start' => round((microtime(true) - $this->startedAt) * 1000, 2),
        ];
    }

    /**
     * 按正規化 SQL 分組查詢。
     *
     * @return array<string, QueryRecord[]>
     */
    public function groupByNormalizedSql(): array
    {
        $groups = [];

        foreach ($this->queries as $q) {
            $groups[$q->normalizedSql][] = $q;
        }

        return $groups;
    }

    /**
     * 按查詢指紋分組（完全相同的 SQL + 參數）。
     *
     * @return array<string, QueryRecord[]>
     */
    public function groupByFingerprint(): array
    {
        $groups = [];

        foreach ($this->queries as $q) {
            $groups[$q->fingerprint()][] = $q;
        }

        return $groups;
    }
}
