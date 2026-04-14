<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Events;

/**
 * 當偵測到 N+1 查詢模式時觸發。
 *
 * 純資料容器，廣播由 LiveQueryMonitor 統一管理。
 */
class N1PatternDetectedEvent
{
    public function __construct(
        public readonly string $normalizedSql,
        public readonly int    $count,
        public readonly string $suggestion,
    ) {}
}
