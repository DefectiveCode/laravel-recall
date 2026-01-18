<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests;

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
}
