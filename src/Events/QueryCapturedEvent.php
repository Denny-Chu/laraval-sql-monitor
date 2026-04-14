<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Events;

/**
 * 當一條查詢被捕獲並分析完成時觸發。
 *
 * 此 Event 為純資料容器，不實作 ShouldBroadcast / ShouldBroadcastNow。
 * 廣播由 LiveQueryMonitor 統一管理，避免每條 query 都觸發同步廣播
 * 而導致 broadcast driver 例外時破壞 QueryListener 的 re-entrancy 防護。
 */
class QueryCapturedEvent
{
    public function __construct(
        public readonly string  $id,
        public readonly string  $sql,
        public readonly float   $executionTimeMs,
        public readonly string  $connection,
        public readonly float   $timestamp,
        public readonly ?array  $complexity = null,
    ) {}
}
