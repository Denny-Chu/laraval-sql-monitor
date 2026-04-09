<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Storage\Contracts;

use LaravelSqlMonitor\Lifecycle\QueryRecord;

/**
 * 查詢持久化存儲介面。
 */
interface QueryStoreInterface
{
    /**
     * 持久化一條查詢記錄。
     */
    public function persist(QueryRecord $record): void;

    /**
     * 批次持久化多條查詢記錄。
     *
     * @param QueryRecord[] $records
     */
    public function persistBatch(array $records): void;

    /**
     * 依條件查詢記錄。
     */
    public function query(array $filters = [], int $limit = 50, int $offset = 0): array;

    /**
     * 查詢慢查詢記錄。
     */
    public function slowQueries(int $limit = 50): array;

    /**
     * 清除過期記錄。
     */
    public function cleanup(int $olderThanHours = 24): int;

    /**
     * 清除所有記錄。
     */
    public function truncate(): void;

    /**
     * 統計數據。
     */
    public function stats(): array;
}
