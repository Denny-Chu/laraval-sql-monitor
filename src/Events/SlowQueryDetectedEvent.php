<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Events;

/**
 * 當偵測到慢查詢時觸發。
 *
 * 純資料容器，廣播由 LiveQueryMonitor 統一管理。
 */
class SlowQueryDetectedEvent
{
    public function __construct(
        public readonly string $id,
        public readonly string $sql,
        public readonly float  $executionTimeMs,
        public readonly string $connection,
        public readonly array  $stackTrace = [],
    ) {}
}
