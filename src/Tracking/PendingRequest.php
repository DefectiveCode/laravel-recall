<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tracking;

final readonly class PendingRequest
{
    private float $timestamp;

    public function __construct(
        private string $key,
    ) {
        $this->timestamp = microtime(true);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }
}
