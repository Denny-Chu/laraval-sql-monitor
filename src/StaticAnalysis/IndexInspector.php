<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\StaticAnalysis;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * 檢查資料庫中實際的索引情況。
 * 支援 MySQL / MariaDB。
 */
class IndexInspector
{
    protected Connection $connection;
    /** @var array<string, array<string, array>> 快取 */
    protected array $indexCache = [];

    public function __construct(?Connection $connection = null)
    {
        $this->connection = $connection ?? DB::connection();
    }

    /**
     * 檢查特定欄位是否有索引。
     */
    public function isColumnIndexed(string $table, string $column): bool
    {
        $indexes = $this->getTableIndexes($table);

        foreach ($indexes as $index) {
            if (in_array($column, $index['columns'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 取得表的所有索引（包括複合索引）。
     *
     * @return array<string, array{columns: string[], type: string, unique: bool}>
     */
    public function getTableIndexes(string $table): array
    {
        // 從快取取得
        if (isset($this->indexCache[$table])) {
            return $this->indexCache[$table];
        }

        $indexes = match ($this->getDatabaseDriver()) {
            'mysql'      => $this->getMysqlIndexes($table),
            'mariadb'    => $this->getMysqlIndexes($table), // MariaDB 同 MySQL
            'pgsql'      => $this->getPostgresIndexes($table),
            'sqlsrv'     => $this->getSqlServerIndexes($table),
            'sqlite'     => $this->getSqliteIndexes($table),
            default      => [],
        };

        $this->indexCache[$table] = $indexes;

        return $indexes;
    }

    /**
     * 檢查兩個表的 JOIN 欄位是否都有索引。
     */
    public function isJoinOptimizable(string $table1, string $column1, string $table2, string $column2): bool
    {
        return $this->isColumnIndexed($table1, $column1)
            && $this->isColumnIndexed($table2, $column2);
    }

    /**
     * 取得表的索引統計（選擇率、基數等）。
     */
    public function getIndexStats(string $table): array
    {
        return match ($this->getDatabaseDriver()) {
            'mysql'   => $this->getMysqlStats($table),
            'pgsql'   => $this->getPostgresStats($table),
            default   => [],
        };
    }

    // ─── MySQL / MariaDB ─────────────────────────────────

    protected function getMysqlIndexes(string $table): array
    {
        $results = $this->connection->select(
            "SELECT COLUMN_NAME, INDEX_NAME, (NON_UNIQUE = 0) AS is_unique
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             ORDER BY SEQ_IN_INDEX",
            [
                $this->connection->getDatabaseName(),
                $table,
            ]
        );

        $indexes = [];

        foreach ($results as $row) {
            $indexName = $row->INDEX_NAME;

            if (! isset($indexes[$indexName])) {
                $indexes[$indexName] = [
                    'columns' => [],
                    'type'    => $indexName === 'PRIMARY' ? 'primary' : 'index',
                    'unique'  => (bool) $row->is_unique || $indexName === 'PRIMARY',
                ];
            }

            $indexes[$indexName]['columns'][] = $row->COLUMN_NAME;
        }

        return $indexes;
    }

    protected function getMysqlStats(string $table): array
    {
        try {
            $results = $this->connection->select(
                "SELECT STAT_NAME, STAT_VALUE
                 FROM mysql.innodb_table_stats
                 WHERE OBJECT_SCHEMA = ? AND OBJECT_NAME = ?",
                [
                    $this->connection->getDatabaseName(),
                    $table,
                ]
            );

            $stats = [];

            foreach ($results as $row) {
                $stats[$row->STAT_NAME] = $row->STAT_VALUE;
            }

            return $stats;
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ─── PostgreSQL ───────────────────────────────────────

    protected function getPostgresIndexes(string $table): array
    {
        // TODO: 實作 PostgreSQL 索引檢查
        return [];
    }

    protected function getPostgresStats(string $table): array
    {
        // TODO: 實作 PostgreSQL 統計資訊
        return [];
    }

    // ─── SQL Server ───────────────────────────────────────

    protected function getSqlServerIndexes(string $table): array
    {
        // TODO: 實作 SQL Server 索引檢查
        return [];
    }

    // ─── SQLite ────────────────────────────────────────────

    protected function getSqliteIndexes(string $table): array
    {
        $results = $this->connection->select(
            "PRAGMA index_list(?)",
            [$table]
        );

        $indexes = [];

        foreach ($results as $indexInfo) {
            $indexName   = $indexInfo->name;
            $indexDetail = $this->connection->select(
                "PRAGMA index_info(?)",
                [$indexName]
            );

            $columns = array_map(fn($col) => $col->name, (array) $indexDetail);

            $indexes[$indexName] = [
                'columns' => $columns,
                'type'    => 'index',
                'unique'  => (bool) $indexInfo->unique,
            ];
        }

        return $indexes;
    }

    // ─── Helpers ────────────────────────────────────────────

    protected function getDatabaseDriver(): string
    {
        return $this->connection->getDriverName();
    }
}
