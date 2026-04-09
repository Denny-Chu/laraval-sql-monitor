<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Lifecycle;

/**
 * 偵測到的 N+1 查詢模式。
 */
class N1Pattern
{
    public function __construct(
        public readonly string $normalizedSql,
        public readonly int    $count,
        /** @var QueryRecord[] */
        public readonly array  $queries,
        public readonly string $severity,     // info | warning | critical
        public readonly string $suggestion,
    ) {}

    /**
     * 被重複查詢浪費的估計時間（毫秒）。
     */
    public function wastedTimeMs(): float
    {
        $total = 0.0;

        // 第一次執行視為合理，其餘為浪費
        foreach (array_slice($this->queries, 1) as $q) {
            $total += $q->executionTimeMs;
        }

        return round($total, 2);
    }

    public function toArray(): array
    {
        return [
            'normalized_sql' => $this->normalizedSql,
            'count'          => $this->count,
            'severity'       => $this->severity,
            'suggestion'     => $this->suggestion,
            'wasted_time_ms' => $this->wastedTimeMs(),
            'sample_sql'     => $this->queries[0]?->sql ?? '',
        ];
    }
}
