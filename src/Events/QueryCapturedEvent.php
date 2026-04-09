<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * 當一條查詢被捕獲並分析完成時觸發。
 */
class QueryCapturedEvent implements ShouldBroadcastNow
{
    public function __construct(
        public readonly string  $id,
        public readonly string  $sql,
        public readonly float   $executionTimeMs,
        public readonly string  $connection,
        public readonly float   $timestamp,
        public readonly ?array  $complexity = null,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel(config('sql-monitor.live_monitor.broadcast_channel', 'sql-monitor'));
    }

    public function broadcastAs(): string
    {
        return 'query.captured';
    }
}
