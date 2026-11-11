<?php

declare(strict_types=1);

namespace DefectiveCode\Recall;

use Redis;
use Throwable;
use Swoole\Table;
use RuntimeException;
use ReflectionProperty;
use Swoole\Http\Server;
use Predis\Response\ErrorInterface;
use Laravel\Octane\Swoole\WorkerState;
use DefectiveCode\Recall\Cache\LocalCache;
use DefectiveCode\Recall\Cache\RecallStore;
use DefectiveCode\Recall\Cache\ArrayLocalCache;
use DefectiveCode\Recall\Cache\SwooleTableCache;
use DefectiveCode\Recall\Tracking\ClientTracker;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use DefectiveCode\Recall\Cache\SharedSwooleTableCache;
use DefectiveCode\Recall\Redis\InvalidationSubscriber;
use DefectiveCode\Recall\Contracts\LocalCacheInterface;

class RecallManager
{
    protected ?InvalidationSubscriber $subscriber = null;

    protected ?LocalCacheInterface $localCache = null;

    protected ?ClientTracker $tracker = null;

    protected ?RecallStore $store = null;

    protected bool $trackingEnabled = false;

    protected ?object $trackedClient = null;

    protected bool $active = false;

    protected bool $queueMode = false;

    protected int $activePid = 0;

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
            localCache: fn (): LocalCacheInterface => $this->getLocalCache(),
            manager: $this,
            config: [
                'enabled' => $this->app['config']['recall.enabled'] ?? true,
                'cache_prefixes' => $this->app['config']['recall.cache_prefixes'] ?? [],
            ],
        );

        return $this->store;
    }

    public function activate(): void
    {
        if ($this->isActive()) {
            return;
        }

        $this->active = true;
        $this->activePid = (int) getmypid();
    }

    public function activateForQueue(): void
    {
        if ($this->isActive()) {
            return;
        }

        $this->activate();
        $this->queueMode = true;
        $this->tracker = null;
        $this->localCache = new ArrayLocalCache([
            'default_ttl' => (int) ($this->app['config']['recall.local_cache.default_ttl'] ?? 3600),
            'max_entries' => (int) ($this->app['config']['recall.queue.max_entries'] ?? 1000),
            'value_size' => (int) ($this->app['config']['recall.local_cache.value_size'] ?? 8192),
        ]);
    }

    public function isActive(): bool
    {
        if ($this->active && getmypid() !== $this->activePid) {
            $this->reset();
        }

        return $this->active;
    }

    public function deactivate(): void
    {
        $this->disconnect();
        $this->reset();
    }

    protected function reset(): void
    {
        $this->subscriber = null;
        $this->tracker = null;
        $this->localCache = null;
        $this->trackedClient = null;
        $this->trackingEnabled = false;
        $this->active = false;
        $this->queueMode = false;
    }

    public function isQueueMode(): bool
    {
        return $this->isActive() && $this->queueMode;
    }

    public function getSubscriber(): InvalidationSubscriber
    {
        return $this->subscriber ??= new InvalidationSubscriber($this->getRedisConnectionConfig() + [
            'tracking_prefixes' => $this->broadcastsTracking()
                ? $this->buildTrackingPrefixes($this->app['config']['recall.cache_prefixes'], $this->dataConnection())
                : [],
        ]);
    }

    public function getLocalCache(): LocalCacheInterface
    {
        if ($this->localCache !== null) {
            return $this->localCache;
        }

        $driver = $this->app['config']['recall.local_cache.driver'] ?? 'apcu';
        $config = $this->app['config']['recall.local_cache'] ?? [];

        return $this->localCache = match ($driver) {
            'swoole' => $this->makeSwooleCache($config),
            default => new LocalCache($config),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function makeSwooleCache(array $config): LocalCacheInterface
    {
        $tables = $this->sharedTables($config);

        if ($tables !== null) {
            return new SharedSwooleTableCache($tables[0], $tables[1], $tables[2]);
        }

        return new SwooleTableCache($config);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{0: Table, 1: Table, 2: array<string, mixed>}|null
     */
    protected function sharedTables(array $config): ?array
    {
        if (
            ! ($config['shared'] ?? false)
            || empty($this->app['config']['recall.cache_prefixes'])
            || ! $this->app->bound(Server::class)
            || ! class_exists(WorkerState::class)
        ) {
            return null;
        }

        $tables = $this->app->make(WorkerState::class)->tables ?? [];
        $data = $tables[RecallServiceProvider::DATA_TABLE] ?? null;
        $epochs = $tables[RecallServiceProvider::EPOCH_TABLE] ?? null;

        if (! $data instanceof Table || ! $epochs instanceof Table) {
            return null;
        }

        $columns = property_exists($data, 'columns') ? (new ReflectionProperty($data, 'columns'))->getValue($data) : [];
        $config['value_size'] = (int) ($columns['value'][1] ?? $config['value_size'] ?? 8192);
        $config['epoch_stripes'] = intdiv(method_exists($epochs, 'getSize') ? $epochs->getSize() : 2 * (int) ($config['epoch_stripes'] ?? 1024), 2);

        return $config['epoch_stripes'] < 1 ? null : [$data, $epochs, $config];
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

        try {
            if (! $subscriber->isConnected()) {
                $subscriber->connect();
                $subscriber->subscribe();
            }

            if (! $this->broadcastsTracking()) {
                $this->enableClientTracking($subscriber->getConnectionId());
                $this->trackedClient = $this->dataClient();
            }
        } catch (Throwable $e) {
            $subscriber->close();

            throw $e;
        }

        $this->trackingEnabled = true;
    }

    public function processInvalidations(): void
    {
        if ($this->trackingEnabled && $this->trackingLost()) {
            $this->getLocalCache()->flush();
            $this->getTracker()->clearPendingRequests();
            $this->disconnect();
        }

        if (! $this->trackingEnabled) {
            $this->enableTracking();
        }

        $this->getTracker()->processInvalidations();
    }

    protected function trackingLost(): bool
    {
        if (! $this->getSubscriber()->isConnected()) {
            return true;
        }

        return ! $this->broadcastsTracking() && $this->trackedClient !== $this->dataClient();
    }

    protected function dataClient(): object
    {
        return $this->dataConnection()->client();
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

        $this->trackedClient = null;
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
            'scheme' => $redisConfig['scheme'] ?? 'tcp',
        ];
    }

    protected function enableClientTracking(int $redirectId): void
    {
        $connection = $this->dataConnection();
        $client = $connection->client();

        $this->rawCommand($client, ['CLIENT', 'TRACKING', 'OFF']);
        $this->rawCommand($client, ['CLIENT', 'TRACKING', 'ON', 'REDIRECT', (string) $redirectId]);
    }

    /**
     * @param  array<string>  $args
     */
    protected function rawCommand(object $client, array $args): void
    {
        $result = $client instanceof Redis
            ? $client->rawCommand(...$args)
            : $client->executeRaw($args);

        if ($result === false || $result instanceof ErrorInterface) {
            $error = $client instanceof Redis ? $client->getLastError() : ($result instanceof ErrorInterface ? $result->getMessage() : null);

            if ($client instanceof Redis) {
                $client->clearLastError();
            }

            throw new RuntimeException('Failed to run '.implode(' ', array_slice($args, 0, 3)).': '.($error ?? 'unknown error'));
        }
    }

    /**
     * @param  array<string>  $cachePrefixes
     * @return array<string>
     */
    protected function buildTrackingPrefixes(array $cachePrefixes, mixed $connection): array
    {
        $fullPrefix = $this->getFullKeyPrefix($connection);

        return array_map(fn (string $prefix): string => $fullPrefix.$prefix, $cachePrefixes);
    }

    protected function dataConnection(): mixed
    {
        $redisStoreName = $this->app['config']['recall.redis_store'] ?? 'redis';
        $connectionName = $this->app['config']["cache.stores.{$redisStoreName}.connection"] ?? 'default';

        return $this->app['redis']->connection($connectionName);
    }

    protected function broadcastsTracking(): bool
    {
        return ! empty($this->app['config']['recall.cache_prefixes']);
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
