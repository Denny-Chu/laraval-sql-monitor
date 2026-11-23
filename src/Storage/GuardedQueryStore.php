<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Storage;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use LaravelSqlMonitor\Lifecycle\QueryRecord;
use LaravelSqlMonitor\Storage\Contracts\QueryStoreInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * 包住實際的持久化存儲：寫入監控紀錄失敗只記 warning，不讓例外打斷被監控的查詢或請求。
 * 讀取類方法（Dashboard、指令用）照常拋出，讓使用者看得到存儲本身的錯誤。
 */
final class GuardedQueryStore implements QueryStoreInterface
{
    use DetectsConcurrencyErrors;

    /** 寫進 log 的例外訊息上限（位元組），避免把整條 SQL 與 bindings 灌進 log。 */
    public const MAX_LOGGED_MESSAGE_BYTES = 1000;

    public function __construct(
        private readonly QueryStoreInterface $inner,
        private readonly LoggerInterface $logger,
        private readonly ConnectionResolverInterface $connections,
    ) {}

    public function persist(QueryRecord $record): void
    {
        try {
            $this->inner->persist($record);
        } catch (Throwable $e) {
            if ($this->rolledBackOpenTransaction($e)) {
                throw $e;
            }

            $this->report($record, $e);
        }
    }

    public function persistBatch(array $records): void
    {
        foreach ($records as $record) {
            $this->persist($record);
        }
    }

    public function query(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return $this->inner->query($filters, $limit, $offset);
    }

    public function slowQueries(int $limit = 50): array
    {
        return $this->inner->slowQueries($limit);
    }

    public function cleanup(int $olderThanHours = 24): int
    {
        return $this->inner->cleanup($olderThanHours);
    }

    public function truncate(): void
    {
        $this->inner->truncate();
    }

    public function stats(): array
    {
        return $this->inner->stats();
    }

    /**
     * 存儲與應用程式共用連線、且正在交易中時，deadlock 之類的錯誤會讓資料庫回滾整筆交易；
     * 這種錯誤必須交還應用程式處理，否則它會在已回滾的狀態下繼續執行並提交部分資料。
     */
    private function rolledBackOpenTransaction(Throwable $e): bool
    {
        return $e instanceof QueryException
            && $this->causedByConcurrencyError($e)
            && $this->connections->connection($e->getConnectionName())->transactionLevel() > 0;
    }

    private function report(QueryRecord $record, Throwable $e): void
    {
        $context = [
            'query_id'  => $record->id,
            'exception' => $e::class,
            'message'   => mb_strcut($e->getMessage(), 0, self::MAX_LOGGED_MESSAGE_BYTES, 'UTF-8'),
        ];

        try {
            $this->logger->warning('sql-monitor: failed to persist query log, record skipped.', $context);
        } catch (Throwable) {
            error_log('sql-monitor: failed to persist query log, record skipped. ' . json_encode($context, JSON_INVALID_UTF8_SUBSTITUTE));
        }
    }
}
