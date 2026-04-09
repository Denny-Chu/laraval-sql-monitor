<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Core;

use function array_filter;
use function array_slice;
use function array_values;
use function str_contains;

/**
 * 收集並過濾 PHP 調用棧，保留應用程式碼的 frame。
 */
class StackTraceCollector
{
    /** 需排除的目錄前綴 */
    private const EXCLUDE_PATTERNS = [
        '/vendor/laravel/framework/',
        '/vendor/phpmyadmin/',
        '/vendor/composer/',
        'LaravelSqlMonitor\\',
    ];

    public function __construct(
        protected int  $limit          = 20,
        protected bool $excludeVendors = true,
    ) {}

    public static function fromConfig(array $config): self
    {
        return new self(
            limit:          $config['limit']           ?? 20,
            excludeVendors: $config['exclude_vendors'] ?? true,
        );
    }

    /**
     * 收集當前呼叫棧。
     *
     * @return array<int, array{file: string, line: int, class?: string, function: string}>
     */
    public function collect(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 50);

        // 過濾自身與框架內部 frame
        $filtered = array_filter($trace, function (array $frame): bool {
            $file  = $frame['file']  ?? '';
            $class = $frame['class'] ?? '';

            // 永遠排除本套件自身
            if (str_contains($class, 'LaravelSqlMonitor\\')) {
                return false;
            }

            if (! $this->excludeVendors) {
                return true;
            }

            foreach (self::EXCLUDE_PATTERNS as $pattern) {
                if (str_contains($file, $pattern) || str_contains($class, $pattern)) {
                    return false;
                }
            }

            return true;
        });

        $frames = array_slice(array_values($filtered), 0, $this->limit);

        return array_map(function (array $frame): array {
            return [
                'file'     => $frame['file']     ?? 'unknown',
                'line'     => $frame['line']     ?? 0,
                'class'    => $frame['class']    ?? null,
                'function' => $frame['function'] ?? 'unknown',
            ];
        }, $frames);
    }

    /**
     * 產生 IDE 可點擊的連結。
     */
    public function createIdeLink(string $file, int $line, string $ide = 'vscode'): string
    {
        return match ($ide) {
            'phpstorm' => "phpstorm://open?file={$file}&line={$line}",
            'sublime'  => "subl://open?url=file://{$file}&line={$line}",
            default    => "vscode://file/{$file}:{$line}",
        };
    }
}
