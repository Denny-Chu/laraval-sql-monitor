<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Core;

/**
 * 代表一條 SQL 查詢的完整分析結果。
 * 這是各分析模組之間傳遞的統一資料結構。
 */
class QueryAnalysis
{
    public function __construct(
        /** 原始 SQL 語句 */
        public readonly string $sql,

        /** 綁定參數 */
        public readonly array $bindings,

        /** 查詢類型：select, insert, update, delete, other */
        public readonly string $queryType,

        /** SELECT 子句中提取的欄位列表 */
        public readonly array $selectColumns,

        /** 是否使用 SELECT * */
        public readonly bool $hasSelectStar,

        /** JOIN 資訊陣列 [{type, table, on}] */
        public readonly array $joins,

        /** 子查詢資訊 [{sql, depth}] */
        public readonly array $subqueries,

        /** FROM 子句中的主要表名 */
        public readonly array $tables,

        /** WHERE 條件資訊 */
        public readonly array $conditions,

        /** 是否含 GROUP BY */
        public readonly bool $hasGroupBy,

        /** 是否含 HAVING */
        public readonly bool $hasHaving,

        /** 是否含 ORDER BY */
        public readonly bool $hasOrderBy,

        /** 是否含 LIMIT */
        public readonly bool $hasLimit,

        /** 是否含 UNION */
        public readonly bool $hasUnion,

        /** 解析過程中的錯誤訊息（null = 成功） */
        public readonly ?string $error = null,
    ) {}

    /**
     * 建立解析失敗的結果。
     */
    public static function failed(string $sql, array $bindings, string $error): self
    {
        return new self(
            sql: $sql,
            bindings: $bindings,
            queryType: 'unknown',
            selectColumns: [],
            hasSelectStar: false,
            joins: [],
            subqueries: [],
            tables: [],
            conditions: [],
            hasGroupBy: false,
            hasHaving: false,
            hasOrderBy: false,
            hasLimit: false,
            hasUnion: false,
            error: $error,
        );
    }

    public function isSuccessful(): bool
    {
        return $this->error === null;
    }

    public function joinCount(): int
    {
        return count($this->joins);
    }

    public function subqueryMaxDepth(): int
    {
        if (empty($this->subqueries)) {
            return 0;
        }

        return max(array_column($this->subqueries, 'depth'));
    }

    public function toArray(): array
    {
        return [
            'sql'              => $this->sql,
            'bindings'         => $this->bindings,
            'query_type'       => $this->queryType,
            'select_columns'   => $this->selectColumns,
            'has_select_star'  => $this->hasSelectStar,
            'joins'            => $this->joins,
            'subqueries'       => $this->subqueries,
            'tables'           => $this->tables,
            'conditions'       => $this->conditions,
            'has_group_by'     => $this->hasGroupBy,
            'has_having'       => $this->hasHaving,
            'has_order_by'     => $this->hasOrderBy,
            'has_limit'        => $this->hasLimit,
            'has_union'        => $this->hasUnion,
            'error'            => $this->error,
        ];
    }
}
