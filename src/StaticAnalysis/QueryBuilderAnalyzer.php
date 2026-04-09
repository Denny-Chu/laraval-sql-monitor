<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\StaticAnalysis;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use ReflectionClass;
use ReflectionProperty;

/**
 * 靜態分析 Eloquent Query Builder 或 Query Builder 實例。
 * 在查詢執行前就分析其結構，不需執行 SQL。
 *
 * 用法：
 *   $analyzer = new QueryBuilderAnalyzer($query);
 *   $analysis = $analyzer->analyze();
 */
class QueryBuilderAnalyzer
{
    protected Builder|EloquentBuilder $builder;
    protected array $joins = [];
    protected array $wheres = [];
    protected array $selects = [];
    protected array $tables = [];
    protected ?string $mainTable = null;
    protected array $methodChain = [];

    public function __construct(Builder|EloquentBuilder $builder)
    {
        $this->builder = $builder;
        $this->extractMetadata();
    }

    /**
     * 執行完整的靜態分析。
     */
    public function analyze(): StaticAnalysisResult
    {
        return new StaticAnalysisResult(
            queryBuilderType: $this->getBuilderType(),
            mainTable:       $this->mainTable,
            joins:           $this->analyzeJoins(),
            wheres:          $this->analyzeWheres(),
            selects:         $this->analyzeSelects(),
            unions:          $this->analyzeUnions(),
            hasOrderBy:      $this->hasOrderBy(),
            hasGroupBy:      $this->hasGroupBy(),
            hasLimit:        $this->hasLimit(),
            hasOffset:       $this->hasOffset(),
            methodChain:     $this->methodChain,
            issues:          $this->detectIssues(),
        );
    }

    // ─── 元數據抽取 ────────────────────────────────────────

    protected function extractMetadata(): void
    {
        $reflection = new ReflectionClass($this->builder);

        // 取得主表
        $this->mainTable = $this->getPropertyValue('from') ?? $this->getPropertyValue('table');

        // 取得 SELECT 子句
        $columns = $this->getPropertyValue('columns');
        $this->selects = is_array($columns) ? $columns : [];

        // 取得 JOIN 子句
        $joins = $this->getPropertyValue('joins') ?? [];
        foreach ($joins as $join) {
            $this->joins[] = [
                'type'  => $join->type ?? 'INNER JOIN',
                'table' => $join->table ?? 'unknown',
                'on'    => $join->wheres ?? [],
            ];
        }

        // 取得 WHERE 子句
        $wheres = $this->getPropertyValue('wheres') ?? [];
        $this->wheres = $wheres;

        // 記錄方法鏈（用於追蹤）
        $this->methodChain = $this->extractMethodChain();
    }

    protected function getPropertyValue(string $property)
    {
        try {
            $reflection = new ReflectionProperty($this->builder, $property);
            $reflection->setAccessible(true);

            return $reflection->getValue($this->builder);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function extractMethodChain(): array
    {
        // 透過 debug_backtrace 追蹤呼叫鏈
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30);
        $chain = [];

        foreach ($trace as $frame) {
            if (isset($frame['class']) && str_contains($frame['class'], 'Database')) {
                $chain[] = $frame['function'];
            }
        }

        return array_unique(array_reverse(array_slice($chain, 0, 10)));
    }

    // ─── 結構分析 ──────────────────────────────────────────

    protected function analyzeJoins(): array
    {
        return array_map(function (array $join) {
            return [
                'type'        => $join['type'] ?? 'INNER JOIN',
                'table'       => $join['table'] ?? 'unknown',
                'condition'   => $this->stringifyWheres($join['on'] ?? []),
                'probability' => $this->estimateJoinSelectivity($join),
            ];
        }, $this->joins);
    }

    protected function analyzeWheres(): array
    {
        return array_map(function ($where) {
            $type    = $where->type ?? 'basic';
            $column  = $where->column ?? 'unknown';
            $operator = $where->operator ?? '=';
            $value   = $where->value ?? null;

            return [
                'type'     => $type,
                'column'   => $column,
                'operator' => $operator,
                'value'    => is_scalar($value) ? $value : 'object',
                'indexed'  => $this->isColumnIndexed($column),
            ];
        }, $this->wheres);
    }

    protected function analyzeSelects(): array
    {
        if (empty($this->selects)) {
            return [['type' => 'star', 'columns' => '*']];
        }

        return array_map(function ($col) {
            if ($col === '*') {
                return ['type' => 'star', 'columns' => '*'];
            }

            return [
                'type'   => 'specific',
                'column' => $col,
                'table'  => $this->extractTable($col),
            ];
        }, $this->selects);
    }

    protected function analyzeUnions(): array
    {
        $unions = $this->getPropertyValue('unions') ?? [];

        return array_map(function ($union) {
            return [
                'type'  => $union->type ?? 'UNION',
                'query' => isset($union->query) ? 'nested' : 'unknown',
            ];
        }, $unions);
    }

    protected function hasOrderBy(): bool
    {
        return ! empty($this->getPropertyValue('orders'));
    }

    protected function hasGroupBy(): bool
    {
        return ! empty($this->getPropertyValue('groups'));
    }

    protected function hasLimit(): bool
    {
        return $this->getPropertyValue('limit') !== null;
    }

    protected function hasOffset(): bool
    {
        return $this->getPropertyValue('offset') !== null;
    }

    // ─── 靜態檢測 ──────────────────────────────────────────

    protected function detectIssues(): array
    {
        $issues = [];

        // 1. SELECT * 警告
        if ($this->hasSelectStar()) {
            $issues[] = [
                'id'       => 'select-star',
                'severity' => 'warning',
                'message'  => 'SELECT * detected: specify columns explicitly for better performance.',
            ];
        }

        // 2. WHERE 子句缺少索引
        foreach ($this->wheres as $where) {
            if (! $this->isColumnIndexed($where->column ?? null)) {
                $issues[] = [
                    'id'       => 'no-index',
                    'severity' => 'info',
                    'message'  => "WHERE clause on '{$where->column}': consider adding an index.",
                    'column'   => $where->column,
                ];
            }
        }

        // 3. JOIN 條件未索引
        foreach ($this->joins as $join) {
            foreach ($join['on'] as $condition) {
                $column = $condition->column ?? null;
                if ($column && ! $this->isColumnIndexed($column)) {
                    $issues[] = [
                        'id'       => 'join-no-index',
                        'severity' => 'warning',
                        'message'  => "JOIN condition '{$column}' not indexed: may cause full table scan.",
                    ];
                }
            }
        }

        // 4. 沒有 ORDER BY 的無限結果集
        if (! $this->hasLimit() && ! $this->hasOrderBy() && empty($this->wheres)) {
            $issues[] = [
                'id'       => 'unbounded-resultset',
                'severity' => 'critical',
                'message'  => 'Query without LIMIT or WHERE: may return entire table.',
            ];
        }

        // 5. LIMIT 前沒有 ORDER BY
        if ($this->hasLimit() && ! $this->hasOrderBy()) {
            $issues[] = [
                'id'       => 'limit-without-order',
                'severity' => 'info',
                'message'  => 'LIMIT without ORDER BY: results order is non-deterministic.',
            ];
        }

        // 6. GROUP BY 與 non-aggregated 欄位
        if ($this->hasGroupBy()) {
            // 檢查 SELECT 中的欄位是否都在 GROUP BY 中
            $issues[] = [
                'id'       => 'group-by-check',
                'severity' => 'info',
                'message'  => 'GROUP BY detected: ensure all non-aggregated columns are in GROUP BY.',
            ];
        }

        // 7. 過多 JOIN（超過 5 個）
        if (count($this->joins) > 5) {
            $issues[] = [
                'id'       => 'excessive-joins',
                'severity' => 'critical',
                'message'  => count($this->joins) . ' JOINs detected: consider breaking into multiple queries.',
            ];
        }

        return $issues;
    }

    // ─── Helpers ────────────────────────────────────────────

    protected function getBuilderType(): string
    {
        return $this->builder instanceof EloquentBuilder ? 'eloquent' : 'query';
    }

    protected function hasSelectStar(): bool
    {
        return empty($this->selects) || in_array('*', $this->selects, true);
    }

    protected function isColumnIndexed(string|null $column): bool
    {
        if ($column === null || $column === '*') {
            return false;
        }

        // TODO: 整合 IndexInspector 檢查實際的資料庫索引
        // 暫時回傳 false，表示未知
        return false;
    }

    protected function extractTable(string $column): ?string
    {
        if (str_contains($column, '.')) {
            return explode('.', $column)[0];
        }

        return $this->mainTable;
    }

    protected function stringifyWheres(array $wheres): string
    {
        $parts = [];

        foreach ($wheres as $where) {
            if (isset($where->column, $where->operator)) {
                $parts[] = "{$where->column} {$where->operator} ?";
            }
        }

        return implode(' AND ', $parts);
    }

    protected function estimateJoinSelectivity(array $join): float
    {
        // 簡單的啟發式方法估計 JOIN 的選擇率
        // 實際應用中應該查詢 EXPLAIN 或統計資訊

        $conditions = count($join['on'] ?? []);

        return match (true) {
            $conditions === 0 => 1.0,  // CROSS JOIN
            $conditions === 1 => 0.05, // 單一條件，假設 5% 的資料匹配
            default           => 0.01, // 多條件，更低的匹配率
        };
    }
}
