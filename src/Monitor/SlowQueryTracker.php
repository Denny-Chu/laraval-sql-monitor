<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Monitor;

use Illuminate\Support\Facades\Log;
use LaravelSqlMonitor\Lifecycle\QueryRecord;

/**
 * Slow Query 追蹤器 — 記錄超過閾值的查詢（記憶體追蹤 + Log）。
 *
 * 持久化已移至 QueryListener 統一負責，此類只保留：
 *   1. per-request 記憶體追蹤（供 MetricsCollector 讀取當次請求統計）
 *   2. Log::warning()（寫入 Laravel 日誌）
 */
class SlowQueryTracker
{
    /** @var QueryRecord[] */
    protected array $slowQueries = [];

    public function __construct(
        protected float $thresholdMs,
    ) {}

    /**
     * 追蹤一條查詢，若符合慢查詢標準則記錄。
     */
    public function track(QueryRecord $record): bool
    {
        if ($record->executionTimeMs < $this->thresholdMs) {
            return false;
        }

        $this->slowQueries[] = $record;

        // 寫入 Laravel 日誌
        Log::channel(config('logging.default'))
            ->warning('[SQL Monitor] Slow Query', [
                'sql'       => $record->sql,
                'time_ms'   => $record->executionTimeMs,
                'threshold' => $this->thresholdMs,
                'connection'=> $record->connection,
            ]);

        return true;
    }

    /**
     * 取得本次請求的所有慢查詢。
     *
     * @return QueryRecord[]
     */
    public function all(): array
    {
        return $this->slowQueries;
    }

    public function count(): int
    {
        return count($this->slowQueries);
    }

    public function flush(): array
    {
        $queries           = $this->slowQueries;
        $this->slowQueries = [];

        return $queries;
    }
}
