<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Storage\Concerns;

use LaravelSqlMonitor\Lifecycle\QueryRecord;
use LaravelSqlMonitor\Storage\TextColumn;

/**
 * 把 QueryRecord 轉成 sql_monitor_logs 的一列，TEXT 欄位一律限制在欄位上限內。
 */
trait BuildsQueryLogRow
{
    /**
     * 存儲 TEXT 欄位的位元組上限；null 代表沒有限制。
     */
    abstract protected function maxTextBytes(): ?int;

    /**
     * @return array<string, mixed>
     */
    protected function toRow(QueryRecord $record): array
    {
        $max = $this->maxTextBytes();

        return [
            'connection_name'     => $record->connection,
            'sql'                 => TextColumn::fit($record->sql, $max),
            'bindings'            => TextColumn::fit($this->encode($record->bindings), $max),
            'execution_time_ms'   => $record->executionTimeMs,
            'normalized_sql'      => TextColumn::fit($record->normalizedSql, $max),
            'stack_trace'         => TextColumn::fit($this->encode($record->stackTrace), $max),
            'complexity_score'    => $record->complexity?->score ?? 0,
            'complexity_severity' => $record->complexity?->severity ?? 'low',
            'warnings'            => TextColumn::fit($this->encode($record->complexity?->warnings ?? []), $max),
            'suggestions'         => TextColumn::fit($this->encode(
                array_map(fn($s) => $s->toArray(), $record->suggestions)
            ), $max),
            'query_type'          => $record->analysis?->queryType ?? 'unknown',
            'primary_table'       => $record->analysis?->tables[0] ?? null,
            'is_slow'             => $record->isSlow(config('sql-monitor.slow_query.threshold_ms', 100)),
            'is_n1'               => $record->isN1,
            'n1_count'            => $record->n1Count,
            'n1_suggestion'       => TextColumn::fit($record->n1Suggestion, $max),
            'is_duplicate'        => $record->isDuplicate,
            'duplicate_count'     => $record->duplicateCount,
            'executed_at'         => date('Y-m-d H:i:s', (int) $record->timestamp),
            'created_at'          => now()->toDateTimeString(),
        ];
    }

    /**
     * JSON 編碼；非 UTF-8 位元組（例如二進位 binding）以替代字元取代，仍無法編碼時回 null。
     */
    private function encode(array $value): ?string
    {
        $json = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $json === false ? null : $json;
    }
}
