<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Contracts;

interface FencedLocalCache extends LocalCacheInterface
{
    public function fence(string $key): int;

    public function putFenced(string $key, mixed $value, int $fence, int $ttl = 0): bool;
}
