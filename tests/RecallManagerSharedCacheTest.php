<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests;

use Swoole\Table;
use Swoole\Http\Server;
use Laravel\Octane\Swoole\WorkerState;
use DefectiveCode\Recall\RecallManager;
use DefectiveCode\Recall\Cache\SwooleTableCache;
use DefectiveCode\Recall\Cache\SharedSwooleTableCache;

class RecallManagerSharedCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireSwoole();

        require_once __DIR__.'/../vendor/laravel/octane/bin/WorkerState.php';
    }

    public function testItGetLocalCacheReturnsSharedSwooleCacheUnderOctaneWithPrefixes(): void
    {
        $this->configureShared(['users:']);
        $this->bindTables($this->dataTable(), $this->epochTable(64));

        $this->assertInstanceOf(SharedSwooleTableCache::class, $this->app->make(RecallManager::class)->getLocalCache());
    }

    public function testItGetLocalCacheFallsBackToPerWorkerWithoutCachePrefixes(): void
    {
        $this->configureShared([]);
        $this->bindTables($this->dataTable(), $this->epochTable(64));

        $this->assertInstanceOf(SwooleTableCache::class, $this->app->make(RecallManager::class)->getLocalCache());
    }

    public function testItGetLocalCacheFallsBackToPerWorkerWhenTheTablesDoNotExist(): void
    {
        $this->configureShared(['users:']);
        $this->bindTables(null, null);

        $this->assertInstanceOf(SwooleTableCache::class, $this->app->make(RecallManager::class)->getLocalCache());
    }

    public function testItGetLocalCacheDerivesTheStripeCountFromTheLiveEpochTable(): void
    {
        $this->configureShared(['users:']);
        $this->app['config']->set('recall.local_cache.epoch_stripes', 100000);
        $epochs = $this->epochTable(64);
        $this->bindTables($this->dataTable(), $epochs);

        $this->app->make(RecallManager::class)->getLocalCache();

        $this->assertSame(intdiv($epochs->getSize(), 2), $epochs->count());
    }

    public function testItGetLocalCacheFallsBackToPerWorkerOutsideOctane(): void
    {
        $this->app['config']->set('recall.local_cache.driver', 'swoole');
        $this->app['config']->set('recall.local_cache.shared', true);
        $this->app['config']->set('recall.cache_prefixes', ['users:']);

        $this->assertInstanceOf(SwooleTableCache::class, $this->app->make(RecallManager::class)->getLocalCache());
    }

    /**
     * @param  array<string>  $prefixes
     */
    protected function configureShared(array $prefixes): void
    {
        $this->app['config']->set('recall.local_cache.driver', 'swoole');
        $this->app['config']->set('recall.local_cache.shared', true);
        $this->app['config']->set('recall.cache_prefixes', $prefixes);
        $this->app->bind(Server::class, fn () => null);
    }

    protected function bindTables(?Table $data, ?Table $epochs): void
    {
        $state = new WorkerState;
        $state->tables = array_filter(['recall' => $data, 'recall_epochs' => $epochs]);

        $this->app->instance(WorkerState::class, $state);
    }

    protected function dataTable(): Table
    {
        $table = new Table(64, 1);
        $table->column('value', Table::TYPE_STRING, 1024);
        $table->column('expires_at', Table::TYPE_INT);
        $table->create();

        return $table;
    }

    protected function epochTable(int $size): Table
    {
        $table = new Table($size, 1);
        $table->column('epoch', Table::TYPE_INT);
        $table->create();

        return $table;
    }
}
