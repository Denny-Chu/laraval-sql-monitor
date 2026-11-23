<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Storage;

/**
 * 讓寫入 TEXT 欄位的字串不超過欄位的位元組上限。
 */
final class TextColumn
{
    /** MySQL TEXT 欄位上限（位元組）。 */
    public const MAX_BYTES = 65535;

    /**
     * 超過上限時截斷（不切斷多位元組字元）並在尾端註明原始長度；上限為 null 代表欄位沒有長度限制。
     */
    public static function fit(?string $value, ?int $maxBytes): ?string
    {
        if ($value === null || $maxBytes === null || strlen($value) <= $maxBytes) {
            return $value;
        }

        $marker = '…[sql-monitor truncated: ' . strlen($value) . ' bytes]';

        return mb_strcut($value, 0, $maxBytes - strlen($marker), 'UTF-8') . $marker;
    }
}
