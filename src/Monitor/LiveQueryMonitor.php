<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Monitor;

use Illuminate\Support\Facades\Event;
use LaravelSqlMonitor\Events\QueryCapturedEvent;
use LaravelSqlMonitor\Events\SlowQueryDetectedEvent;
use LaravelSqlMonitor\Events\N1PatternDetectedEvent;
use LaravelSqlMonitor\Lifecycle\QueryRecord;

/**
 * Live Query Monitor — 透過事件系統即時廣播查詢資訊。
 *
 * 前端可透過 Laravel Echo 監聽 WebSocket 頻道接收即時更新。
 */
class LiveQueryMonitor
{
    protected bool $broadcasting = true;

    public function __construct(
        protected string $channel = 'sql-monitor',
    ) {}

    /**
     * 廣播一條查詢被捕獲的事件。
     */
    public function broadcastQuery(QueryRecord $record): void
    {
        if (! $this->broadcasting) {
            return;
        }

        Event::dispatch(new QueryCapturedEvent(
            id:              $record->id,
            sql:             $record->sql,
            executionTimeMs: $record->executionTimeMs,
            connection:      $record->connection,
            timestamp:       $record->timestamp,
            complexity:      $record->complexity?->toArray(),
        ));
    }

    /**
     * 廣播慢查詢被偵測的事件。
     */
    public function broadcastSlowQuery(QueryRecord $record): void
    {
        if (! $this->broadcasting) {
            return;
        }

        Event::dispatch(new SlowQueryDetectedEvent(
            id:              $record->id,
            sql:             $record->sql,
            executionTimeMs: $record->executionTimeMs,
            connection:      $record->connection,
            stackTrace:      $record->stackTrace,
        ));
    }

    /**
     * 廣播 N+1 模式被偵測的事件。
     */
    public function broadcastN1Pattern(string $normalizedSql, int $count, string $suggestion): void
    {
        if (! $this->broadcasting) {
            return;
        }

        Event::dispatch(new N1PatternDetectedEvent(
            normalizedSql: $normalizedSql,
            count:         $count,
            suggestion:    $suggestion,
        ));
    }

    public function pause(): void
    {
        $this->broadcasting = false;
    }

    public function resume(): void
    {
        $this->broadcasting = true;
    }
}
