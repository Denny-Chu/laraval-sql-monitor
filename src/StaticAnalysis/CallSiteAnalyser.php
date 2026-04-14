<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\StaticAnalysis;

use LaravelSqlMonitor\StaticAnalysis\Ast\QueryCallSite;
use Illuminate\Support\Str;

/**
 * 對 AstAnalyser 產生的 QueryCallSite 進行深度靜態分析。
 *
 * 分析項目：
 *  1. SELECT * 偵測
 *  2. 缺少 WHERE 條件（全表掃描風險）
 *  3. 缺少 LIMIT（無界結果集）
 *  4. 過量 JOIN
 *  5. JOIN 索引提示
 *  6. WHERE 欄位索引檢查（需要資料庫連線）
 *  7. GROUP BY 索引提示
 *  8. LIMIT 無 ORDER BY（不確定性結果）
 *  9. UNION 提示
 * 10. N+1 風險偵測（with() 缺失的 Eloquent 呼叫）
 * 11. 複雜度評分（0–100）
 * 12. 相對成本估計
 */
class CallSiteAnalyser
{
    /** 會觸發「全表掃描風險」的終端方法 */
    private const BULK_TERMINAL_METHODS = ['get', 'pluck', 'cursor', 'lazy', 'chunk', null];

    /** 被視為「單筆」的終端方法（不需要 LIMIT 檢查） */
    private const SINGLE_TERMINAL_METHODS = ['first', 'find', 'findOrFail', 'firstOrFail', 'sole', 'value', 'count', 'sum', 'avg', 'min', 'max', 'exists', 'doesntExist'];

    /** JOIN 數量門檻 */
    private const JOIN_WARNING_THRESHOLD  = 3;
    private const JOIN_CRITICAL_THRESHOLD = 5;

    /** 寫入操作的根方法或終端方法（不適用 SELECT 相關規則） */
    private const WRITE_METHODS = [
        'create', 'insert', 'insertOrIgnore', 'insertGetId', 'upsert',
        'update', 'delete', 'forceDelete', 'truncate',
    ];

    public function __construct(
        private readonly ?IndexInspector $indexInspector = null,
    ) {}

    // ─── 主要入口 ──────────────────────────────────────────────

    /**
     * 分析單一呼叫點，回傳完整報告。
     */
    public function analyse(QueryCallSite $site): CallSiteReport
    {
        $issues       = [];
        $indexDetails = [];
        $primaryTable = $this->extractPrimaryTable($site);

        $isWrite = $this->isWriteOperation($site);

        // ── 1. SELECT * ──────────────────────────────────────
        if (! $isWrite && $site->hasSelectStar()) {
            $issues[] = $this->issue('info', 'select-star',
                '使用了 SELECT *，建議明確指定所需欄位以減少 I/O');
        }

        // ── 2. 無 WHERE 條件 ─────────────────────────────────
        if (! $isWrite && empty($site->wheres) && $this->isBulkTerminal($site)) {
            $issues[] = $this->issue('warning', 'no-where',
                '無 WHERE 條件，可能造成全表掃描');
        }

        // ── 3. 無 LIMIT ────────────────────────────────────
        if (! $isWrite && ! $site->hasLimit && $this->isBulkTerminal($site)
            && ! in_array($site->terminalMethod, ['cursor', 'lazy', 'chunk'], true)
        ) {
            $issues[] = $this->issue('warning', 'no-limit',
                '無 LIMIT 限制，可能回傳大量資料');
        }

        // ── 4. 過量 JOIN ────────────────────────────────────
        $joinCount = $site->joinCount();
        if ($joinCount > self::JOIN_CRITICAL_THRESHOLD) {
            $issues[] = $this->issue('critical', 'excessive-joins',
                "JOIN 數量過多（{$joinCount} 個），建議拆分查詢或重新設計資料模型");
        } elseif ($joinCount > self::JOIN_WARNING_THRESHOLD) {
            $issues[] = $this->issue('warning', 'many-joins',
                "JOIN 數量偏多（{$joinCount} 個），請確認效能是否可接受");
        }

        // ── 5. JOIN 索引提示 ─────────────────────────────────
        foreach ($site->joins as $join) {
            $joinTable = $join['table'] ?? null;

            if ($joinTable) {
                $issues[] = $this->issue('info', 'join-index-hint',
                    "JOIN `{$joinTable}`：確認 JOIN 條件欄位（雙方）皆有索引");
            }
        }

        // ── 6. WHERE 欄位索引檢查 ────────────────────────────
        if ($this->indexInspector && $primaryTable) {
            foreach ($site->wheres as $where) {
                $col = $where['column'] ?? null;
                if ($col === null || !is_string($col) || str_contains($col, '.') || str_starts_with($col, '$')) {
                    continue; // 跳過含表名前綴的欄位（需更複雜處理）
                }

                try {
                    $indexed = $this->indexInspector->isColumnIndexed($primaryTable, $col);

                    $indexDetails[] = [
                        'table'   => $primaryTable,
                        'column'  => $col,
                        'indexed' => $indexed,
                    ];

                    if (! $indexed) {
                        $issues[] = $this->issue('warning', 'missing-index',
                            "WHERE 欄位 `{$col}` 在 `{$primaryTable}` 表中無索引，建議新增索引以加速查詢");
                    }
                } catch (\Throwable) {
                    // 資料庫不可用或表不存在，跳過索引檢查
                }
            }
        }

        // ── 7. GROUP BY 提示 ─────────────────────────────────
        if ($site->hasGroupBy) {
            $issues[] = $this->issue('info', 'group-by-hint',
                '使用了 GROUP BY，確認分組欄位有索引以避免 filesort');
        }

        // ── 8. LIMIT 無 ORDER BY ─────────────────────────────
        if ($site->hasLimit && ! $site->hasOrderBy) {
            $issues[] = $this->issue('info', 'limit-without-order',
                '有 LIMIT 但無 ORDER BY，每次查詢結果順序可能不一致');
        }

        // ── 9. UNION ────────────────────────────────────────
        if ($site->hasUnion) {
            $issues[] = $this->issue('info', 'union-detected',
                '使用了 UNION，確認是否可改用 UNION ALL（若不需去重複）以提升效能');
        }

        // ── 10. Eloquent N+1 風險 ────────────────────────────
        if (! $isWrite && $site->isEloquent() && empty($site->withs)
            && $this->isBulkTerminal($site)
        ) {
            $issues[] = $this->issue('info', 'n1-risk',
                'Eloquent 批量查詢未使用 with() 預先載入，存在 N+1 查詢風險');
        }

        // ── 計算分數 ────────────────────────────────────────
        $complexityScore = $this->calculateComplexity($site, $indexDetails);
        $estimatedCost   = $this->estimateCost($site, $indexDetails);

        return new CallSiteReport(
            callSite:        $site,
            primaryTable:    $primaryTable,
            issues:          $issues,
            complexityScore: $complexityScore,
            estimatedCost:   $estimatedCost,
            indexDetails:    $indexDetails,
        );
    }

    /**
     * 批量分析多個呼叫點。
     *
     * @param  QueryCallSite[] $sites
     * @return CallSiteReport[]
     */
    public function analyseMany(array $sites): array
    {
        return array_map(fn(QueryCallSite $s) => $this->analyse($s), $sites);
    }

    // ─── 複雜度評分 ────────────────────────────────────────────

    /**
     * 計算查詢的複雜度分數（0–100）。
     *
     * 計分規則：
     *  - 每個 JOIN：+15（上限 50）
     *  - 無 WHERE：+15
     *  - 未索引的 WHERE 欄位：+10 / 個
     *  - SELECT *：+5
     *  - 無 LIMIT（批量查詢）：+10
     *  - GROUP BY：+10
     *  - UNION：+15
     */
    private function calculateComplexity(QueryCallSite $site, array $indexDetails): int
    {
        $score = 0;

        // JOIN 數量
        $score += min($site->joinCount() * 15, 50);

        // 無 WHERE
        if (empty($site->wheres) && $this->isBulkTerminal($site)) {
            $score += 15;
        }

        // 未索引的 WHERE
        $unindexed = count(array_filter($indexDetails, fn($d) => ! $d['indexed']));
        $score += min($unindexed * 10, 30);

        // SELECT *
        if (! $this->isWriteOperation($site) && $site->hasSelectStar()) {
            $score += 5;
        }

        // 無 LIMIT
        if (! $this->isWriteOperation($site) && ! $site->hasLimit && $this->isBulkTerminal($site)
            && ! in_array($site->terminalMethod, ['cursor', 'lazy', 'chunk'], true)
        ) {
            $score += 10;
        }

        // GROUP BY
        if ($site->hasGroupBy) {
            $score += 10;
        }

        // UNION
        if ($site->hasUnion) {
            $score += 15;
        }

        return min($score, 100);
    }

    // ─── 成本估計 ──────────────────────────────────────────────

    /**
     * 估計查詢的相對執行成本。
     *
     * 基準 1.0 = 單表、有索引 WHERE、有 LIMIT。
     * 成本模型：
     *  - 每個 JOIN：×1.5
     *  - 無 WHERE：×3.0
     *  - 每個有 WHERE：×0.7（疊加減少）
     *  - 無 LIMIT（批量）：×2.0
     *  - UNION：×1.5
     *  - GROUP BY：×1.3
     *  - 未索引的 WHERE：每個 ×1.8
     */
    private function estimateCost(QueryCallSite $site, array $indexDetails): float
    {
        $cost = 1.0;

        // JOIN
        for ($i = 0; $i < $site->joinCount(); $i++) {
            $cost *= 1.5;
        }

        // WHERE 條件
        if (! $this->isWriteOperation($site) && empty($site->wheres) && $this->isBulkTerminal($site)) {
            $cost *= 3.0;
        } else {
            foreach ($site->wheres as $ignored) {
                $cost *= 0.7;
            }
        }

        // 未索引 WHERE
        $unindexed = count(array_filter($indexDetails, fn($d) => ! $d['indexed']));
        for ($i = 0; $i < $unindexed; $i++) {
            $cost *= 1.8;
        }

        // 無 LIMIT（批量查詢）
        if (! $this->isWriteOperation($site) && ! $site->hasLimit && $this->isBulkTerminal($site)
            && ! in_array($site->terminalMethod, ['cursor', 'lazy', 'chunk'], true)
        ) {
            $cost *= 2.0;
        }

        // UNION
        if ($site->hasUnion) {
            $cost *= 1.5;
        }

        // GROUP BY
        if ($site->hasGroupBy) {
            $cost *= 1.3;
        }

        return round($cost, 2);
    }

    // ─── 輔助方法 ──────────────────────────────────────────────

    /**
     * 從 QueryCallSite 推斷主要操作表名稱。
     */
    private function extractPrimaryTable(QueryCallSite $site): ?string
    {
        // DB::table('xxx') — 第一個參數通常就是表名
        if ($site->rootType === 'db' && ! empty($site->rootArgs)) {
            $first = $site->rootArgs[0] ?? null;
            if (is_string($first) && $first !== '') {
                return $this->normalizeTableName($first);
            }
        }

        // Eloquent Model::query() / Model::where() 等
        // rootClass 才是 Model 類別名稱；rootArgs 通常是查詢方法的參數
        if ($site->rootType === 'eloquent') {
            $className = $site->rootClass;

            if (! is_string($className) || $className === '') {
                $className = $site->rootArgs[0] ?? null;
            }

            if (is_string($className) && $className !== '' && ! str_starts_with($className, '$')) {
                return $this->classNameToTable($className);
            }
        }

        return null;
    }

    /**
     * 將 Model 類別名稱轉換為預設的表名稱。
     * 例：App\Models\UserProfile → user_profiles
     */
    private function classNameToTable(string $className): string
    {
        // 取短名稱
        $parts = explode('\\', $className);
        $short = end($parts);

        // 轉蛇底式並簡單複數化
        return Str::snake(Str::plural($short));
    }

    /**
     * 將 DB::table() 的字串表名正規化，移除 alias。
     * 例：users AS u -> users, users u -> users
     */
    private function normalizeTableName(string $table): string
    {
        $table = trim($table);

        if ($table === '' || str_starts_with($table, '(')) {
            return $table;
        }

        $normalized = preg_replace('/\s+as\s+[^\s]+$/i', '', $table);
        if (is_string($normalized)) {
            $table = trim($normalized);
        }

        if (! str_contains($table, ' ')) {
            return $table;
        }

        $normalized = preg_replace('/\s+[^\s]+$/', '', $table);

        return is_string($normalized) ? trim($normalized) : $table;
    }

    /**
     * 判斷呼叫點是否為寫入操作（INSERT / UPDATE / DELETE），
     * 寫入操作不適用 SELECT 相關規則。
     */
    private function isWriteOperation(QueryCallSite $site): bool
    {
        if (in_array($site->rootMethod, self::WRITE_METHODS, true)) {
            return true;
        }

        if ($site->terminalMethod !== null
            && in_array($site->terminalMethod, self::WRITE_METHODS, true)
        ) {
            return true;
        }

        return false;
    }

    /**
     * 判斷呼叫點是否為「批量」類型（可能回傳多筆資料）。
     *
     * 優先用 terminalMethod 判斷；若為 null（例如 User::find($id) 無後續鏈），
     * 則 fallback 到 rootMethod，避免單筆查詢方法被誤判為批量。
     */
    private function isBulkTerminal(QueryCallSite $site): bool
    {
        $method = $site->terminalMethod
            ?? (in_array($site->rootMethod, self::SINGLE_TERMINAL_METHODS, true)
                ? $site->rootMethod
                : null);

        if ($method === null) {
            return true; // 未偵測到任何終端方法，視為潛在批量
        }

        return ! in_array($method, self::SINGLE_TERMINAL_METHODS, true);
    }

    /**
     * 快速建立 issue 陣列。
     */
    private function issue(string $severity, string $code, string $message): array
    {
        return [
            'severity' => $severity,
            'code'     => $code,
            'message'  => $message,
        ];
    }
}
