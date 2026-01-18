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
        $row = $this->table->get($this->keyPrefix.$key);

        if ($row === false) {
            return null;
        }

        if ($row['expires_at'] > 0 && $row['expires_at'] < time()) {
            $this->table->del($this->keyPrefix.$key);

            return null;
        }

        return unserialize($row['value']);
    }

    public function put(string $key, mixed $value, int $ttl = 0): bool
    {
        $effectiveTtl = $ttl > 0 ? $ttl : $this->defaultTtl;
        $expiresAt = $effectiveTtl > 0 ? time() + $effectiveTtl : 0;

        return $this->table->set($this->keyPrefix.$key, [
            'value' => serialize($value),
            'expires_at' => $expiresAt,
        ]);
    }

    public function forget(string $key): bool
    {
        return $this->table->del($this->keyPrefix.$key);
    }

    public function flush(): bool
    {
        $keysToDelete = [];

        foreach ($this->table as $key => $row) {
            if (str_starts_with($key, $this->keyPrefix)) {
                $keysToDelete[] = $key;
            }
        }

        foreach ($keysToDelete as $key) {
            $this->table->del($key);
        }

        return true;
    }

    protected function ensureSwooleAvailable(): void
    {
        if (! extension_loaded('swoole') && ! extension_loaded('openswoole')) {
            throw new LocalCacheException('Swoole or OpenSwoole extension is required for SwooleTableCache.');
        }
    }
}
