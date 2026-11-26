<?php

declare(strict_types=1);

namespace DefectiveCode\Recall;

use Redis;
use Stringable;
use RedisCluster;
use Predis\Client;
use RuntimeException;
use Illuminate\Cache\CacheManager;
use Illuminate\Redis\RedisManager;
use DefectiveCode\Recall\Cache\LocalCache;
use DefectiveCode\Recall\Cache\RecallStore;
use Illuminate\Contracts\Config\Repository;
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

        /** @var string $redisStoreName */
        $redisStoreName = $this->app->make(Repository::class)->get('recall.redis_store') ?? 'redis';
        $redisStore = $this->app->make(CacheManager::class)->store($redisStoreName)->getStore();

        /** @var array{enabled?: bool, cache_prefixes?: array<string>} $config */
        $config = [
            'enabled' => $this->app->make(Repository::class)->get('recall.enabled') ?? true,
            'cache_prefixes' => $this->app->make(Repository::class)->get('recall.cache_prefixes') ?? [],
        ];

        $this->store = new RecallStore(
            redisStore: $redisStore,
            localCache: $this->getLocalCache(),
            manager: $this,
            config: $config,
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

        $driver = $this->app->make(Repository::class)->get('recall.local_cache.driver') ?? 'apcu';
        /** @var array{key_prefix?: string, default_ttl?: int, table_size?: int, value_size?: int} $config */
        $config = $this->app->make(Repository::class)->get('recall.local_cache') ?? [];

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
     * @return array{host: string, port: int, password?: string|null, username?: string|null, database?: int, timeout?: float, scheme?: string}
     */
    protected function getRedisConnectionConfig(): array
    {
        /** @var string $redisStoreName */
        $redisStoreName = $this->app->make(Repository::class)->get('recall.redis_store') ?? 'redis';
        /** @var string $connectionName */
        $connectionName = $this->app->make(Repository::class)->get("cache.stores.{$redisStoreName}.connection") ?? 'default';
        /** @var array{host?: string, port?: int|string, password?: ?string, username?: ?string, database?: int|string, read_timeout?: float|int|string, timeout?: float|int|string, scheme?: string} $redisConfig */
        $redisConfig = $this->app->make(Repository::class)->get("database.redis.{$connectionName}") ?? [];

        return [
            'host' => $redisConfig['host'] ?? '127.0.0.1',
            'port' => (int) ($redisConfig['port'] ?? 6379),
            'password' => $redisConfig['password'] ?? null,
            'username' => $redisConfig['username'] ?? null,
            'database' => (int) ($redisConfig['database'] ?? 0),
            'timeout' => (float) ($redisConfig['read_timeout'] ?? $redisConfig['timeout'] ?? 5.0),
            'scheme' => $redisConfig['scheme'] ?? 'tcp',
        ];
    }

    protected function enableClientTracking(int $redirectId): void
    {
        /** @var string $redisStoreName */
        $redisStoreName = $this->app->make(Repository::class)->get('recall.redis_store') ?? 'redis';
        /** @var string $connectionName */
        $connectionName = $this->app->make(Repository::class)->get("cache.stores.{$redisStoreName}.connection") ?? 'default';

        $connection = $this->app->make(RedisManager::class)->connection($connectionName);
        /** @var Redis|Client $client */
        $client = $connection->client();

        /** @var array<string> $cachePrefixes */
        $cachePrefixes = $this->app->make(Repository::class)->get('recall.cache_prefixes') ?? [];
        $args = $this->buildTrackingArgs($redirectId, $cachePrefixes, $connection);

        $result = $client instanceof Redis
            ? $client->rawCommand(...$args)
            : $client->executeRaw($args);

        if ($result === false) {
            $lastError = $client instanceof Redis ? $client->getLastError() : 'unknown error';

            if ($client instanceof Redis) {
                $client->clearLastError();
            }

            throw new RuntimeException(
                "Failed to enable CLIENT TRACKING on connection '{$connectionName}' with REDIRECT {$redirectId}: {$lastError}"
            );
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
        /** @var string $connectionPrefix */
        $connectionPrefix = match (true) {
            $connection instanceof PhpRedisConnection && (($client = $connection->client()) instanceof Redis || $client instanceof RedisCluster) => $client->getOption(Redis::OPT_PREFIX) ?: '',
            $connection instanceof PredisConnection && ($processor = $connection->getOptions()->prefix) instanceof Stringable => (string) $processor,
            default => '',
        };

        /** @var string $redisStoreName */
        $redisStoreName = $this->app->make(Repository::class)->get('recall.redis_store') ?? 'redis';
        /** @var string $storePrefix */
        $storePrefix = $this->app->make(Repository::class)->get("cache.stores.{$redisStoreName}.prefix")
            ?? $this->app->make(Repository::class)->get('cache.prefix')
            ?? '';

        return $connectionPrefix.$storePrefix;
    }
}
