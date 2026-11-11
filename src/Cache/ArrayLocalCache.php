<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Cache;

use DefectiveCode\Recall\Contracts\LocalCacheInterface;

class ArrayLocalCache implements LocalCacheInterface
{
    protected int $defaultTtl;

    protected int $maxEntries;

    protected int $valueSize;

    /** @var array<string, array{0: string, 1: int}> */
    protected array $entries = [];

    /**
     * @param  array{default_ttl?: int, max_entries?: int, value_size?: int}  $config
     */
    public function __construct(array $config = [])
    {
        $this->defaultTtl = $config['default_ttl'] ?? 3600;
        $this->maxEntries = max(1, $config['max_entries'] ?? 1000);
        $this->valueSize = $config['value_size'] ?? 8192;
    }

    public function get(string $key): mixed
    {
        if (! isset($this->entries[$key])) {
            return null;
        }

        $entry = $this->entries[$key];
        unset($this->entries[$key]);

        if ($entry[1] <= time()) {
            return null;
        }

        $this->entries[$key] = $entry;

        return unserialize($entry[0]);
    }

    public function put(string $key, mixed $value, int $ttl = 0): bool
    {
        unset($this->entries[$key]);

        $serialized = serialize($value);

        if (strlen($serialized) > $this->valueSize) {
            return false;
        }

        while (count($this->entries) >= $this->maxEntries) {
            unset($this->entries[array_key_first($this->entries)]);
        }

        $this->entries[$key] = [$serialized, time() + ($ttl > 0 ? $ttl : $this->defaultTtl)];

        return true;
    }

    public function forget(string $key): bool
    {
        $existed = isset($this->entries[$key]);
        unset($this->entries[$key]);

        return $existed;
    }

    public function flush(): bool
    {
        $this->entries = [];

        return true;
    }
}
