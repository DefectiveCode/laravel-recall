<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests;

use Illuminate\Cache\Repository;
use DefectiveCode\Recall\RecallManager;

class RecallServiceProviderTest extends TestCase
{
    public function testRegistersRecallManagerSingleton(): void
    {
        $manager1 = $this->app->make(RecallManager::class);
        $manager2 = $this->app->make(RecallManager::class);

        $this->assertSame($manager1, $manager2);
    }

    public function testRegistersRecallAlias(): void
    {
        $manager = $this->app->make('recall');

        $this->assertInstanceOf(RecallManager::class, $manager);
    }

    public function testMergesConfig(): void
    {
        $this->assertTrue($this->app['config']->has('recall'));
        $this->assertTrue($this->app['config']->has('recall.enabled'));
        $this->assertTrue($this->app['config']->has('recall.local_cache'));
    }

    public function testRegistersCacheDriver(): void
    {
        $this->requireApcu();

        $this->app['config']->set('cache.stores.recall_test', [
            'driver' => 'recall',
        ]);
        $this->app['config']->set('cache.stores.redis', [
            'driver' => 'redis',
            'connection' => 'default',
        ]);
        $this->app['config']->set('database.redis.default', [
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
        ]);

        $cache = $this->app['cache']->store('recall_test');

        $this->assertInstanceOf(Repository::class, $cache);
    }

    public function testConfigDefaultValues(): void
    {
        $this->assertTrue($this->app['config']['recall.enabled']);
        $this->assertEquals('redis', $this->app['config']['recall.redis_store']);
        $this->assertEquals([], $this->app['config']['recall.cache_prefixes']);
        $this->assertEquals('apcu', $this->app['config']['recall.local_cache.driver']);
        $this->assertEquals('recall:', $this->app['config']['recall.local_cache.key_prefix']);
        $this->assertEquals(3600, $this->app['config']['recall.local_cache.default_ttl']);
        $this->assertTrue($this->app['config']['recall.listeners.warm']);
        $this->assertFalse($this->app['config']['recall.listeners.tick']);
    }
}
