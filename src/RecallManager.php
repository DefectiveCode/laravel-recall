<?php

declare(strict_types=1);

namespace DefectiveCode\Recall;

use Redis;
use RuntimeException;
use DefectiveCode\Recall\Cache\LocalCache;
use DefectiveCode\Recall\Cache\RecallStore;
use DefectiveCode\Recall\Cache\SwooleTableCache;
use DefectiveCode\Recall\Tracking\ClientTracker;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use DefectiveCode\Recall\Redis\InvalidationSubscriber;
use DefectiveCode\Recall\Contracts\LocalCacheInterface;

class RecallManager
{
    protected ?InvalidationSubscriber $subscriber = null;

    protected ?LocalCacheInterface $localCache = null;

    protected ?ClientTracker $tracker = null;

    protected ?RecallStore $store = null;

    protected bool $trackingEnabled = false;

    public function __construct(
        protected Application $app,
    ) {}

    public function store(): RecallStore
    {
        if ($this->store !== null) {
            return $this->store;
        }

        $redisStoreName = $this->app['config']['recall.redis_store'] ?? 'redis';
        $redisStore = $this->app['cache']->store($redisStoreName)->getStore();

        $this->store = new RecallStore(
            redisStore: $redisStore,
            localCache: $this->getLocalCache(),
            manager: $this,
            config: [
                'enabled' => $this->app['config']['recall.enabled'] ?? true,
                'cache_prefixes' => $this->app['config']['recall.cache_prefixes'] ?? [],
            ],
        );

        return $this->store;
    }

    public function getSubscriber(): InvalidationSubscriber
    {
        return $this->subscriber ??= new InvalidationSubscriber($this->getRedisConnectionConfig());
    }

    public function getLocalCache(): LocalCacheInterface
    {
        if ($this->localCache !== null) {
            return $this->localCache;
        }

        $driver = $this->app['config']['recall.local_cache.driver'] ?? 'apcu';
        $config = $this->app['config']['recall.local_cache'] ?? [];

        return $this->localCache = match ($driver) {
            'swoole' => new SwooleTableCache($config),
            default => new LocalCache($config),
        };
    }

    public function getTracker(): ClientTracker
    {
        return $this->tracker ??= new ClientTracker(
            $this->getSubscriber(),
            $this->getLocalCache(),
        );
    }

    public function enableTracking(): void
    {
        if ($this->trackingEnabled) {
            return;
        }

        $subscriber = $this->getSubscriber();

        if (! $subscriber->isConnected()) {
            $subscriber->connect();
            $subscriber->subscribe();
        }

        $this->enableClientTracking($subscriber->getConnectionId());
        $this->trackingEnabled = true;
    }

    public function processInvalidations(): void
    {
        if (! $this->trackingEnabled) {
            $this->enableTracking();
        }

        $this->getTracker()->processInvalidations();
    }

    public function flushLocalCache(): void
    {
        $this->getLocalCache()->flush();
    }

    public function disconnect(): void
    {
        if ($this->subscriber !== null) {
            $this->subscriber->close();
        }

        $this->trackingEnabled = false;
    }

    public function isConnected(): bool
    {
        return $this->subscriber !== null && $this->subscriber->isConnected();
    }

    /**
     * @return array{host: string, port: int, password?: string|null, username?: string|null, database?: int, timeout?: float}
     */
    protected function getRedisConnectionConfig(): array
    {
        $redisStoreName = $this->app['config']['recall.redis_store'] ?? 'redis';
        $connectionName = $this->app['config']["cache.stores.{$redisStoreName}.connection"] ?? 'default';
        $redisConfig = $this->app['config']["database.redis.{$connectionName}"] ?? [];

        return [
            'host' => $redisConfig['host'] ?? '127.0.0.1',
            'port' => (int) ($redisConfig['port'] ?? 6379),
            'password' => $redisConfig['password'] ?? null,
            'username' => $redisConfig['username'] ?? null,
            'database' => (int) ($redisConfig['database'] ?? 0),
            'timeout' => (float) ($redisConfig['read_timeout'] ?? $redisConfig['timeout'] ?? 5.0),
        ];
    }

    protected function enableClientTracking(int $redirectId): void
    {
        $redisStoreName = $this->app['config']['recall.redis_store'] ?? 'redis';
        $connectionName = $this->app['config']["cache.stores.{$redisStoreName}.connection"] ?? 'default';

        $connection = $this->app['redis']->connection($connectionName);
        $client = $connection->client();

        $cachePrefixes = $this->app['config']['recall.cache_prefixes'] ?? [];
        $args = $this->buildTrackingArgs($redirectId, $cachePrefixes, $connection);

        $result = $client instanceof Redis
            ? $client->rawCommand(...$args)
            : $client->executeRaw($args);

        if ($result === false) {
            throw new RuntimeException('Failed to enable CLIENT TRACKING');
        }
    }

    /**
     * @param  array<string>  $cachePrefixes
     * @return array<string>
     */
    protected function buildTrackingArgs(int $redirectId, array $cachePrefixes, mixed $connection): array
    {
        $args = ['CLIENT', 'TRACKING', 'ON', 'REDIRECT', (string) $redirectId];

        if (empty($cachePrefixes)) {
            return $args;
        }

        $args[] = 'BCAST';

        $fullPrefix = $this->getFullKeyPrefix($connection);

        foreach ($cachePrefixes as $prefix) {
            $args[] = 'PREFIX';
            $args[] = $fullPrefix.$prefix;
        }

        return $args;
    }

    protected function getFullKeyPrefix(mixed $connection): string
    {
        $connectionPrefix = match (true) {
            $connection instanceof PhpRedisConnection => $connection->client()->getOption(Redis::OPT_PREFIX) ?: '',
            $connection instanceof PredisConnection => $connection->getOptions()->prefix ?: '',
            default => '',
        };

        $redisStoreName = $this->app['config']['recall.redis_store'] ?? 'redis';
        $storePrefix = $this->app['config']["cache.stores.{$redisStoreName}.prefix"]
            ?? $this->app['config']['cache.prefix']
            ?? '';

        return $connectionPrefix.$storePrefix;
    }
}
