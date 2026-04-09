<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Monitor;

use Illuminate\Support\Facades\Log;
use LaravelSqlMonitor\Lifecycle\QueryRecord;
use LaravelSqlMonitor\Storage\Contracts\QueryStoreInterface;

/**
 * Slow Query 追蹤器 — 記錄超過閾值的查詢。
 */
class SlowQueryTracker
{
    /** @var QueryRecord[] */
    protected array $slowQueries = [];

    public function __construct(
        protected float               $thresholdMs,
        protected ?QueryStoreInterface $store = null,
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

        // 持久化到儲存層
        $this->store?->persist($record);

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
