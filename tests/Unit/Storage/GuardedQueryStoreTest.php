<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Tests\Unit\Storage;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;
use LaravelSqlMonitor\Lifecycle\QueryRecord;
use LaravelSqlMonitor\Storage\Contracts\QueryStoreInterface;
use LaravelSqlMonitor\Storage\GuardedQueryStore;
use PHPUnit\Framework\TestCase;
use PDOException;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

class GuardedQueryStoreTest extends TestCase
{
    public function testPersistFailureIsLoggedInsteadOfThrown(): void
    {
        $logger = $this->recordingLogger();
        $store = new GuardedQueryStore($this->failingStore(str_repeat('x', 5000)), $logger, $this->connections(0));

        $store->persist($this->record('q_1'));

        $this->assertCount(1, $logger->records);
        $this->assertSame('warning', $logger->records[0]['level']);
        $this->assertSame('q_1', $logger->records[0]['context']['query_id']);
        $this->assertSame(RuntimeException::class, $logger->records[0]['context']['exception']);
        $this->assertLessThanOrEqual(GuardedQueryStore::MAX_LOGGED_MESSAGE_BYTES, strlen($logger->records[0]['context']['message']));
    }

    public function testPersistBatchKeepsGoingAfterOneFailure(): void
    {
        $logger = $this->recordingLogger();
        $inner = $this->failingStore('boom', failOn: 'q_1');
        $store = new GuardedQueryStore($inner, $logger, $this->connections(0));

        $store->persistBatch([$this->record('q_1'), $this->record('q_2')]);

        $this->assertSame(['q_2'], $inner->persisted);
        $this->assertCount(1, $logger->records);
    }

    public function testPersistSuccessDelegatesWithoutLogging(): void
    {
        $logger = $this->recordingLogger();
        $inner = $this->failingStore('boom', failOn: 'never');
        $store = new GuardedQueryStore($inner, $logger, $this->connections(0));

        $store->persist($this->record('q_1'));

        $this->assertSame(['q_1'], $inner->persisted);
        $this->assertSame([], $logger->records);
    }

    public function testReadFailuresStillPropagate(): void
    {
        $store = new GuardedQueryStore($this->failingStore('read failed'), $this->recordingLogger(), $this->connections(0));

        $this->expectException(RuntimeException::class);

        $store->stats();
    }

    public function testThrowingLoggerDoesNotEscape(): void
    {
        $logger = new class extends AbstractLogger
        {
            public function log($level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('log directory is not writable');
            }
        };
        $store = new GuardedQueryStore($this->failingStore('boom'), $logger, $this->connections(0));

        $previous = ini_set('error_log', '/dev/null');
        try {
            $store->persist($this->record('q_1'));
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $this->addToAssertionCount(1);
    }

    public function testDeadlockInsideOpenTransactionIsRethrown(): void
    {
        $deadlock = $this->deadlock();
        $store = new GuardedQueryStore($this->failingStore('', error: $deadlock), $this->recordingLogger(), $this->connections(1));

        $this->expectExceptionObject($deadlock);

        $store->persist($this->record('q_1'));
    }

    public function testDeadlockOutsideTransactionIsLoggedOnly(): void
    {
        $logger = $this->recordingLogger();
        $store = new GuardedQueryStore($this->failingStore('', error: $this->deadlock()), $logger, $this->connections(0));

        $store->persist($this->record('q_1'));

        $this->assertSame(QueryException::class, $logger->records[0]['context']['exception']);
    }

    private function deadlock(): QueryException
    {
        return new QueryException(
            'mysql',
            'insert into `sql_monitor_logs` (`query_id`) values (?)',
            ['q_1'],
            new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'),
        );
    }

    private function connections(int $transactionLevel): ConnectionResolverInterface
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('transactionLevel')->willReturn($transactionLevel);

        $resolver = $this->createStub(ConnectionResolverInterface::class);
        $resolver->method('connection')->willReturn($connection);

        return $resolver;
    }

    private function record(string $id): QueryRecord
    {
        return new QueryRecord(
            id: $id,
            sql: 'select 1',
            bindings: [],
            executionTimeMs: 150.0,
            connection: 'mysql',
            timestamp: 0.0,
            normalizedSql: 'select 1',
        );
    }

    private function failingStore(string $message, string $failOn = '*', ?\Throwable $error = null): QueryStoreInterface
    {
        return new class($message, $failOn, $error) implements QueryStoreInterface
        {
            /** @var list<string> */
            public array $persisted = [];

            public function __construct(private string $message, private string $failOn, private ?\Throwable $error) {}

            public function persist(QueryRecord $record): void
            {
                if ($this->failOn === '*' || $this->failOn === $record->id) {
                    throw $this->error ?? new RuntimeException($this->message);
                }

                $this->persisted[] = $record->id;
            }

            public function persistBatch(array $records): void
            {
                foreach ($records as $record) {
                    $this->persist($record);
                }
            }

            public function query(array $filters = [], int $limit = 50, int $offset = 0): array
            {
                throw new RuntimeException($this->message);
            }

            public function slowQueries(int $limit = 50): array
            {
                throw new RuntimeException($this->message);
            }

            public function cleanup(int $olderThanHours = 24): int
            {
                throw new RuntimeException($this->message);
            }

            public function truncate(): void
            {
                throw new RuntimeException($this->message);
            }

            public function stats(): array
            {
                throw new RuntimeException($this->message);
            }
        };
    }

    private function recordingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger
        {
            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }
}
