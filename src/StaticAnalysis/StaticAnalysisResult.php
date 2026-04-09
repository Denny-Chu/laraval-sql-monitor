<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\StaticAnalysis;

/**
 * 靜態分析的結果。
 */
class StaticAnalysisResult
{
    public function __construct(
        public readonly string $queryBuilderType,  // eloquent | query
        public readonly ?string $mainTable,
        public readonly array $joins,              // [{type, table, condition, probability}]
        public readonly array $wheres,             // [{type, column, operator, value, indexed}]
        public readonly array $selects,            // [{type, column?, table?}]
        public readonly array $unions,             // [{type, query}]
        public readonly bool $hasOrderBy,
        public readonly bool $hasGroupBy,
        public readonly bool $hasLimit,
        public readonly bool $hasOffset,
        public readonly array $methodChain,        // 方法鏈調用序列
        public readonly array $issues,             // [{id, severity, message}]
    ) {}

    public function hasCriticalIssues(): bool
    {
        return ! empty(array_filter(
            $this->issues,
            fn($i) => $i['severity'] === 'critical'
        ));
    }

    public function getCriticalIssues(): array
    {
        return array_filter($this->issues, fn($i) => $i['severity'] === 'critical');
    }

    public function getWarnings(): array
    {
        return array_filter($this->issues, fn($i) => $i['severity'] === 'warning');
    }

    public function getInfos(): array
    {
        return array_filter($this->issues, fn($i) => $i['severity'] === 'info');
    }

    public function toArray(): array
    {
        return [
            'builder_type'  => $this->queryBuilderType,
            'main_table'    => $this->mainTable,
            'joins'         => $this->joins,
            'wheres'        => $this->wheres,
            'selects'       => $this->selects,
            'unions'        => $this->unions,
            'has_order_by'  => $this->hasOrderBy,
            'has_group_by'  => $this->hasGroupBy,
            'has_limit'     => $this->hasLimit,
            'has_offset'    => $this->hasOffset,
            'method_chain'  => $this->methodChain,
            'issues'        => $this->issues,
        ];
    }
}
