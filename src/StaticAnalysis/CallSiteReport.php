<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\StaticAnalysis;

use LaravelSqlMonitor\StaticAnalysis\Ast\QueryCallSite;

/**
 * 代表對單一查詢呼叫點（QueryCallSite）的靜態分析結果。
 *
 * 包含：
 *  - 識別到的問題清單（issues）
 *  - 複雜度分數（0–100）
 *  - 相對執行成本估計
 *  - 最高嚴重度等級
 */
class CallSiteReport
{
    // 嚴重度等級對應數值（方便比較）
    private const SEVERITY_RANK = [
        'ok'       => 0,
        'info'     => 1,
        'warning'  => 2,
        'critical' => 3,
    ];

    public function __construct(
        /** 原始呼叫點 */
        public readonly QueryCallSite $callSite,

        /** 識別出的主要操作表名稱（若可靜態推斷） */
        public readonly ?string $primaryTable,

        /**
         * 問題清單，每項格式：
         * ['severity' => 'warning', 'code' => 'no-limit', 'message' => '...']
         */
        public readonly array $issues,

        /** 複雜度分數 0–100 */
        public readonly int $complexityScore,

        /**
         * 相對執行成本估計值。
         * 基準 1.0 = 單表、有索引 WHERE、有 LIMIT 的查詢。
         * 數值越大代表預測成本越高，僅供參考。
         */
        public readonly float $estimatedCost,

        /**
         * 索引分析細節，格式：
         * ['table' => '...', 'column' => '...', 'indexed' => bool, 'note' => '...']
         */
        public readonly array $indexDetails = [],
    ) {}

    // ─── 嚴重度 ──────────────────────────────────────────────

    /**
     * 取得此報告的最高嚴重度等級（ok / info / warning / critical）。
     */
    public function severity(): string
    {
        if (empty($this->issues)) {
            return 'ok';
        }

        $max = 0;
        $level = 'ok';

        foreach ($this->issues as $issue) {
            $rank = self::SEVERITY_RANK[$issue['severity']] ?? 0;
            if ($rank > $max) {
                $max   = $rank;
                $level = $issue['severity'];
            }
        }

        return $level;
    }

    public function hasCritical(): bool
    {
        return $this->severity() === 'critical';
    }

    public function hasWarning(): bool
    {
        return in_array($this->severity(), ['warning', 'critical'], true);
    }

    public function isClean(): bool
    {
        return $this->severity() === 'ok';
    }

    // ─── 過濾問題 ─────────────────────────────────────────────

    /**
     * 取得達到指定最低嚴重度的所有問題。
     *
     * @param  string $minSeverity  'info' | 'warning' | 'critical'
     * @return array
     */
    public function issuesAbove(string $minSeverity): array
    {
        $minRank = self::SEVERITY_RANK[$minSeverity] ?? 0;

        return array_values(array_filter(
            $this->issues,
            fn($i) => (self::SEVERITY_RANK[$i['severity']] ?? 0) >= $minRank
        ));
    }

    // ─── 成本標籤 ─────────────────────────────────────────────

    /**
     * 取得成本的文字標籤（LOW / MEDIUM / HIGH / VERY HIGH）。
     */
    public function costLabel(): string
    {
        return match (true) {
            $this->estimatedCost >= 15.0 => 'VERY HIGH',
            $this->estimatedCost >= 5.0  => 'HIGH',
            $this->estimatedCost >= 2.0  => 'MEDIUM',
            default                      => 'LOW',
        };
    }

    /**
     * 取得複雜度的文字標籤（OK / WARNING / CRITICAL）。
     */
    public function complexityLabel(): string
    {
        return match (true) {
            $this->complexityScore >= 70 => 'CRITICAL',
            $this->complexityScore >= 40 => 'WARNING',
            default                      => 'OK',
        };
    }

    // ─── 序列化 ───────────────────────────────────────────────

    public function toArray(): array
    {
        $site = $this->callSite;

        return [
            'location'         => $site->locationString(),
            'file'             => $site->filePath,
            'line'             => $site->startLine,
            'class'            => $site->className,
            'method'           => $site->methodName,
            'root_type'        => $site->rootType,
            'chain_summary'    => $site->chainSummary(),
            'primary_table'    => $this->primaryTable,
            'terminal_method'  => $site->terminalMethod,
            'join_count'       => $site->joinCount(),
            'where_count'      => count($site->wheres),
            'has_limit'        => $site->hasLimit,
            'has_order_by'     => $site->hasOrderBy,
            'has_group_by'     => $site->hasGroupBy,
            'has_union'        => $site->hasUnion,
            'complexity_score' => $this->complexityScore,
            'complexity_label' => $this->complexityLabel(),
            'estimated_cost'   => $this->estimatedCost,
            'cost_label'       => $this->costLabel(),
            'severity'         => $this->severity(),
            'issues'           => $this->issues,
            'index_details'    => $this->indexDetails,
        ];
    }
}
