<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests;

use Illuminate\Cache\Repository;
use Illuminate\Events\Dispatcher;
use DefectiveCode\Recall\RecallManager;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\RequestReceived;
use DefectiveCode\Recall\RecallServiceProvider;

class RecallServiceProviderTest extends TestCase
{
    public function testItRegistersOctaneListenersWhenEnabled(): void
    {
        $this->requireOctane();

        $this->assertTrue($this->app['events']->hasListeners(WorkerStarting::class));
        $this->assertTrue($this->app['events']->hasListeners(RequestReceived::class));
    }

    public function testItDoesNotRegisterOctaneListenersWhenDisabled(): void
    {
        $this->requireOctane();

        $this->app['config']->set('recall.enabled', false);
        $this->app->instance('events', new Dispatcher($this->app));

        (new RecallServiceProvider($this->app))->boot();

        $this->assertFalse($this->app['events']->hasListeners(WorkerStarting::class));
        $this->assertFalse($this->app['events']->hasListeners(RequestReceived::class));
    }

    public function testItSharesTheBootedRecallManagerWithOctaneSandboxes(): void
    {
        $this->requireOctane();

        $sandbox = clone $this->app;

        $this->assertSame($this->app->make(RecallManager::class), $sandbox->make(RecallManager::class));
    }

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

    public function testItRegistersOctaneTablesOnlyWhenSharedWithSwoole(): void
    {
        $this->registerWith(['driver' => 'swoole', 'shared' => false]);
        $this->assertNull($this->app['config']->get('octane.tables'));

        $this->registerWith(['driver' => 'apcu', 'shared' => true]);
        $this->assertNull($this->app['config']->get('octane.tables'));

        $this->registerWith(['driver' => 'swoole', 'shared' => true, 'table_size' => 128, 'epoch_stripes' => 16]);

        $tables = $this->app['config']->get('octane.tables');
        $this->assertSame(['value' => 'string:8192', 'expires_at' => 'int'], $tables['recall:128']);
        $this->assertSame(['epoch' => 'int'], $tables['recall_epochs:32']);
    }

    public function testItKeepsExistingOctaneTablesWhenRegisteringSharedTables(): void
    {
        $existing = ['example:1000' => ['name' => 'string:1000']];
        $this->app['config']->set('octane.tables', $existing);

        $this->registerWith(['driver' => 'swoole', 'shared' => true, 'table_size' => 128]);
        $this->registerWith(['driver' => 'swoole', 'shared' => true, 'table_size' => 128]);

        $tables = $this->app['config']->get('octane.tables');
        $this->assertSame($existing['example:1000'], $tables['example:1000']);
        $this->assertCount(3, $tables);
    }

    /**
     * @param  array<string, mixed>  $localCache
     */
    protected function registerWith(array $localCache): void
    {
        $this->app['config']->set('recall.local_cache', $localCache + $this->app['config']['recall.local_cache']);

        (new RecallServiceProvider($this->app))->register();
    }
}
