<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Lifecycle;

/**
 * 一組完全重複的查詢。
 */
class DuplicateGroup
{
    public function __construct(
        public readonly string $fingerprint,
        public readonly string $sql,
        public readonly array  $bindings,
        public readonly int    $count,
        /** @var QueryRecord[] */
        public readonly array  $queries,
        /** 節省毫秒數（移除重複後） */
        public readonly float  $potentialSaving,
    ) {}

    public function toArray(): array
    {
        return [
            'sql'               => $this->sql,
            'bindings'          => $this->bindings,
            'count'             => $this->count,
            'potential_saving'  => $this->potentialSaving,
        ];
    }
}
