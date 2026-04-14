<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Storage;

use Illuminate\Support\Facades\DB;
use LaravelSqlMonitor\Lifecycle\QueryRecord;
use LaravelSqlMonitor\Storage\Contracts\QueryStoreInterface;

/**
 * 使用既有資料庫連線作為查詢日誌持久化存儲。
 */
class DatabaseQueryStore implements QueryStoreInterface
{
    protected string $connection;
    protected string $table;

    /**
     * 延遲建表旗標，原因同 SqliteQueryStore：
     * 避免 constructor 觸發 QueryExecuted，在 singleton 構建期間造成容器遞迴。
     */
    protected bool $tableEnsured = false;

    public function __construct(string $connection = 'mysql', string $table = 'sql_monitor_logs')
    {
        $this->connection = $connection;
        $this->table = $table;

        // 不在 constructor 呼叫 ensureTableExists()
    }

    public function persist(QueryRecord $record): void
    {
        $this->db()->insert([
            'query_id'            => $record->id,
            'connection_name'     => $record->connection,
            'sql'                 => $record->sql,
            'bindings'            => json_encode($record->bindings),
            'execution_time_ms'   => $record->executionTimeMs,
            'normalized_sql'      => $record->normalizedSql,
            'stack_trace'         => json_encode($record->stackTrace),
            'complexity_score'    => $record->complexity?->score ?? 0,
            'complexity_severity' => $record->complexity?->severity ?? 'low',
            'warnings'            => json_encode($record->complexity?->warnings ?? []),
            'suggestions'         => json_encode(
                array_map(fn($s) => $s->toArray(), $record->suggestions)
            ),
            'query_type'          => $record->analysis?->queryType ?? 'unknown',
            'primary_table'       => $record->analysis?->tables[0] ?? null,
            'is_slow'             => $record->isSlow(config('sql-monitor.slow_query.threshold_ms', 100)),
            'executed_at'         => date('Y-m-d H:i:s', (int) $record->timestamp),
            'created_at'          => now()->toDateTimeString(),
        ]);
    }

    public function persistBatch(array $records): void
    {
        foreach ($records as $record) {
            $this->persist($record);
        }
    }

    public function query(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $query = $this->db();

        if (isset($filters['is_slow'])) {
            $query->where('is_slow', $filters['is_slow']);
        }

        if (isset($filters['query_type'])) {
            $query->where('query_type', $filters['query_type']);
        }

        if (isset($filters['min_time_ms'])) {
            $query->where('execution_time_ms', '>=', $filters['min_time_ms']);
        }

        if (isset($filters['table'])) {
            $query->where('primary_table', $filters['table']);
        }

        return $query
            ->orderByDesc('executed_at')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public function slowQueries(int $limit = 50): array
    {
        return $this->query(['is_slow' => true], $limit);
    }

    public function cleanup(int $olderThanHours = 24): int
    {
        return $this->db()
            ->where('created_at', '<', now()->subHours($olderThanHours)->toDateTimeString())
            ->delete();
    }

    public function truncate(): void
    {
        $this->db()->truncate();
    }

    public function stats(): array
    {
        $db = $this->db();

        return [
            'total'           => (clone $db)->count(),
            'slow_queries'    => (clone $db)->where('is_slow', true)->count(),
            'avg_time_ms'     => round((float) (clone $db)->avg('execution_time_ms'), 2),
            'max_time_ms'     => round((float) (clone $db)->max('execution_time_ms'), 2),
            'by_type'         => (clone $db)->selectRaw('query_type, COUNT(*) as count')
                                    ->groupBy('query_type')
                                    ->pluck('count', 'query_type')
                                    ->toArray(),
        ];
    }

    // ─── internal ────────────────────────────────────────────

    protected function db()
    {
        if (! $this->tableEnsured) {
            $this->tableEnsured = true; // 先設旗標，避免 ensureTableExists 內部再次進入
            $this->ensureTableExists();
        }

        return DB::connection($this->connection)->table($this->table);
    }

    protected function ensureTableExists(): void
    {
        $schema = DB::connection($this->connection)->getSchemaBuilder();

        if ($schema->hasTable($this->table)) {
            return;
        }

        $schema->create($this->table, function ($table) {
            $table->id();
            $table->string('query_id')->unique();
            $table->string('connection_name')->default('mysql');
            $table->text('sql');
            $table->text('bindings')->nullable();
            $table->float('execution_time_ms');
            $table->text('normalized_sql');
            $table->text('stack_trace')->nullable();
            $table->unsignedTinyInteger('complexity_score')->default(0);
            $table->string('complexity_severity')->default('low');
            $table->text('warnings')->nullable();
            $table->text('suggestions')->nullable();
            $table->string('query_type')->default('unknown');
            $table->string('primary_table')->nullable();
            $table->boolean('is_slow')->default(false);
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index('executed_at');
            $table->index('execution_time_ms');
            $table->index('is_slow');
            $table->index('query_type');
        });
    }
}