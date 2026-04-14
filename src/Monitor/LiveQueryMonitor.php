<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Monitor;

use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use LaravelSqlMonitor\Events\QueryCapturedEvent;
use LaravelSqlMonitor\Events\SlowQueryDetectedEvent;
use LaravelSqlMonitor\Events\N1PatternDetectedEvent;
use LaravelSqlMonitor\Lifecycle\QueryRecord;

/**
 * Live Query Monitor — 透過 Broadcast Facade 即時廣播查詢資訊。
 *
 * Events 本身為純資料容器（不實作 ShouldBroadcastNow），
 * 廣播統一由此類管理，確保：
 *  1. 廣播失敗不影響 QueryListener 的 re-entrancy 防護。
 *  2. 廣播只在 live_monitor.enabled = true 且 driver 可用時執行。
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

        // 先 dispatch Laravel Event（供應用程式自行監聽）
        Event::dispatch(new QueryCapturedEvent(
            id:              $record->id,
            sql:             $record->sql,
            executionTimeMs: $record->executionTimeMs,
            connection:      $record->connection,
            timestamp:       $record->timestamp,
            complexity:      $record->complexity?->toArray(),
        ));

        // 再透過 Broadcast Facade 推送至 WebSocket
        $this->broadcastRaw('query.captured', [
            'id'               => $record->id,
            'sql'              => $record->sql,
            'execution_time_ms'=> $record->executionTimeMs,
            'connection'       => $record->connection,
            'timestamp'        => $record->timestamp,
            'complexity'       => $record->complexity?->toArray(),
        ]);
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

        $this->broadcastRaw('query.slow', [
            'id'                => $record->id,
            'sql'               => $record->sql,
            'execution_time_ms' => $record->executionTimeMs,
            'connection'        => $record->connection,
            'stack_trace'       => $record->stackTrace,
        ]);
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

        $this->broadcastRaw('query.n1_detected', [
            'normalized_sql' => $normalizedSql,
            'count'          => $count,
            'suggestion'     => $suggestion,
        ]);
    }

    public function pause(): void
    {
        $this->broadcasting = false;
    }

    public function resume(): void
    {
        $this->broadcasting = true;
    }

    // ─── internal ────────────────────────────────────────────

    /**
     * 透過 Broadcast Facade 直接推送原始資料至指定頻道。
     *
     * 使用 Broadcast::on() 而非 ShouldBroadcastNow Event，
     * 讓廣播完全解耦於 QueryExecuted 事件監聽流程。
     */
    protected function broadcastRaw(string $event, array $data): void
    {
        Broadcast::on(new Channel($this->channel))
            ->as($event)
            ->with($data)
            ->sendNow();
    }
}
