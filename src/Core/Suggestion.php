<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Core;

/**
 * 單一優化建議。
 */
class Suggestion
{
    public function __construct(
        public readonly string  $id,
        public readonly string  $title,
        public readonly string  $message,
        public readonly string  $severity,       // info | warning | critical
        public readonly ?string $example = null,
        public readonly ?string $docUrl  = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'id'       => $this->id,
            'title'    => $this->title,
            'message'  => $this->message,
            'severity' => $this->severity,
            'example'  => $this->example,
            'doc_url'  => $this->docUrl,
        ]);
    }
}
