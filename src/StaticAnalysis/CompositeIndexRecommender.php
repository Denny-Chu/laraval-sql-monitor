<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\StaticAnalysis;

use LaravelSqlMonitor\StaticAnalysis\Ast\QueryCallSite;

/**
 * 根據 call site 彙整結果，為每個表產生複合索引建議。
 *
 * 設計原則（MySQL 複合索引最佳實踐）：
 *   1. Leftmost prefix rule — 索引只能從最左欄位開始使用
 *   2. Equality first, range last — 等值條件先、範圍條件後（range 欄位只能放一個且必須在尾）
 *   3. High selectivity first — 等值欄位之間按選擇率（distinct/rows）由高到低排序
 *   4. ORDER BY tail — 排序欄位若與 WHERE 不重疊且可與索引方向一致，放在尾端避免 filesort
 *   5. Existing-index deduplication — 若現有索引已能覆蓋，不產生重複建議
 *   6. Supersede detection — 若新建議可完全取代某個現有索引，明確標記以便 DROP
 *
 * 範疇：
 *   - 只處理 primaryTable 可靜態推斷的 call site
 *   - 只處理欄位名為字串常數的 WHERE / ORDER BY / GROUP BY
 *   - 涉及 table.column 形式、動態欄位 ($var)、closure 的條件一律跳過（無法靜態判斷）
 *   - LIKE '%xxx%' / != / NOT IN / whereDate/whereYear 等非 sargable 條件不納入索引建議
 */
class CompositeIndexRecommender
{
    public function __construct(
        private readonly ?IndexInspector $inspector = null,
    ) {}

    /**
     * 主要入口：輸入一組分析報告，輸出每個表的索引建議。
     *
     * @param  CallSiteReport[]  $reports
     * @return array<string, array<int, array>>  key = table name, value = [recommendation, ...]
     */
    public function recommend(array $reports): array
    {
        $sitesByTable = [];

        foreach ($reports as $report) {
            $table = $report->primaryTable;
            if ($table === null || $table === '' || ! $this->isSafeIdentifier($table)) {
                continue;
            }

            $sitesByTable[$table][] = $report->callSite;
        }

        $result = [];

        foreach ($sitesByTable as $table => $sites) {
            $recs = $this->analyseTable($table, $sites);
            if (! empty($recs)) {
                $result[$table] = $recs;
            }
        }

        return $result;
    }

    // ─── 表層級分析 ─────────────────────────────────────────

    /**
     * 對單一表分析：收集 pattern → 建候選 → 去重 → 排序。
     *
     * @param  QueryCallSite[]  $sites
     * @return array<int, array>
     */
    private function analyseTable(string $table, array $sites): array
    {
        // 1. 彙整 patterns（同一組 eq+range+orderBy 合併計數）
        $patterns = $this->collectPatterns($sites);

        if (empty($patterns)) {
            return [];
        }

        // 2. 取得表的現有索引
        $existingIndexes = [];
        if ($this->inspector !== null) {
            try {
                $existingIndexes = $this->inspector->getTableIndexes($table);
            } catch (\Throwable) {
                $existingIndexes = [];
            }
        }

        // 3. 對每個 pattern 建候選索引
        $candidates = [];
        foreach ($patterns as $pattern) {
            $candidate = $this->buildCandidate($table, $pattern);

            if ($candidate === null || empty($candidate['columns'])) {
                continue;
            }

            // 若現有索引已覆蓋此 pattern，跳過
            if ($this->isPatternCoveredByExisting($pattern, $existingIndexes)) {
                continue;
            }

            $candidate['patterns']  = [$pattern];
            $candidate['frequency'] = $pattern['frequency'];
            $candidates[] = $candidate;
        }

        // 4. 去重：相同欄位組合的候選合併（pattern 併入、frequency 累加）
        $deduped = $this->dedupeCandidates($candidates);

        // 5. 偵測被取代的現有索引
        foreach ($deduped as &$rec) {
            $rec['replaces'] = $this->findReplaceableExistingIndexes($rec['columns'], $existingIndexes);
        }
        unset($rec);

        // 6. 按總頻率降序
        usort($deduped, fn($a, $b) => $b['frequency'] <=> $a['frequency']);

        return $deduped;
    }

    // ─── Pattern 擷取 ───────────────────────────────────────

    /**
     * 從 call site 陣列中擷取所有查詢 pattern 並合併計數。
     *
     * @param  QueryCallSite[]  $sites
     * @return array<string, array>
     */
    private function collectPatterns(array $sites): array
    {
        $patterns = [];

        foreach ($sites as $site) {
            $pattern = $this->extractPatternFromSite($site);
            if ($pattern === null) {
                continue;
            }

            $key = $this->patternKey($pattern);

            if (isset($patterns[$key])) {
                $patterns[$key]['frequency']++;
                $patterns[$key]['sample_locations'][] = $site->locationString();
            } else {
                $pattern['frequency']        = 1;
                $pattern['sample_locations'] = [$site->locationString()];
                $patterns[$key]              = $pattern;
            }
        }

        return $patterns;
    }

    /**
     * 從單一 call site 擷取 pattern。
     *
     * @return array{eq_cols: string[], range_col: ?string, order_cols: array<int, array{column: string, direction: string}>}|null
     */
    private function extractPatternFromSite(QueryCallSite $site): ?array
    {
        $eqSet      = [];
        $rangeCol   = null;
        $orderCols  = [];

        foreach ($site->wheres as $where) {
            $col = $where['column'] ?? null;

            if (! is_string($col) || $col === '' || ! $this->isSafeIdentifier($col)) {
                continue; // 跳過 table.column / $var / 非法識別符
            }

            $type = $this->classifyWhereOperator(
                (string) ($where['method'] ?? 'where'),
                (string) ($where['operator'] ?? '='),
                $where['value'] ?? null,
            );

            if ($type === 'equality') {
                $eqSet[$col] = true;
            } elseif ($type === 'range') {
                // MySQL 每個索引只能使用一個 range 欄位；若已有一個，後續 range 欄位放棄（保守做法）
                if ($rangeCol === null) {
                    $rangeCol = $col;
                }
            }
            // 'not_indexable' → 不納入
        }

        if (empty($eqSet) && $rangeCol === null) {
            return null; // 無可用 WHERE 條件 → 此 site 不納入建議
        }

        // ORDER BY：只在所有方向一致時納入，否則放棄（方向混合時索引無法助排序）
        if (! empty($site->orderByColumns)) {
            $directions = array_unique(array_map(
                fn($ob) => $ob['direction'] ?? 'asc',
                $site->orderByColumns,
            ));

            if (count($directions) === 1) {
                foreach ($site->orderByColumns as $ob) {
                    $col = $ob['column'] ?? null;
                    if (is_string($col) && $this->isSafeIdentifier($col) && ! isset($eqSet[$col]) && $col !== $rangeCol) {
                        $orderCols[] = [
                            'column'    => $col,
                            'direction' => $ob['direction'] ?? 'asc',
                        ];
                    }
                }
            }
        }

        $eqCols = array_keys($eqSet);
        sort($eqCols);

        return [
            'eq_cols'    => $eqCols,
            'range_col'  => $rangeCol,
            'order_cols' => $orderCols,
        ];
    }

    /**
     * Pattern 唯一識別 key（用於合併相同 pattern）。
     */
    private function patternKey(array $pattern): string
    {
        $eq    = implode(',', $pattern['eq_cols']);
        $range = $pattern['range_col'] ?? '';
        $order = implode(',', array_map(
            fn($o) => "{$o['column']}:{$o['direction']}",
            $pattern['order_cols'] ?? [],
        ));

        return "eq=[{$eq}]|range=[{$range}]|order=[{$order}]";
    }

    /**
     * 將 WHERE 方法 + operator + value 分類為索引可用性。
     *
     * 回傳：
     *   - 'equality'      → 可走索引、適合放左側
     *   - 'range'         → 可走索引、必須放右側
     *   - 'not_indexable' → 不納入建議（!=, NOT IN, LIKE '%x%', whereDate/Year/Month 等非 sargable）
     */
    private function classifyWhereOperator(string $method, string $operator, mixed $value): string
    {
        // 方法層級分類優先
        switch ($method) {
            case 'whereIn':
            case 'whereNull':
            case 'whereNotNull':
                return 'equality';

            case 'whereNotIn':
                return 'not_indexable';

            case 'whereBetween':
                return 'range';

            // Date-part 方法會把欄位包在函數內（DATE()/YEAR()），導致 non-sargable
            case 'whereDate':
            case 'whereYear':
            case 'whereMonth':
                return 'not_indexable';
        }

        // where(col, op, val) 形式：看 operator
        $op = strtolower(trim($operator));

        return match ($op) {
            '=', '<=>', 'is', 'is null', 'is not null' => 'equality',
            '>', '<', '>=', '<=', 'between'            => 'range',
            '!=', '<>', 'not like'                     => 'not_indexable',
            'like'                                     => $this->classifyLikePattern($value),
            default                                    => 'equality',
        };
    }

    /**
     * LIKE 'abc%' 可走索引；LIKE '%abc' / '%abc%' 不能走索引。
     * 若值無法靜態判斷（變數），保守視為 not_indexable，不納入建議。
     */
    private function classifyLikePattern(mixed $value): string
    {
        if (! is_string($value)) {
            return 'not_indexable';
        }

        if ($value === '') {
            return 'not_indexable';
        }

        if (str_starts_with($value, '%')) {
            return 'not_indexable';
        }

        return 'equality'; // 前綴 LIKE 可用索引（視為 equality 類級別）
    }

    // ─── 候選索引產生 ──────────────────────────────────────

    /**
     * 根據 pattern 建立候選索引欄位序列。
     *
     * 順序規則：
     *   1. equality 欄位（按選擇率 DESC，同值按字母序）
     *   2. 一個 range 欄位
     *   3. ORDER BY 欄位（若未包含在上面）
     *
     * @return array{columns: string[], reasoning: array}|null
     */
    private function buildCandidate(string $table, array $pattern): ?array
    {
        $eqCols    = $pattern['eq_cols'];
        $rangeCol  = $pattern['range_col'];
        $orderCols = $pattern['order_cols'] ?? [];

        // 取 equality 欄位的選擇率
        $selectivities = [];
        foreach ($eqCols as $col) {
            $selectivities[$col] = $this->getSelectivity($table, $col);
        }

        // 按選擇率 DESC 排序；null 視為 0（最低）；tie 破解用字母序
        usort($eqCols, function (string $a, string $b) use ($selectivities) {
            $sa = $selectivities[$a] ?? 0.0;
            $sb = $selectivities[$b] ?? 0.0;

            if ($sa === $sb) {
                return strcmp($a, $b);
            }

            return $sb <=> $sa;
        });

        $columns = $eqCols;

        if ($rangeCol !== null) {
            $columns[] = $rangeCol;
        }

        foreach ($orderCols as $ob) {
            $col = $ob['column'];
            if (! in_array($col, $columns, true)) {
                $columns[] = $col;
            }
        }

        if (empty($columns)) {
            return null;
        }

        return [
            'columns'       => $columns,
            'selectivities' => $selectivities,
            'range_col'     => $rangeCol,
            'order_cols'    => $orderCols,
        ];
    }

    /**
     * 取得欄位選擇率。若無 inspector 或查詢失敗，回傳 null。
     */
    private function getSelectivity(string $table, string $column): ?float
    {
        if ($this->inspector === null) {
            return null;
        }

        try {
            return $this->inspector->getColumnSelectivity($table, $column);
        } catch (\Throwable) {
            return null;
        }
    }

    // ─── 現有索引覆蓋檢查 ──────────────────────────────────

    /**
     * 檢查 pattern 是否已被現有某個索引完整覆蓋。
     *
     * 條件：存在一個現有索引，其 leftmost K 欄位（K = |eq_cols|）是 eq_cols 的排列，
     *   並且（若 pattern 有 range_col）第 K+1 欄位恰為 range_col。
     *
     * 注意 ORDER BY 覆蓋不納入這個檢查 —— 只要 WHERE 走得到索引就算覆蓋（ORDER BY 是
     * 錦上添花）；若要完全避免 filesort 仍可另行建議，但那屬於進階優化，不在此檢查內。
     */
    private function isPatternCoveredByExisting(array $pattern, array $existingIndexes): bool
    {
        $eqSet      = array_flip($pattern['eq_cols']);
        $eqCount    = count($eqSet);
        $rangeCol   = $pattern['range_col'];
        $minNeeded  = $eqCount + ($rangeCol !== null ? 1 : 0);

        foreach ($existingIndexes as $idxName => $meta) {
            $cols = $meta['columns'] ?? [];
            if (count($cols) < $minNeeded) {
                continue;
            }

            // 取前 eqCount 欄位，必須是 eq_cols 的排列
            $prefix = array_slice($cols, 0, $eqCount);
            if ($eqCount > 0) {
                $prefixSet = array_flip($prefix);
                if (count($prefixSet) !== $eqCount || array_diff_key($eqSet, $prefixSet) !== []) {
                    continue;
                }
            }

            // 若有 range_col，下一欄必須是 range_col
            if ($rangeCol !== null) {
                $nextCol = $cols[$eqCount] ?? null;
                if ($nextCol !== $rangeCol) {
                    continue;
                }
            }

            return true; // 找到可覆蓋的現有索引
        }

        return false;
    }

    /**
     * 找出會被新候選索引完全取代的現有索引。
     *
     * 條件：現有索引的所有欄位，作為新候選的 leftmost 欄位時完全匹配（含順序）。
     * 例：
     *   existing = [user_id]
     *   candidate = [user_id, status, created_at]
     *   → user_id 是 candidate 的 left-prefix → existing 可被取代
     *
     * PRIMARY 永遠不納入可取代清單。
     *
     * @return array<int, string>  被取代的索引名稱清單
     */
    private function findReplaceableExistingIndexes(array $candidateCols, array $existingIndexes): array
    {
        $replaceable = [];

        foreach ($existingIndexes as $idxName => $meta) {
            if ($idxName === 'PRIMARY') {
                continue;
            }

            if (($meta['type'] ?? '') === 'primary') {
                continue;
            }

            $existingCols = $meta['columns'] ?? [];
            $len          = count($existingCols);

            if ($len === 0 || $len >= count($candidateCols)) {
                // 長度 >= 候選不構成被取代關係（候選本身就比它短或等長）
                continue;
            }

            $prefix = array_slice($candidateCols, 0, $len);
            if ($prefix === $existingCols) {
                $replaceable[] = $idxName;
            }
        }

        return $replaceable;
    }

    // ─── 候選去重 ──────────────────────────────────────────

    /**
     * 合併欄位組合完全相同的候選，累加頻率並合併 patterns。
     *
     * @param  array<int, array>  $candidates
     * @return array<int, array>
     */
    private function dedupeCandidates(array $candidates): array
    {
        $map = [];

        foreach ($candidates as $c) {
            $key = implode('|', $c['columns']);

            if (isset($map[$key])) {
                $map[$key]['frequency'] += $c['frequency'];
                $map[$key]['patterns']  = array_merge($map[$key]['patterns'], $c['patterns']);
            } else {
                $map[$key] = $c;
            }
        }

        return array_values($map);
    }

    // ─── Helpers ──────────────────────────────────────────

    private function isSafeIdentifier(string $identifier): bool
    {
        return (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier);
    }
}
