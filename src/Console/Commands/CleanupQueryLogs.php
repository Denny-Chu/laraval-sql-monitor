<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Console\Commands;

use Illuminate\Console\Command;
use LaravelSqlMonitor\Storage\Contracts\QueryStoreInterface;

class CleanupQueryLogs extends Command
{
    protected $signature   = 'sql-monitor:cleanup {--hours=24 : 清除超過幾小時的記錄}';
    protected $description = '清除過期的 SQL Monitor 日誌記錄';

    public function handle(QueryStoreInterface $store): int
    {
        $hours   = (int) $this->option('hours');
        $deleted = $store->cleanup($hours);

        $this->info("已清除 {$deleted} 筆超過 {$hours} 小時的記錄。");

        return self::SUCCESS;
    }
}
