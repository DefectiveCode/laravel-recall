<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Cache;

use APCuIterator;
use DefectiveCode\Recall\Contracts\LocalCacheInterface;
use DefectiveCode\Recall\Exceptions\LocalCacheException;

class LocalCache implements LocalCacheInterface
{
    protected string $keyPrefix;

    protected int $defaultTtl;

    /**
     * @param  array{key_prefix?: string, default_ttl?: int}  $config
     */
    public function __construct(array $config = [])
    {
        $this->keyPrefix = $config['key_prefix'] ?? 'recall:';
        $this->defaultTtl = $config['default_ttl'] ?? 3600;

        $this->ensureApcuAvailable();
    }

    public function get(string $key): mixed
    {
        $success = false;
        $value = apcu_fetch($this->keyPrefix.$key, $success);

        if (! $success) {
            return null;
        }

        return unserialize($value);
    }

    public function put(string $key, mixed $value, int $ttl = 0): bool
    {
        $effectiveTtl = $ttl > 0 ? $ttl : $this->defaultTtl;

        return apcu_store($this->keyPrefix.$key, serialize($value), $effectiveTtl);
    }

    public function forget(string $key): bool
    {
        return apcu_delete($this->keyPrefix.$key);
    }

    public function flush(): bool
    {
        $pattern = '/^'.preg_quote($this->keyPrefix, '/').'/';
        $iterator = new APCuIterator($pattern, APC_ITER_KEY);

        $keys = [];
        foreach ($iterator as $item) {
            $keys[] = $item['key'];
        }

        if (empty($keys)) {
            return true;
        }

        return apcu_delete($keys) !== false;
    }

    protected function ensureApcuAvailable(): void
    {
        if (! extension_loaded('apcu')) {
            throw new LocalCacheException('APCu extension is not loaded. Please install and enable the APCu extension.');
        }

        if (! apcu_enabled()) {
            throw new LocalCacheException('APCu is not enabled. Please enable APCu in your PHP configuration (apc.enable_cli=1 for CLI).');
        }
    }
}
