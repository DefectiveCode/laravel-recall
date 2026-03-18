<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Cache;

use Swoole\Table;
use DefectiveCode\Recall\Contracts\LocalCacheInterface;
use DefectiveCode\Recall\Exceptions\LocalCacheException;

class SwooleTableCache implements LocalCacheInterface
{
    protected Table $table;

    protected string $keyPrefix;

    protected int $defaultTtl;

    /**
     * @param  array{key_prefix?: string, default_ttl?: int, table_size?: int, value_size?: int}  $config
     */
    public function __construct(array $config = [])
    {
        $this->ensureSwooleAvailable();

        $this->keyPrefix = $config['key_prefix'] ?? 'recall:';
        $this->defaultTtl = $config['default_ttl'] ?? 3600;

        $tableSize = $config['table_size'] ?? 65536;
        $valueSize = $config['value_size'] ?? 8192;

        $this->table = new Table($tableSize);
        $this->table->column('value', Table::TYPE_STRING, $valueSize);
        $this->table->column('expires_at', Table::TYPE_INT);
        $this->table->create();
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
        $effectiveTtl = $ttl > 0 ? $ttl : $this->defaultTtl;
        $expiresAt = $effectiveTtl > 0 ? time() + $effectiveTtl : 0;

        return $this->table->set($this->hashKey($key), [
            'value' => serialize($value),
            'expires_at' => $expiresAt,
        ]);
    }

    public function forget(string $key): bool
    {
        return $this->table->del($this->hashKey($key));
    }

    public function flush(): bool
    {
        $keysToDelete = [];

        foreach ($this->table as $key => $row) {
            $keysToDelete[] = $key;
        }

        foreach ($keysToDelete as $key) {
            $this->table->del($key);
        }

        return true;
    }

    protected function hashKey(string $key): string
    {
        return md5($this->keyPrefix.$key);
    }

    protected function ensureSwooleAvailable(): void
    {
        if (! extension_loaded('swoole') && ! extension_loaded('openswoole')) {
            throw new LocalCacheException('Swoole or OpenSwoole extension is required for SwooleTableCache.');
        }
    }
}
