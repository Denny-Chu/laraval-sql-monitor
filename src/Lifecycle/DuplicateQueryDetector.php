<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Lifecycle;

/**
 * 重複查詢偵測器。
 *
 * 與 N+1 不同，重複查詢指的是「完全相同的 SQL + 完全相同的參數」
 * 被執行了多次（通常代表可以快取結果）。
 */
class DuplicateQueryDetector
{
    /**
     * @param  QueryRecord[] $queries
     * @return DuplicateGroup[]
     */
    public function detect(array $queries): array
    {
        $groups     = [];
        $duplicates = [];

        // 以指紋分組
        foreach ($queries as $q) {
            $fp = $q->fingerprint();
            $groups[$fp][] = $q;
        }

        // 只保留出現 2 次以上的
        foreach ($groups as $fp => $group) {
            if (count($group) < 2) {
                continue;
            }

            $duplicates[] = new DuplicateGroup(
                fingerprint:     $fp,
                sql:             $group[0]->sql,
                bindings:        $group[0]->bindings,
                count:           count($group),
                queries:         $group,
                potentialSaving: $this->calculateSaving($group),
            );
        }

        // 依次數降序
        usort($duplicates, fn(DuplicateGroup $a, DuplicateGroup $b) => $b->count <=> $a->count);

        return $duplicates;
    }

    /**
     * 計算若移除重複查詢可節省的時間（毫秒）。
     */
    private function calculateSaving(array $queries): float
    {
        $total = 0.0;

        // 第一次保留，其餘視為浪費
        foreach (array_slice($queries, 1) as $q) {
            $total += $q->executionTimeMs;
        }

        return round($total, 2);
    }
}
