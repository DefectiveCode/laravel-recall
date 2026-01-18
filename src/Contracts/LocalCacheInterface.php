<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Contracts;

interface LocalCacheInterface
{
    public function get(string $key): mixed;

    public function put(string $key, mixed $value, int $ttl = 0): bool;

    public function forget(string $key): bool;

    public function flush(): bool;
}
