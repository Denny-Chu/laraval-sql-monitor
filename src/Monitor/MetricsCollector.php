<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Monitor;

use LaravelSqlMonitor\Lifecycle\RequestQueryManager;
use LaravelSqlMonitor\Lifecycle\N1QueryDetector;
use LaravelSqlMonitor\Lifecycle\DuplicateQueryDetector;
use LaravelSqlMonitor\Storage\Contracts\QueryStoreInterface;
use LaravelSqlMonitor\Storage\MemoryQueryStore;

/**
 * 彙整所有分析指標，供 Dashboard 和 API 使用。
 *
 * 資料來源：
 *   - DB 層（QueryStoreInterface）：歷史問題查詢（慢查詢、complex、N+1、duplicate）
 *   - Cache 層（MemoryQueryStore）：近期正常查詢（info / low，TTL 過期自動消失）
 *   - 記憶體層（RequestQueryManager）：當次 request 的即時統計
 */
class MetricsCollector
{
    public function __construct(
        protected RequestQueryManager    $manager,
        protected N1QueryDetector        $n1Detector,
        protected DuplicateQueryDetector $dupDetector,
        protected SlowQueryTracker       $slowTracker,
        protected ?QueryStoreInterface   $store = null,
        protected ?MemoryQueryStore      $memoryStore = null,
    ) {}

    /**
     * 產生完整的指標報告（供 Dashboard SSR 初始渲染）。
     */
    public function collect(): array
    {
        $queries = $this->manager->all();
        $n1      = $this->n1Detector->detect($queries);
        $dups    = $this->dupDetector->detect($queries);

        return [
            'summary'         => $this->manager->getStats(),
            'n1_patterns'     => array_map(fn($p) => $p->toArray(), $n1),
            'duplicates'      => array_map(fn($d) => $d->toArray(), $dups),
            'slow_queries'    => $this->store ? $this->store->slowQueries(50) : [],
            'queries'         => $this->store ? $this->store->query([], 100) : [],
            'memory_queries'  => $this->memoryStore ? $this->memoryStore->all() : [],
            'stats'           => $this->store ? $this->store->stats() : [],
        ];
    }

    /**
     * Polling 專用：回傳 DB 層 + Cache 層合併資料。
     */
    public function poll(): array
    {
        return [
            'db_queries'      => $this->store ? $this->store->query([], 100) : [],
            'memory_queries'  => $this->memoryStore ? $this->memoryStore->all() : [],
            'stats'           => $this->store ? $this->store->stats() : [],
        ];
    }

    /**
     * 快速摘要（適合放在 Response Header 中）。
     */
    public function quickSummary(): array
    {
        return $this->manager->getStats();
    }
}
