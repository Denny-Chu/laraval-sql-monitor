<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Lifecycle;

use LaravelSqlMonitor\Core\QueryAnalysis;
use LaravelSqlMonitor\Core\ComplexityResult;
use LaravelSqlMonitor\Core\Suggestion;

/**
 * 代表一條已執行查詢的完整記錄（含分析結果）。
 */
class QueryRecord
{
    public function __construct(
        /** 唯一 ID */
        public readonly string $id,

        /** 原始 SQL */
        public readonly string $sql,

        /** 綁定參數 */
        public readonly array $bindings,

        /** 執行時間（毫秒） */
        public readonly float $executionTimeMs,

        /** 資料庫連線名稱 */
        public readonly string $connection,

        /** 執行時間戳 */
        public readonly float $timestamp,

        /** 正規化後的 SQL（移除參數值） */
        public readonly string $normalizedSql,

        /** 呼叫棧 */
        public readonly array $stackTrace = [],

        /** SQL 分析結果 */
        public ?QueryAnalysis $analysis = null,

        /** 複雜度結果 */
        public ?ComplexityResult $complexity = null,

        /** 優化建議 */
        public array $suggestions = [],
    ) {}

    /**
     * 從 QueryExecuted 事件建立記錄。
     */
    public static function fromEvent(
        string $sql,
        array  $bindings,
        float  $time,
        string $connection,
        array  $stackTrace = [],
    ): self {
        return new self(
            id:              uniqid('q_', true),
            sql:             $sql,
            bindings:        $bindings,
            executionTimeMs: $time,
            connection:      $connection,
            timestamp:       microtime(true),
            normalizedSql:   self::normalize($sql),
            stackTrace:      $stackTrace,
        );
    }

    /**
     * 將 SQL 正規化（移除參數綁定值）以便比較是否為同一條查詢。
     */
    public static function normalize(string $sql): string
    {
        // 將 ? 參數保留不動
        // 將引號內的字串常數替換為 '?'
        $normalized = preg_replace("/'.+?'/", "'?'", $sql);
        // 將數字常數替換為 ?
        $normalized = preg_replace('/\b\d+\b/', '?', $normalized ?? $sql);
        // 壓縮多餘空白
        $normalized = preg_replace('/\s+/', ' ', $normalized ?? $sql);

        return trim($normalized ?? $sql);
    }

    /**
     * 建立查詢指紋（SQL + 參數的 hash）。
     */
    public function fingerprint(): string
    {
        return hash('sha256', $this->sql . json_encode($this->bindings));
    }

    public function isSlow(float $thresholdMs): bool
    {
        return $this->executionTimeMs >= $thresholdMs;
    }

    public function toArray(): array
    {
        return [
            'id'                => $this->id,
            'sql'               => $this->sql,
            'bindings'          => $this->bindings,
            'execution_time_ms' => $this->executionTimeMs,
            'connection'        => $this->connection,
            'timestamp'         => $this->timestamp,
            'normalized_sql'    => $this->normalizedSql,
            'stack_trace'       => $this->stackTrace,
            'analysis'          => $this->analysis?->toArray(),
            'complexity'        => $this->complexity?->toArray(),
            'suggestions'       => array_map(fn(Suggestion $s) => $s->toArray(), $this->suggestions),
        ];
    }
}
