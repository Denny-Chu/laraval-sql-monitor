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
    private const SINGLE_TERMINAL_METHODS = [
        'first', 'find', 'findOrFail', 'firstOrFail', 'sole',
        // firstOrCreate / firstOrNew / findOrNew / updateOrCreate 均回傳單一 model
        'firstOrCreate', 'firstOrNew', 'findOrNew', 'updateOrCreate',
        'value', 'count', 'sum', 'avg', 'min', 'max', 'exists', 'doesntExist',
    ];

    /**
     * 聚合 / 純量 terminal：Laravel 會重寫 SELECT 子句
     * （例如 count→count(*)、value('col')→SELECT col、sum('col')→sum(col)），
     * 因此 select-star 規則對它們不適用。
     */
    private const SCALAR_TERMINAL_METHODS = [
        'count', 'exists', 'doesntExist',
        'sum', 'avg', 'min', 'max', 'value',
    ];

    /** JOIN 數量門檻 */
    private const JOIN_WARNING_THRESHOLD  = 3;
    private const JOIN_CRITICAL_THRESHOLD = 5;

    /** 寫入操作的根方法或終端方法（不適用 SELECT 相關規則） */
    private const WRITE_METHODS = [
        'create', 'insert', 'insertOrIgnore', 'insertGetId', 'insertUsing', 'upsert',
        'update', 'updateOrInsert', 'updateOrCreate', 'updateExistingPivot',
        'increment', 'decrement', 'incrementEach', 'decrementEach',
        'delete', 'forceDelete', 'truncate',
    ];

    /**
     * `DB::` facade 下代表「執行 raw SQL」的方法。
     * 這些方法的第一個參數是 SQL 字串，不走 Query Builder 鏈，
     * 因此 select-star / no-where / no-limit / n1-risk 等基於方法鏈的規則都不適用。
     */
    private const RAW_SQL_ROOT_METHODS = [
        'statement', 'unprepared', 'select', 'raw',
        'insert', 'update', 'delete', 'affectingStatement',
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

        $isWrite  = $this->isWriteOperation($site);
        $isRawSql = $this->isRawSqlCall($site);

        // Raw SQL：方法鏈類規則不適用，只發出一次性提示，快速回傳
        if ($isRawSql) {
            $issues[] = $this->issue('info', 'raw-sql',
                '偵測到 raw SQL（DB::statement / DB::select / DB::unprepared 等），靜態分析無法檢查 SQL 內容，請人工審查');

            return new CallSiteReport(
                callSite:        $site,
                primaryTable:    null,
                issues:          $issues,
                complexityScore: 0,
                estimatedCost:   1.0,
                indexDetails:    [],
            );
        }

        // ── 1. SELECT * ──────────────────────────────────────
        // 聚合 / 純量 terminal（count/exists/value/sum...）會被 Laravel 重寫 SELECT 子句，
        // 即便 chain 無顯式 select()，實際送出的 SQL 不會是 SELECT *。
        if (! $isWrite && $site->hasSelectStar()
            && ! $this->terminalRewritesSelectClause($site)
        ) {
            $issues[] = $this->issue('info', 'select-star',
                '使用了 SELECT *，建議明確指定所需欄位以減少 I/O');
        }

        // ── 2. 無 WHERE 條件 ─────────────────────────────────
        // 註：只有「已經看到終端方法」的呼叫點才檢查 no-where，避免
        // split-chain 模式（`$q = Model::with(); $q->where()->get()`）
        // 因為 extractor 看不到後續鏈而誤報。其他規則（select-star、
        // n1-risk、join 檢查）不受影響，該呼叫點仍然會被分析。
        if (! $isWrite && empty($site->wheres) && $this->isBulkTerminal($site)
            && $site->terminalMethod !== null
        ) {
            $issues[] = $this->issue('warning', 'no-where',
                '無 WHERE 條件，可能造成全表掃描');
        }

        // ── 3. 無 LIMIT ────────────────────────────────────
        // 串流 / 分批 terminal（cursor / lazy / chunk / each 家族）
        // 本身就會控制 batch 大小，無需強制要求 LIMIT。
        if (! $isWrite && ! $site->hasLimit && $this->isBulkTerminal($site)
            && $site->terminalMethod !== null
            && ! in_array($site->terminalMethod, [
                'cursor',
                'lazy', 'lazyById',
                'chunk', 'chunkById', 'chunkMap',
                'each', 'eachById',
            ], true)
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
        // 只有「回傳 Model / Model Collection」的 terminal 才有 N+1 風險，
        // pluck/value/count/exists 等回傳原始值者不適用（無 relation 可 lazy-load）。
        if (! $isWrite && $site->isEloquent() && empty($site->withs)
            && $this->returnsHydratedModels($site)
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
        if (! $this->isWriteOperation($site) && $site->hasSelectStar()
            && ! $this->terminalRewritesSelectClause($site)
        ) {
            $score += 5;
        }

        // 無 LIMIT
        if (! $this->isWriteOperation($site) && ! $site->hasLimit && $this->isBulkTerminal($site)
            && $site->terminalMethod !== null
            && ! in_array($site->terminalMethod, [
                'cursor',
                'lazy', 'lazyById',
                'chunk', 'chunkById', 'chunkMap',
                'each', 'eachById',
            ], true)
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
     * 將 Model 類別名稱轉換為實際表名稱。
     *
     * 優先順序：
     *   1. 透過 ReflectionClass::getDefaultProperties() 讀取 $table 屬性預設值
     *      （不需實例化 Model，安全且無副作用）
     *   2. Fallback：Str::snake(Str::plural($short))
     *      （Laravel 預設慣例，適用於未明確定義 $table 的 Model）
     *
     * 例：
     *   App\Models\PurchaseItem（有 $table = 'purchase_item'）→ 'purchase_item'
     *   App\Models\UserProfile（無 $table 屬性）→ 'user_profiles'
     */
    private function classNameToTable(string $className): string
    {
        // 取短名稱（同時保留作為 fallback 基礎）
        $parts = explode('\\', $className);
        $short = end($parts);
        $fallback = Str::snake(Str::plural($short));

        // 嘗試透過 Reflection 讀取 Model 的 $table 屬性預設值
        try {
            if (class_exists($className, autoload: true)) {
                $defaults = (new \ReflectionClass($className))->getDefaultProperties();

                if (isset($defaults['table']) && is_string($defaults['table']) && $defaults['table'] !== '') {
                    return $defaults['table'];
                }
            }
        } catch (\Throwable) {
            // class 載入失敗或 reflection 失敗，直接 fallback
        }

        return $fallback;
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
    /**
     * 回傳 terminal 是否會產生「model / model collection」結果。
     * 只有這類結果才可能因為未 eager-load relation 而產生 N+1。
     *
     * pluck/value/count/sum/exists 等返回原始值，不具 relation 存取點，故排除。
     * terminal 為 null（鏈被中斷）時保守視為 true，維持原本的 over-report 行為。
     */
    private function returnsHydratedModels(QueryCallSite $site): bool
    {
        $method = $site->terminalMethod;

        if ($method === null) {
            // 無 terminal 視為未知，和原本 isBulkTerminal(null)=true 一致，保留 over-report
            return true;
        }

        static $hydratedBulkTerminals = [
            'get', 'all',
            'paginate', 'simplePaginate', 'cursorPaginate',
            'cursor',
            'chunk', 'chunkById', 'chunkMap',
            'each', 'eachById',
            'lazy', 'lazyById',
        ];

        return in_array($method, $hydratedBulkTerminals, true);
    }

    /**
     * 判斷是否為 DB facade 的 raw SQL 呼叫（非 Query Builder 鏈）。
     * 例：DB::statement('SET ...'), DB::select('SELECT ...'), DB::unprepared(...)
     */
    private function isRawSqlCall(QueryCallSite $site): bool
    {
        if ($site->rootType !== 'db') {
            return false;
        }

        if ($site->rootMethod === null) {
            return false;
        }

        return in_array($site->rootMethod, self::RAW_SQL_ROOT_METHODS, true);
    }

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

        // 啟發式：僅在「terminal 未被識別為任何已知執行方法」時使用，
        // 若鏈最尾端是未知的方法名稱但名稱看起來是寫入操作（deleteWithAuthor 等專案自訂包裝），
        // 視為寫入以避免對 select-star / no-limit / n1-risk 誤判。
        if ($site->terminalMethod === null) {
            $lastMethod = $this->lastChainMethod($site);
            if ($lastMethod !== null && $this->looksLikeWriteMethodName($lastMethod)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 取得查詢鏈中最後一個方法名稱（若無則為 null）。
     */
    private function lastChainMethod(QueryCallSite $site): ?string
    {
        if (empty($site->chain)) {
            return $site->rootMethod;
        }

        $last = end($site->chain);

        return is_array($last) && isset($last['method']) && is_string($last['method'])
            ? $last['method']
            : null;
    }

    /**
     * 啟發式：方法名稱是否看起來像寫入操作。
     * 僅用於「terminal 未匹配已知方法」的 fallback，不覆蓋已知判斷。
     */
    private function looksLikeWriteMethodName(string $method): bool
    {
        // 常見寫入動詞前綴（小寫匹配）
        // 排除過於模糊的動詞如 edit/modify/store/add/save，避免誤把讀取型別方法當成寫入。
        static $prefixes = [
            'delete', 'destroy', 'remove', 'purge', 'softDelete', 'forceDelete',
            'update', 'upsert',
            'insert', 'create',
            'increment', 'decrement',
            'sync', 'attach', 'detach',
            'restore',
        ];

        foreach ($prefixes as $p) {
            if (stripos($method, $p) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Laravel 會針對某些 terminal 覆寫 SELECT 子句，
     * 此時 select-star 規則不適用：
     *  - 聚合/純量：count / exists / sum / avg / min / max / value
     *  - pluck('col'[, 'key']) 會精確選取指定欄位
     */
    private function terminalRewritesSelectClause(QueryCallSite $site): bool
    {
        $method = $site->terminalMethod;
        if ($method === null) {
            return false;
        }

        if (in_array($method, self::SCALAR_TERMINAL_METHODS, true)) {
            return true;
        }

        return $method === 'pluck';
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
