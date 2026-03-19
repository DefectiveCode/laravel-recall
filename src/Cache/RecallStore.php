<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Cache;

use Redis;
use RuntimeException;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Cache\LockProvider;
use DefectiveCode\Recall\RecallManager;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use DefectiveCode\Recall\Contracts\LocalCacheInterface;

class RecallStore implements Store, LockProvider
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
        protected LocalCacheInterface $localCache,
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

        $localValue = $this->localCache->get($redisKey);

        if ($localValue !== null) {
            return $localValue;
        }

        $pendingRequest = $this->manager->getTracker()->registerPendingRequest($redisKey);
        $value = $this->redisStore->get($key);

        if ($value !== null && $this->manager->getTracker()->completePendingRequest($pendingRequest)) {
            $this->localCache->put($redisKey, $value);
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
        return $this->redisStore->put($key, $value, $seconds);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function putMany(array $values, $seconds): bool
    {
        return $this->redisStore->putMany($values, $seconds);
    }

    public function increment($key, $value = 1): int|bool
    {
        return $this->redisStore->increment($key, $value);
    }

    public function decrement($key, $value = 1): int|bool
    {
        return $this->redisStore->decrement($key, $value);
    }

    public function forever($key, $value): bool
    {
        return $this->redisStore->forever($key, $value);
    }

    public function forget($key): bool
    {
        return $this->redisStore->forget($key);
    }

    public function flush(): bool
    {
        $this->localCache->flush();
        $this->manager->getTracker()->clearPendingRequests();

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

    protected function shouldCacheLocally(string $key): bool
    {
        if (! $this->enabled) {
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
