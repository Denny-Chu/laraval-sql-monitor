<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Core;

/**
 * 根據 QueryAnalysis 與 ComplexityResult 產生可操作的優化建議。
 */
class OptimizationSuggester
{
    /**
     * 產生優化建議清單。
     *
     * @return Suggestion[]
     */
    public function suggest(QueryAnalysis $analysis, ComplexityResult $complexity): array
    {
        if (! $analysis->isSuccessful()) {
            return [];
        }

        $suggestions = [];

        // ──── 1. SELECT * → 指定欄位 ────────────────────────
        if ($analysis->hasSelectStar) {
            $tables = implode(', ', $analysis->tables) ?: '...';
            $suggestions[] = new Suggestion(
                id:       'select-star',
                title:    '避免 SELECT *',
                message:  '請明確指定所需欄位以減少資料傳輸與記憶體佔用。',
                severity: 'warning',
                example:  "SELECT id, name, email FROM {$tables}",
                docUrl:   'https://dev.mysql.com/doc/refman/8.0/en/select-optimization.html',
            );
        }

        // ──── 2. JOIN 過多 ──────────────────────────────────
        if ($analysis->joinCount() > 5) {
            $suggestions[] = new Suggestion(
                id:       'excessive-joins',
                title:    '簡化多表 JOIN',
                message:  '考慮拆分查詢、使用暫存表、或建立資料庫視圖(View)來降低複雜度。',
                severity: 'critical',
            );
        }

        // ──── 3. 子查詢 → CTE 或 JOIN ──────────────────────
        if ($analysis->subqueryMaxDepth() > 2) {
            $suggestions[] = new Suggestion(
                id:       'subquery-to-cte',
                title:    '使用 CTE 取代深層子查詢',
                message:  'MySQL 8.0+ 支援 WITH (CTE) 語法，可提升可讀性與效能。',
                severity: 'warning',
                example:  "WITH cte AS (SELECT ...) SELECT * FROM cte WHERE ...",
            );
        }

        // ──── 4. 缺少 WHERE（UPDATE / DELETE） ─────────────
        if (in_array($analysis->queryType, ['update', 'delete'], true) && empty($analysis->conditions)) {
            $suggestions[] = new Suggestion(
                id:       'missing-where',
                title:    '危險：缺少 WHERE 條件',
                message:  '此 ' . strtoupper($analysis->queryType) . ' 將影響所有資料列，請確認是否有意為之。',
                severity: 'critical',
            );
        }

        // ──── 5. N+1 友善提醒（偵測到關聯查詢模式） ────────
        if ($analysis->queryType === 'select' && $analysis->joinCount() === 0 && ! empty($analysis->conditions)) {
            foreach ($analysis->conditions as $cond) {
                if (preg_match('/\b(\w+_id)\s*=\s*\?/i', $cond)) {
                    $suggestions[] = new Suggestion(
                        id:       'potential-n1',
                        title:    '可能的 N+1 查詢',
                        message:  '偵測到以外鍵查詢的模式，建議搭配 Eloquent `with()` Eager Loading。',
                        severity: 'info',
                        example:  'User::with("posts")->get()',
                    );
                    break;
                }
            }
        }

        // ──── 6. 無 LIMIT 的大表查詢 ───────────────────────
        if ($analysis->queryType === 'select' && ! $analysis->hasLimit) {
            $suggestions[] = new Suggestion(
                id:       'add-limit',
                title:    '加入 LIMIT 限制',
                message:  '避免一次載入整張表的資料，特別是在分頁場景下。',
                severity: 'info',
                example:  'SELECT ... FROM ... LIMIT 100',
            );
        }

        // ──── 7. LIKE 前導萬用字元 ─────────────────────────
        foreach ($analysis->conditions as $condition) {
            if (preg_match("/LIKE\s+'%/i", $condition)) {
                $suggestions[] = new Suggestion(
                    id:       'fulltext-search',
                    title:    '考慮全文檢索',
                    message:  'LIKE "%..." 無法使用索引，建議改用 FULLTEXT INDEX 或外部搜尋引擎。',
                    severity: 'warning',
                    example:  'ALTER TABLE posts ADD FULLTEXT(title, body);',
                    docUrl:   'https://dev.mysql.com/doc/refman/8.0/en/fulltext-search.html',
                );
                break;
            }
        }

        // ──── 8. 索引建議 ──────────────────────────────────
        if ($analysis->queryType === 'select' && $analysis->joinCount() > 0) {
            $joinTables = array_column($analysis->joins, 'table');
            $suggestions[] = new Suggestion(
                id:       'check-indexes',
                title:    '檢查 JOIN 欄位索引',
                message:  '確認 JOIN 涉及的表 (' . implode(', ', $joinTables) . ') 的關聯欄位已建立索引。',
                severity: 'info',
                example:  'EXPLAIN ' . $analysis->sql,
            );
        }

        return $suggestions;
    }
}
