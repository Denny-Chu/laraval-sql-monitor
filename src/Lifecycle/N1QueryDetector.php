<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Lifecycle;

/**
 * N+1 查詢偵測器。
 *
 * 原理：
 *   將所有查詢依「正規化 SQL」分組，如果同一正規化 SQL 被執行的次數
 *   超過閾值（預設 2），即判定為潛在 N+1 問題。
 */
class N1QueryDetector
{
    public function __construct(
        protected int $threshold = 2,
    ) {}

    /**
     * 偵測 N+1 查詢模式。
     *
     * @param  QueryRecord[] $queries
     * @return N1Pattern[]
     */
    public function detect(array $queries): array
    {
        $groups   = $this->groupByNormalizedSql($queries);
        $patterns = [];

        foreach ($groups as $normalized => $group) {
            $count = count($group);

            if ($count < $this->threshold) {
                continue;
            }

            $patterns[] = new N1Pattern(
                normalizedSql: $normalized,
                count:         $count,
                queries:       $group,
                severity:      $this->severity($count),
                suggestion:    $this->buildSuggestion($group),
            );
        }

        // 依次數降序排列
        usort($patterns, fn(N1Pattern $a, N1Pattern $b) => $b->count <=> $a->count);

        return $patterns;
    }

    // ─── internal ────────────────────────────────────────────

    /**
     * @return array<string, QueryRecord[]>
     */
    private function groupByNormalizedSql(array $queries): array
    {
        $groups = [];

        foreach ($queries as $q) {
            $groups[$q->normalizedSql][] = $q;
        }

        return $groups;
    }

    private function severity(int $count): string
    {
        return match (true) {
            $count >= 50 => 'critical',
            $count >= 10 => 'warning',
            default      => 'info',
        };
    }

    private function buildSuggestion(array $queries): string
    {
        $sample = $queries[0] ?? null;

        if ($sample === null) {
            return 'Review query pattern to avoid repetitive SQL.';
        }

        // 偵測是否為典型的外鍵查詢（e.g. WHERE user_id = ?）
        if (preg_match('/WHERE\s+`?(\w+)`?\s*=\s*\?/i', $sample->sql, $m)) {
            $fk    = $m[1];
            $table = $sample->analysis?->tables[0] ?? '...';

            return "Use Eager Loading to avoid N+1: "
                 . "Model::with('{$table}')->get() "
                 . "(foreign key: {$fk})";
        }

        return 'Consider using Eloquent with() or load() to batch these queries.';
    }
}
