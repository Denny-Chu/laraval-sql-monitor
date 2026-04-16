<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Storage;

use Illuminate\Support\Facades\Cache;
use LaravelSqlMonitor\Lifecycle\QueryRecord;

/**
 * Cache 層 — 儲存 info / low 等級的正常查詢。
 *
 * 使用 Laravel Cache Facade，支援 file / redis / memcached 等 driver。
 * 每筆記錄附帶 _stored_at timestamp，讀取時逐筆過濾過期項。
 *
 * 併發安全：開發環境通常單人使用，偶爾遺失一兩筆正常查詢可接受。
 */
class MemoryQueryStore
{
    protected const CACHE_KEY = 'sql_monitor:memory';

    public function __construct(
        protected int $ttlSeconds = 60,
        protected int $maxBuffer = 500,
    ) {}

    /**
     * 批次寫入查詢記錄（由 Middleware 在 request 結束時呼叫）。
     *
     * @param QueryRecord[] $records
     */
    public function pushBatch(array $records): void
    {
        if (empty($records)) {
            return;
        }

        $now    = time();
        $cutoff = $now - $this->ttlSeconds;

        // 讀取現有 buffer 並過濾過期項
        $buffer = Cache::get(self::CACHE_KEY, []);
        $buffer = array_filter($buffer, fn(array $q) => ($q['_stored_at'] ?? 0) > $cutoff);

        // 加入新記錄
        foreach ($records as $record) {
            $entry = $record->toArray();
            $entry['_stored_at'] = $now;
            $buffer[] = $entry;
        }

        // 裁切到 maxBuffer
        if (count($buffer) > $this->maxBuffer) {
            $buffer = array_slice($buffer, -$this->maxBuffer);
        }

        // 存入 Cache（TTL 設為 2 倍，確保 buffer 在有持續寫入時不過早消失）
        Cache::put(self::CACHE_KEY, array_values($buffer), $this->ttlSeconds * 2);
    }

    /**
     * 取得所有未過期的記憶體查詢。
     */
    public function all(): array
    {
        $cutoff = time() - $this->ttlSeconds;
        $buffer = Cache::get(self::CACHE_KEY, []);

        return array_values(
            array_filter($buffer, fn(array $q) => ($q['_stored_at'] ?? 0) > $cutoff)
        );
    }

    /**
     * 清空 Cache 層。
     */
    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
