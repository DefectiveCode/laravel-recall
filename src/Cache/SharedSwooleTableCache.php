<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Cache;

use Swoole\Table;
use DefectiveCode\Recall\Contracts\FencedLocalCache;

class SharedSwooleTableCache implements FencedLocalCache
{
    protected string $keyPrefix;

    protected int $defaultTtl;

    protected int $valueSize;

    protected int $stripes;

    /**
     * @param  array{key_prefix?: string, default_ttl?: int, value_size?: int, epoch_stripes?: int}  $config
     */
    public function __construct(
        protected Table $table,
        protected Table $epochs,
        array $config = [],
    ) {
        $this->keyPrefix = $config['key_prefix'] ?? 'recall:';
        $this->defaultTtl = $config['default_ttl'] ?? 3600;
        $this->valueSize = $config['value_size'] ?? 8192;
        $this->stripes = max(1, $config['epoch_stripes'] ?? 1024);
    }

    public function get(string $key): mixed
    {
        $hashedKey = $this->hashKey($key);
        $row = $this->table->get($hashedKey);

        if ($row === false) {
            return null;
        }

        if ($row['expires_at'] > 0 && $row['expires_at'] < time()) {
            $this->table->del($hashedKey);

            return null;
        }

        return unserialize($row['value']);
    }

    public function put(string $key, mixed $value, int $ttl = 0): bool
    {
        $hashedKey = $this->hashKey($key);
        $serialized = serialize($value);

        if (strlen($serialized) > $this->valueSize) {
            $this->table->del($hashedKey);

            return false;
        }

        return $this->table->set($hashedKey, [
            'value' => $serialized,
            'expires_at' => $this->expiresAt($ttl),
        ]);
    }

    public function fence(string $key): int
    {
        return $this->epoch($this->stripe($this->hashKey($key)));
    }

    public function putFenced(string $key, mixed $value, int $fence, int $ttl = 0): bool
    {
        if (! $this->put($key, $value, $ttl)) {
            return false;
        }

        if ($this->fence($key) === $fence) {
            return true;
        }

        $this->table->del($this->hashKey($key));

        return false;
    }

    public function forget(string $key): bool
    {
        $hashedKey = $this->hashKey($key);

        $this->epochs->incr($this->stripe($hashedKey), 'epoch');

        return $this->table->del($hashedKey);
    }

    public function flush(): bool
    {
        for ($stripe = 0; $stripe < $this->stripes; $stripe++) {
            $this->epochs->incr((string) $stripe, 'epoch');
        }

        $keysToDelete = [];

        foreach ($this->table as $key => $row) {
            $keysToDelete[] = $key;
        }

        foreach ($keysToDelete as $key) {
            $this->table->del($key);
        }

        return true;
    }

    protected function epoch(string $stripe): int
    {
        $epoch = $this->epochs->get($stripe, 'epoch');

        return $epoch === false ? 0 : (int) $epoch;
    }

    protected function stripe(string $hashedKey): string
    {
        return (string) (crc32($hashedKey) % $this->stripes);
    }

    protected function expiresAt(int $ttl): int
    {
        $effectiveTtl = $ttl > 0 ? $ttl : $this->defaultTtl;

        return $effectiveTtl > 0 ? time() + $effectiveTtl : 0;
    }

    protected function hashKey(string $key): string
    {
        return md5($this->keyPrefix.$key);
    }
}
