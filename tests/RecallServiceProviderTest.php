<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests;

use Illuminate\Cache\Repository;
use Illuminate\Events\Dispatcher;
use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Event;
use DefectiveCode\Recall\RecallManager;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\RequestReceived;
use DefectiveCode\Recall\RecallServiceProvider;

class RecallServiceProviderTest extends TestCase
{
    public function testItRegistersOctaneListenersWhenEnabled(): void
    {
        $this->requireOctane();

        $this->assertTrue(Event::hasListeners(WorkerStarting::class));
        $this->assertTrue(Event::hasListeners(RequestReceived::class));
    }

    public function testItDoesNotRegisterOctaneListenersWhenDisabled(): void
    {
        $this->requireOctane();

        config()->set('recall.enabled', false);
        $events = new Dispatcher(app());
        app()->instance('events', $events);

        (new RecallServiceProvider(app()))->boot();

        $this->assertFalse($events->hasListeners(WorkerStarting::class));
        $this->assertFalse($events->hasListeners(RequestReceived::class));
    }

    public function testItSharesTheBootedRecallManagerWithOctaneSandboxes(): void
    {
        $this->requireOctane();

        $sandbox = clone app();

        $this->assertSame(app()->make(RecallManager::class), $sandbox->make(RecallManager::class));
    }

    public function testRegistersRecallManagerSingleton(): void
    {
        $manager1 = app()->make(RecallManager::class);
        $manager2 = app()->make(RecallManager::class);

        $this->assertSame($manager1, $manager2);
    }

    public function testRegistersRecallAlias(): void
    {
        $manager = app()->make('recall');

        $this->assertInstanceOf(RecallManager::class, $manager);
    }

    public function testMergesConfig(): void
    {
        $this->assertTrue(config()->has('recall'));
        $this->assertTrue(config()->has('recall.enabled'));
        $this->assertTrue(config()->has('recall.local_cache'));
    }

    public function testRegistersCacheDriver(): void
    {
        $this->requireApcu();

        config()->set('cache.stores.recall_test', [
            'driver' => 'recall',
        ]);
        config()->set('cache.stores.redis', [
            'driver' => 'redis',
            'connection' => 'default',
        ]);
        config()->set('database.redis.default', [
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
        ]);

        $cache = app(CacheManager::class)->store('recall_test');

        $this->assertInstanceOf(Repository::class, $cache);
    }

    public function testConfigDefaultValues(): void
    {
        $this->assertTrue(config('recall.enabled'));
        $this->assertEquals('redis', config('recall.redis_store'));
        $this->assertEquals([], config('recall.cache_prefixes'));
        $this->assertEquals('apcu', config('recall.local_cache.driver'));
        $this->assertEquals('recall:', config('recall.local_cache.key_prefix'));
        $this->assertEquals(3600, config('recall.local_cache.default_ttl'));
        $this->assertTrue(config('recall.listeners.warm'));
        $this->assertFalse(config('recall.listeners.tick'));
    }
}
