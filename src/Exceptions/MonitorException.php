<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Exceptions;

use RuntimeException;

class MonitorException extends RuntimeException
{
    public static function parsingFailed(string $sql, string $reason): self
    {
        return new self("SQL parsing failed for: [{$sql}] — Reason: {$reason}");
    }

    public static function storageError(string $reason): self
    {
        return new self("Storage error: {$reason}");
    }
}
