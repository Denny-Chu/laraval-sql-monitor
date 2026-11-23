<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Tests\Unit\Storage;

use LaravelSqlMonitor\Lifecycle\QueryRecord;
use LaravelSqlMonitor\Storage\Concerns\BuildsQueryLogRow;
use LaravelSqlMonitor\Storage\TextColumn;
use Orchestra\Testbench\TestCase;

class BuildsQueryLogRowTest extends TestCase
{
    public function testOversizedTextColumnsAreFittedForStorage(): void
    {
        $placeholders = implode(', ', array_fill(0, 1000, '(?, ?, ?, ?, ?, ?, ?)'));
        $sql = "insert into `philippine_address` (`barangay`, `city`, `created_at`, `is_deliverable`, `province`, `rate_zone`, `updated_at`) values {$placeholders}";
        $bindings = array_merge(...array_fill(0, 1000, ['BARANGAY NAME', 'CITY NAME', '2026-10-08 00:00:00', 1, 'PROVINCE', 'LUZON', '2026-10-08 00:00:00']));

        $row = $this->builder()->row(new QueryRecord(
            id: 'q_big',
            sql: $sql,
            bindings: $bindings,
            executionTimeMs: 593.89,
            connection: 'mysql',
            timestamp: 0.0,
            normalizedSql: QueryRecord::normalize($sql),
            stackTrace: array_fill(0, 5000, ['file' => '/var/www/vendor/laravel/framework/src/Illuminate/Database/Connection.php', 'line' => 824]),
        ));

        $this->assertGreaterThan(TextColumn::MAX_BYTES, strlen(json_encode($bindings)));
        foreach (['sql', 'bindings', 'normalized_sql', 'stack_trace', 'warnings', 'suggestions'] as $column) {
            $this->assertLessThanOrEqual(TextColumn::MAX_BYTES, strlen((string) $row[$column]), $column);
        }
        $this->assertTrue($row['is_slow']);
    }

    public function testSmallRecordKeepsOriginalValues(): void
    {
        $row = $this->builder()->row(new QueryRecord(
            id: 'q_small',
            sql: 'select * from `users` where `id` = ?',
            bindings: [7],
            executionTimeMs: 1.5,
            connection: 'mysql',
            timestamp: 0.0,
            normalizedSql: 'select * from `users` where `id` = ?',
        ));

        $this->assertSame('select * from `users` where `id` = ?', $row['sql']);
        $this->assertSame('[7]', $row['bindings']);
        $this->assertSame('[]', $row['stack_trace']);
        $this->assertFalse($row['is_slow']);
    }

    public function testUnlimitedStoreKeepsFullBindings(): void
    {
        $bindings = array_fill(0, 7000, 'BARANGAY NAME');

        $row = $this->builder(null)->row(new QueryRecord(
            id: 'q_sqlite',
            sql: 'insert into `philippine_address` values (?)',
            bindings: $bindings,
            executionTimeMs: 593.89,
            connection: 'mysql',
            timestamp: 0.0,
            normalizedSql: 'insert into `philippine_address` values (?)',
        ));

        $this->assertSame(json_encode($bindings), $row['bindings']);
    }

    private function builder(?int $maxTextBytes = TextColumn::MAX_BYTES): object
    {
        return new class($maxTextBytes)
        {
            use BuildsQueryLogRow;

            public function __construct(private ?int $limit) {}

            protected function maxTextBytes(): ?int
            {
                return $this->limit;
            }

            /** @return array<string, mixed> */
            public function row(QueryRecord $record): array
            {
                return $this->toRow($record);
            }
        };
    }
}
