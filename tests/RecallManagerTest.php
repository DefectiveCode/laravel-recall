<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests;

use Mockery;
use ReflectionClass;
use DefectiveCode\Recall\RecallManager;
use DefectiveCode\Recall\Cache\LocalCache;
use DefectiveCode\Recall\Cache\RecallStore;
use DefectiveCode\Recall\Cache\SwooleTableCache;
use DefectiveCode\Recall\Tracking\ClientTracker;
use DefectiveCode\Recall\Redis\InvalidationSubscriber;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class RecallManagerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireApcu();
    }

    protected function configureRedis(): void
    {
        $this->app['config']->set('cache.stores.redis', [
            'driver' => 'redis',
            'connection' => 'default',
        ]);
        $this->app['config']->set('database.redis.default', [
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
        ]);
    }

    public function testStoreReturnsRecallStoreInstance(): void
    {
        $this->configureRedis();

        $manager = $this->app->make(RecallManager::class);
        $store = $manager->store();

        $this->assertInstanceOf(RecallStore::class, $store);
    }

    public function testStoreLazyInitialization(): void
    {
        $this->configureRedis();

        $manager = $this->app->make(RecallManager::class);

        $store1 = $manager->store();
        $store2 = $manager->store();

        $this->assertSame($store1, $store2);
    }

    public function testGetSubscriberReturnsInstance(): void
    {
        $this->configureRedis();

        $manager = $this->app->make(RecallManager::class);
        $subscriber = $manager->getSubscriber();

        $this->assertInstanceOf(InvalidationSubscriber::class, $subscriber);
    }

    public function testGetSubscriberLazyInitialization(): void
    {
        $this->configureRedis();

        $manager = $this->app->make(RecallManager::class);

        $subscriber1 = $manager->getSubscriber();
        $subscriber2 = $manager->getSubscriber();

        $this->assertSame($subscriber1, $subscriber2);
    }

    public function testGetLocalCacheReturnsApcuByDefault(): void
    {
        $this->app['config']->set('recall.local_cache.driver', 'apcu');

        $manager = $this->app->make(RecallManager::class);
        $localCache = $manager->getLocalCache();

        $this->assertInstanceOf(LocalCache::class, $localCache);
    }

    public function testGetLocalCacheReturnsSwooleWhenConfigured(): void
    {
        $this->requireSwoole();

        $this->app['config']->set('recall.local_cache.driver', 'swoole');

        $manager = $this->app->make(RecallManager::class);
        $localCache = $manager->getLocalCache();

        $this->assertInstanceOf(SwooleTableCache::class, $localCache);
    }

    public function testGetLocalCacheLazyInitialization(): void
    {
        $manager = $this->app->make(RecallManager::class);

        $cache1 = $manager->getLocalCache();
        $cache2 = $manager->getLocalCache();

        $this->assertSame($cache1, $cache2);
    }

    public function testGetTrackerReturnsClientTracker(): void
    {
        $this->configureRedis();

        $manager = $this->app->make(RecallManager::class);
        $tracker = $manager->getTracker();

        $this->assertInstanceOf(ClientTracker::class, $tracker);
    }

    public function testGetTrackerLazyInitialization(): void
    {
        $this->configureRedis();

        $manager = $this->app->make(RecallManager::class);

        $tracker1 = $manager->getTracker();
        $tracker2 = $manager->getTracker();

        $this->assertSame($tracker1, $tracker2);
    }

    public function testIsConnectedReturnsFalseInitially(): void
    {
        $manager = $this->app->make(RecallManager::class);

        $this->assertFalse($manager->isConnected());
    }

    public function testDisconnectWhenNotConnected(): void
    {
        $manager = $this->app->make(RecallManager::class);

        $manager->disconnect();

        $this->assertFalse($manager->isConnected());
    }

    public function testFlushLocalCache(): void
    {
        $manager = $this->app->make(RecallManager::class);
        $localCache = $manager->getLocalCache();

        $localCache->put('test_key', 'test_value');
        $this->assertEquals('test_value', $localCache->get('test_key'));

        $manager->flushLocalCache();

        $this->assertNull($localCache->get('test_key'));
    }

    public function testUsesCustomRedisStore(): void
    {
        $this->app['config']->set('recall.redis_store', 'custom_redis');
        $this->app['config']->set('cache.stores.custom_redis', [
            'driver' => 'redis',
            'connection' => 'cache',
        ]);
        $this->app['config']->set('database.redis.cache', [
            'host' => '127.0.0.1',
            'port' => 6380,
            'database' => 1,
        ]);

        $manager = $this->app->make(RecallManager::class);

        $this->assertFalse($manager->isConnected());
    }

    public function testBuildTrackingArgsWithoutPrefixes(): void
    {
        $this->configureRedis();

        $manager = $this->app->make(RecallManager::class);

        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('buildTrackingArgs');

        $args = $method->invoke($manager, 123, [], null);

        $this->assertEquals(['CLIENT', 'TRACKING', 'ON', 'REDIRECT', '123'], $args);
    }

    public function testBuildTrackingArgsWithPrefixes(): void
    {
        $this->configureRedis();
        $this->app['config']->set('cache.stores.redis.prefix', 'laravel_cache_');

        $manager = $this->app->make(RecallManager::class);

        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('buildTrackingArgs');

        $mockConnection = Mockery::mock();

        $args = $method->invoke($manager, 123, ['users:', 'settings:'], $mockConnection);

        $this->assertEquals('CLIENT', $args[0]);
        $this->assertEquals('TRACKING', $args[1]);
        $this->assertEquals('ON', $args[2]);
        $this->assertEquals('REDIRECT', $args[3]);
        $this->assertEquals('123', $args[4]);
        $this->assertEquals('BCAST', $args[5]);
        $this->assertEquals('PREFIX', $args[6]);
        $this->assertStringEndsWith('users:', $args[7]);
        $this->assertEquals('PREFIX', $args[8]);
        $this->assertStringEndsWith('settings:', $args[9]);
    }

    public function testGetFullKeyPrefixWithStorePrefix(): void
    {
        $this->configureRedis();
        $this->app['config']->set('cache.stores.redis.prefix', 'myapp_');

        $manager = $this->app->make(RecallManager::class);

        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('getFullKeyPrefix');

        $mockConnection = Mockery::mock();

        $prefix = $method->invoke($manager, $mockConnection);

        $this->assertEquals('myapp_', $prefix);
    }

    public function testGetFullKeyPrefixFallsBackToCachePrefix(): void
    {
        $this->configureRedis();
        $this->app['config']->set('cache.prefix', 'fallback_');

        $manager = $this->app->make(RecallManager::class);

        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('getFullKeyPrefix');

        $mockConnection = Mockery::mock();

        $prefix = $method->invoke($manager, $mockConnection);

        $this->assertEquals('fallback_', $prefix);
    }

    public function testItExtractsSchemeFromRedisConfig(): void
    {
        $this->configureRedis();
        $this->app['config']->set('database.redis.default.scheme', 'tls');

        $manager = $this->app->make(RecallManager::class);

        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('getRedisConnectionConfig');

        $config = $method->invoke($manager);

        $this->assertEquals('tls', $config['scheme']);
    }

    public function testItDefaultsSchemeToTcp(): void
    {
        $this->configureRedis();

        $manager = $this->app->make(RecallManager::class);

        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('getRedisConnectionConfig');

        $config = $method->invoke($manager);

        $this->assertEquals('tcp', $config['scheme']);
    }

    public function testItExtractsSslScheme(): void
    {
        $this->configureRedis();
        $this->app['config']->set('database.redis.default.scheme', 'ssl');

        $manager = $this->app->make(RecallManager::class);

        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('getRedisConnectionConfig');

        $config = $method->invoke($manager);

        $this->assertEquals('ssl', $config['scheme']);
    }
}
