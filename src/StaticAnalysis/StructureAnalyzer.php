<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\StaticAnalysis;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * 分析 Eloquent Query Builder 的查詢結構，
 * 計算複雜度評分、識別潛在風險。
 */
class StructureAnalyzer
{
    public function __construct(
        protected StaticAnalysisResult $analysis,
        protected ?IndexInspector $indexInspector = null,
    ) {}

    /**
     * 計算查詢的整體複雜度分數（0-100）。
     */
    public function calculateComplexityScore(): int
    {
        $score = 0;

        // 1. JOIN 數量（每個 +15）
        $score += min(count($this->analysis->joins) * 15, 50);

        // 2. WHERE 條件（未索引 +20）
        foreach ($this->analysis->wheres as $where) {
            if (! ($where['indexed'] ?? false) && $this->indexInspector) {
                $table = $this->analysis->mainTable;
                if ($table && ! $this->indexInspector->isColumnIndexed($table, $where['column'] ?? '')) {
                    $score += 20;
                }
            }
        }

        // 3. UNION（+15）
        $score += count($this->analysis->unions) * 15;

        // 4. GROUP BY 沒有 INDEX（+10）
        if ($this->analysis->hasGroupBy) {
            $score += 10;
        }

        // 5. SELECT *（+5）
        if ($this->hasSelectStar()) {
            $score += 5;
        }

        // 6. 無 LIMIT（+10）
        if (! $this->analysis->hasLimit) {
            $score += 10;
        }

        return min($score, 100);
    }

    /**
     * 取得查詢的估計選擇率（0-1）。
     * 基於 WHERE 條件和索引情況。
     */
    public function estimateSelectivity(): float
    {
        if (empty($this->analysis->wheres)) {
            return 1.0; // 無 WHERE，選整個表
        }

        // 簡單的乘法規則：每個 WHERE 條件減少 50%
        $selectivity = 1.0;

        foreach ($this->analysis->wheres as $where) {
            $selectivity *= 0.5;

            // 如果有索引，改善 selectivity
            if ($where['indexed'] ?? false) {
                $selectivity *= 0.8;
            }
        }

        return max($selectivity, 0.01);
    }

    /**
     * 估計查詢的執行成本（相對值）。
     */
    public function estimateCost(): float
    {
        $baseCost = 1.0;

        // JOIN 增加成本
        $baseCost *= (1 + count($this->analysis->joins) * 0.5);

        // 低選擇率降低成本（因為返回結果少）
        $baseCost *= $this->estimateSelectivity();

        // 有 LIMIT 降低成本
        if ($this->analysis->hasLimit) {
            $baseCost *= 0.3;
        }

        return round($baseCost, 2);
    }

    /**
     * 取得查詢結構的最佳化建議。
     */
    public function getOptimizationSuggestions(): array
    {
        $suggestions = [];

        // 1. 多於 5 個 JOIN
        if (count($this->analysis->joins) > 5) {
            $suggestions[] = [
                'id'       => 'excessive-joins',
                'title'    => 'Too Many JOINs',
                'message'  => 'Consider breaking this query into multiple queries or using a different approach.',
                'severity' => 'critical',
                'action'   => 'refactor_query',
            ];
        }

        // 2. 未索引的 WHERE 欄位
        foreach ($this->analysis->wheres as $where) {
            if (! ($where['indexed'] ?? false) && $this->indexInspector) {
                $table = $this->analysis->mainTable;
                if ($table && ! $this->indexInspector->isColumnIndexed($table, $where['column'] ?? '')) {
                    $suggestions[] = [
                        'id'       => 'missing-index',
                        'title'    => 'Missing Index',
                        'message'  => "Add an index on '{$where['column']}' for better WHERE clause performance.",
                        'severity' => 'warning',
                        'action'   => 'add_index',
                        'column'   => $where['column'],
                    ];
                }
            }
        }

        // 3. SELECT *
        if ($this->hasSelectStar()) {
            $suggestions[] = [
                'id'       => 'select-star',
                'title'    => 'SELECT * Usage',
                'message'  => 'Specify columns explicitly instead of using SELECT *.',
                'severity' => 'info',
            ];
        }

        // 4. JOIN 條件未索引
        foreach ($this->analysis->joins as $join) {
            // 通常 JOIN 條件應該在兩邊都有索引
            $suggestions[] = [
                'id'       => 'join-optimization',
                'title'    => 'JOIN Optimization',
                'message'  => "Ensure JOIN condition columns are indexed in '{$join['table']}'.",
                'severity' => 'info',
            ];
        }

        // 5. LIMIT 前沒有 ORDER BY
        if ($this->analysis->hasLimit && ! $this->analysis->hasOrderBy) {
            $suggestions[] = [
                'id'       => 'limit-without-order',
                'title'    => 'Non-deterministic LIMIT',
                'message'  => 'Add ORDER BY before LIMIT to ensure consistent results.',
                'severity' => 'info',
            ];
        }

        return $suggestions;
    }

    /**
     * 生成查詢的結構摘要（用於日誌/報告）。
     */
    public function generateSummary(): string
    {
        $parts = [];

        $parts[] = match ($this->analysis->queryBuilderType) {
            'eloquent' => 'Eloquent Query:',
            default    => 'Query:',
        };

        if ($this->analysis->mainTable) {
            $parts[] = "FROM {$this->analysis->mainTable}";
        }

        if (! empty($this->analysis->joins)) {
            $parts[] = "JOIN ({$this->analysis->joinCount()})";
        }

        if (! empty($this->analysis->wheres)) {
            $parts[] = "WHERE ({$this->analysis->whereCount()})";
        }

        if ($this->analysis->hasGroupBy) {
            $parts[] = "GROUP BY";
        }

        if ($this->analysis->hasOrderBy) {
            $parts[] = "ORDER BY";
        }

        if ($this->analysis->hasLimit) {
            $parts[] = "LIMIT";
        }

        $complexity = $this->calculateComplexityScore();
        $severity   = match (true) {
            $complexity >= 70 => 'CRITICAL',
            $complexity >= 40 => 'WARNING',
            default           => 'OK',
        };

        return implode(' → ', $parts) . " [Complexity: {$complexity}/100 - {$severity}]";
    }

    // ─── Helpers ────────────────────────────────────────────

    protected function hasSelectStar(): bool
    {
        return empty($this->analysis->selects)
            || in_array('*', array_column($this->analysis->selects, 'columns', null), true);
    }
}
