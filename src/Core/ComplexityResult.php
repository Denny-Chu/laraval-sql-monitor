<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Core;

/**
 * ComplexityDetector 回傳的分析結果。
 */
class ComplexityResult
{
    public function __construct(
        /** 風險分數 0–100 */
        public readonly int $score,

        /** 嚴重等級：low | info | warning | critical */
        public readonly string $severity,

        /** 警告列表 [{id, message, severity}] */
        public readonly array $warnings,
    ) {}

    public function isCritical(): bool
    {
        return $this->severity === ComplexityDetector::SEVERITY_CRITICAL;
    }

    public function hasWarnings(): bool
    {
        return ! empty($this->warnings);
    }

    public function toArray(): array
    {
        return [
            'score'    => $this->score,
            'severity' => $this->severity,
            'warnings' => $this->warnings,
        ];
    }
}
