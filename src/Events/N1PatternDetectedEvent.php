<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * 當偵測到 N+1 查詢模式時觸發。
 */
class N1PatternDetectedEvent implements ShouldBroadcastNow
{
    public function __construct(
        public readonly string $normalizedSql,
        public readonly int    $count,
        public readonly string $suggestion,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel(config('sql-monitor.live_monitor.broadcast_channel', 'sql-monitor'));
    }

    public function broadcastAs(): string
    {
        return 'query.n1_detected';
    }
}
