<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Monitor;

use LaravelSqlMonitor\Lifecycle\RequestQueryManager;
use LaravelSqlMonitor\Lifecycle\N1QueryDetector;
use LaravelSqlMonitor\Lifecycle\DuplicateQueryDetector;

/**
 * 彙整所有分析指標，供 Dashboard 和 API 使用。
 */
class MetricsCollector
{
    public function __construct(
        protected RequestQueryManager   $manager,
        protected N1QueryDetector       $n1Detector,
        protected DuplicateQueryDetector $dupDetector,
        protected SlowQueryTracker      $slowTracker,
    ) {}

    /**
     * 產生完整的指標報告。
     */
    public function collect(): array
    {
        $queries    = $this->manager->all();
        $n1         = $this->n1Detector->detect($queries);
        $duplicates = $this->dupDetector->detect($queries);

        return [
            'summary'    => $this->manager->getStats(),
            'n1_patterns' => array_map(fn($p) => $p->toArray(), $n1),
            'duplicates'  => array_map(fn($d) => $d->toArray(), $duplicates),
            'slow_queries'=> array_map(fn($q) => $q->toArray(), $this->slowTracker->all()),
            'queries'     => array_map(fn($q) => $q->toArray(), $queries),
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
