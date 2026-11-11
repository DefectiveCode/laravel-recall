<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Cache;

use Redis;
use Closure;
use RuntimeException;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Store;
use DefectiveCode\Recall\RecallManager;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Redis\Connections\PredisConnection;
use DefectiveCode\Recall\Contracts\FencedLocalCache;
use Illuminate\Redis\Connections\PhpRedisConnection;
use DefectiveCode\Recall\Contracts\LocalCacheInterface;

class RecallStore implements LockProvider, Store
{
    /** @var array<string> */
    protected array $cachePrefixes;

    protected bool $enabled;

    protected RedisStore $redisStore;

    /**
     * @param  array{enabled?: bool, cache_prefixes?: array<string>}  $config
     */
    public function __construct(
        Store $redisStore,
        protected LocalCacheInterface|Closure $localCache,
        protected RecallManager $manager,
        array $config = [],
    ) {
        if (! $redisStore instanceof RedisStore) {
            throw new RuntimeException('RecallStore requires a RedisStore instance');
        }

        $this->redisStore = $redisStore;
        $this->enabled = $config['enabled'] ?? true;
        $this->cachePrefixes = $config['cache_prefixes'] ?? [];
    }

    public function get($key): mixed
    {
        if (! $this->shouldCacheLocally($key)) {
            return $this->redisStore->get($key);
        }

        $redisKey = $this->getRedisKey($key);

        $localCache = $this->local();
        $localValue = $localCache->get($redisKey);

        if ($localValue !== null && $this->manager->isQueueMode()) {
            $this->manager->processInvalidations();
            $localValue = $localCache->get($redisKey);
        }

        if ($localValue !== null) {
            return $localValue;
        }

        $fence = $localCache instanceof FencedLocalCache ? $localCache->fence($redisKey) : null;

        $pendingRequest = $this->manager->getTracker()->registerPendingRequest($redisKey);
        $value = $this->redisStore->get($key);

        if ($value !== null && $this->manager->getTracker()->completePendingRequest($pendingRequest)) {
            if ($fence !== null && $localCache instanceof FencedLocalCache) {
                $localCache->putFenced($redisKey, $value, $fence);
            } else {
                $localCache->put($redisKey, $value);
            }
        }

        return $value;
    }

    /**
     * @param  array<string>  $keys
     * @return array<string, mixed>
     */
    public function many(array $keys): array
    {
        $results = [];

        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }

        return $results;
    }

    public function put($key, $value, $seconds): bool
    {
        try {
            return $this->redisStore->put($key, $value, $seconds);
        } finally {
            $this->forgetLocally($key);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function putMany(array $values, $seconds): bool
    {
        try {
            return $this->redisStore->putMany($values, $seconds);
        } finally {
            foreach (array_keys($values) as $key) {
                $this->forgetLocally((string) $key);
            }
        }
    }

    public function increment($key, $value = 1): int|bool
    {
        try {
            return $this->redisStore->increment($key, $value);
        } finally {
            $this->forgetLocally($key);
        }
    }

    public function decrement($key, $value = 1): int|bool
    {
        try {
            return $this->redisStore->decrement($key, $value);
        } finally {
            $this->forgetLocally($key);
        }
    }

    public function forever($key, $value): bool
    {
        try {
            return $this->redisStore->forever($key, $value);
        } finally {
            $this->forgetLocally($key);
        }
    }

    public function touch($key, $seconds): bool
    {
        $this->forgetLocally($key);

        return (bool) $this->redisStore->connection()->expire(
            $this->redisStore->getPrefix().$key,
            (int) max(1, $seconds),
        );
    }

    public function forget($key): bool
    {
        try {
            return $this->redisStore->forget($key);
        } finally {
            $this->forgetLocally($key);
        }
    }

    public function flush(): bool
    {
        if ($this->enabled && $this->manager->isActive()) {
            $this->local()->flush();
            $this->manager->getTracker()->clearPendingRequests();
        }

        return $this->redisStore->flush();
    }

    public function getPrefix(): string
    {
        return $this->redisStore->getPrefix();
    }

    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        return $this->redisStore->lock($name, $seconds, $owner);
    }

    public function restoreLock($name, $owner): Lock
    {
        return $this->redisStore->restoreLock($name, $owner);
    }

    protected function forgetLocally(string $key): void
    {
        if ($this->shouldCacheLocally($key)) {
            $this->local()->forget($this->getRedisKey($key));
        }
    }

    protected function local(): LocalCacheInterface
    {
        return $this->localCache instanceof Closure ? ($this->localCache)() : $this->localCache;
    }

    protected function shouldCacheLocally(string $key): bool
    {
        if (! $this->enabled || ! $this->manager->isActive()) {
            return false;
        }

        if (empty($this->cachePrefixes)) {
            return true;
        }

        foreach ($this->cachePrefixes as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    protected function getRedisKey(string $key): string
    {
        return $this->getConnectionPrefix().$this->redisStore->getPrefix().$key;
    }

    protected function getConnectionPrefix(): string
    {
        $connection = $this->redisStore->connection();

        return match (true) {
            $connection instanceof PhpRedisConnection => $connection->client()->getOption(Redis::OPT_PREFIX) ?: '',
            $connection instanceof PredisConnection => $connection->getOptions()->prefix ?: '',
            default => '',
        };
    }
}
