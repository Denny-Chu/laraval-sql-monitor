<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Console\Commands;

use Illuminate\Console\Command;
use LaravelSqlMonitor\Storage\Contracts\QueryStoreInterface;

class ExportQueryLogs extends Command
{
    protected $signature   = 'sql-monitor:export
                              {--format=json : 輸出格式 (json|csv)}
                              {--output=     : 輸出檔案路徑}
                              {--slow        : 僅匯出慢查詢}
                              {--limit=500   : 最大匯出筆數}';

    protected $description = '匯出 SQL Monitor 日誌到檔案';

    public function handle(QueryStoreInterface $store): int
    {
        $format = $this->option('format');
        $output = $this->option('output') ?: storage_path("sql-monitor-export.{$format}");
        $limit  = (int) $this->option('limit');

        $data = $this->option('slow')
            ? $store->slowQueries($limit)
            : $store->query([], $limit);

        if (empty($data)) {
            $this->warn('沒有找到可匯出的記錄。');
            return self::SUCCESS;
        }

        match ($format) {
            'csv'   => $this->exportCsv($data, $output),
            default => $this->exportJson($data, $output),
        };

        $this->info("已匯出 " . count($data) . " 筆記錄到 {$output}");

        return self::SUCCESS;
    }

    private function exportJson(array $data, string $path): void
    {
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function exportCsv(array $data, string $path): void
    {
        $fp = fopen($path, 'w');

        if (! empty($data)) {
            // 寫入表頭
            $first = (array) $data[0];
            fputcsv($fp, array_keys($first));

            // 寫入資料
            foreach ($data as $row) {
                $row = (array) $row;
                // 將陣列/物件轉為 JSON 字串
                $row = array_map(function ($val) {
                    return is_array($val) || is_object($val) ? json_encode($val) : $val;
                }, $row);
                fputcsv($fp, $row);
            }
        }

        fclose($fp);
    }
}
