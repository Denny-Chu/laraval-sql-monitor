<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * 當偵測到慢查詢時觸發。
 */
class SlowQueryDetectedEvent implements ShouldBroadcastNow
{
    public function __construct(
        public readonly string $id,
        public readonly string $sql,
        public readonly float  $executionTimeMs,
        public readonly string $connection,
        public readonly array  $stackTrace = [],
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel(config('sql-monitor.live_monitor.broadcast_channel', 'sql-monitor'));
    }

    public function broadcastAs(): string
    {
        return 'query.slow';
    }
}
