<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Core;

/**
 * SQL 查詢複雜度偵測器。
 *
 * 依據 QueryAnalysis 結構計算風險分數（0–100）並產生相應的警告訊息。
 */
class ComplexityDetector
{
    /** 嚴重程度常數 */
    public const SEVERITY_LOW      = 'low';
    public const SEVERITY_INFO     = 'info';
    public const SEVERITY_WARNING  = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    public function __construct(
        protected int  $joinThreshold      = 5,
        protected int  $subqueryDepthLimit = 3,
        protected bool $detectSelectStar   = true,
        protected bool $detectMissingWhere = true,
        protected bool $detectLikeWildcard = true,
    ) {}

    /**
     * 從配置建立實例。
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            joinThreshold:      $config['join_threshold']       ?? 5,
            subqueryDepthLimit: $config['subquery_depth_limit'] ?? 3,
            detectSelectStar:   $config['detect_select_star']   ?? true,
            detectMissingWhere: $config['detect_missing_where'] ?? true,
            detectLikeWildcard: $config['detect_like_wildcard'] ?? true,
        );
    }

    /**
     * 執行複雜度偵測並回傳結果。
     */
    public function detect(QueryAnalysis $analysis): ComplexityResult
    {
        if (! $analysis->isSuccessful()) {
            return new ComplexityResult(0, self::SEVERITY_LOW, []);
        }

        $score    = 0;
        $warnings = [];

        // ──── 規則 1：SELECT * ───────────────────────────────
        if ($this->detectSelectStar && $analysis->hasSelectStar) {
            $score    += 10;
            $warnings[] = $this->warn(
                'select-star',
                'Avoid SELECT *: specify only needed columns to reduce I/O and memory usage.',
                self::SEVERITY_WARNING,
            );
        }

        // ──── 規則 2：JOIN 數量 ──────────────────────────────
        $joinCount = $analysis->joinCount();
        if ($joinCount > $this->joinThreshold) {
            $score    += 25;
            $warnings[] = $this->warn(
                'excessive-joins',
                "Excessive JOINs detected ({$joinCount}): consider simplifying or splitting into multiple queries.",
                self::SEVERITY_CRITICAL,
            );
        } elseif ($joinCount >= 3) {
            $score    += 10;
            $warnings[] = $this->warn(
                'multiple-joins',
                "Multiple JOINs detected ({$joinCount}): review whether all joins are necessary.",
                self::SEVERITY_INFO,
            );
        }

        // ──── 規則 3：子查詢深度 ────────────────────────────
        $maxDepth = $analysis->subqueryMaxDepth();
        if ($maxDepth > $this->subqueryDepthLimit) {
            $score    += 20;
            $warnings[] = $this->warn(
                'deep-subquery',
                "Deep subquery nesting ({$maxDepth} levels): consider using CTEs or JOINs instead.",
                self::SEVERITY_WARNING,
            );
        } elseif ($maxDepth >= 2) {
            $score    += 10;
            $warnings[] = $this->warn(
                'subquery',
                "Subquery detected (depth {$maxDepth}): evaluate whether a JOIN would perform better.",
                self::SEVERITY_INFO,
            );
        }

        // ──── 規則 4：UNION ──────────────────────────────────
        if ($analysis->hasUnion) {
            $score    += 15;
            $warnings[] = $this->warn(
                'union',
                'UNION detected: ensure proper indexes on UNION columns and consider UNION ALL if duplicates are acceptable.',
                self::SEVERITY_INFO,
            );
        }

        // ──── 規則 5：缺少 WHERE 的 UPDATE / DELETE ─────────
        if ($this->detectMissingWhere) {
            if (in_array($analysis->queryType, ['update', 'delete'], true) && empty($analysis->conditions)) {
                $score    += 30;
                $warnings[] = $this->warn(
                    'missing-where',
                    strtoupper($analysis->queryType) . ' without WHERE clause: this will affect ALL rows in the table!',
                    self::SEVERITY_CRITICAL,
                );
            }
        }

        // ──── 規則 6：LIKE 前導萬用字元 ─────────────────────
        if ($this->detectLikeWildcard) {
            foreach ($analysis->conditions as $condition) {
                if (preg_match("/LIKE\s+'%/i", $condition)) {
                    $score    += 10;
                    $warnings[] = $this->warn(
                        'like-leading-wildcard',
                        'LIKE with leading wildcard (%) prevents index usage: consider full-text search.',
                        self::SEVERITY_WARNING,
                    );
                    break;
                }
            }
        }

        // ──── 規則 7：沒有 LIMIT 的 SELECT ──────────────────
        if ($analysis->queryType === 'select' && ! $analysis->hasLimit && ! empty($analysis->tables)) {
            $score    += 5;
            $warnings[] = $this->warn(
                'no-limit',
                'SELECT without LIMIT: consider adding LIMIT to prevent loading entire tables.',
                self::SEVERITY_INFO,
            );
        }

        // ──── 規則 8：GROUP BY + HAVING ─────────────────────
        if ($analysis->hasGroupBy && $analysis->hasHaving) {
            $score    += 10;
            $warnings[] = $this->warn(
                'group-having',
                'GROUP BY with HAVING: if possible, move HAVING conditions to WHERE for better performance.',
                self::SEVERITY_INFO,
            );
        }

        $finalScore = min($score, 100);

        return new ComplexityResult(
            score:    $finalScore,
            severity: $this->scoreSeverity($finalScore),
            warnings: $warnings,
        );
    }

    // ─── Helpers ─────────────────────────────────────────────

    private function warn(string $id, string $message, string $severity): array
    {
        return [
            'id'       => $id,
            'message'  => $message,
            'severity' => $severity,
        ];
    }

    private function scoreSeverity(int $score): string
    {
        return match (true) {
            $score >= 70 => self::SEVERITY_CRITICAL,
            $score >= 40 => self::SEVERITY_WARNING,
            $score >= 20 => self::SEVERITY_INFO,
            default      => self::SEVERITY_LOW,
        };
    }
}
