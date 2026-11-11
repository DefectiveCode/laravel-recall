<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests;

use Mockery;
use Swoole\Table;
use Swoole\Http\Server;
use DefectiveCode\Recall\RecallManager;
use DefectiveCode\Recall\Cache\SwooleTableCache;
use DefectiveCode\Recall\Cache\SharedSwooleTableCache;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class RecallManagerSharedCacheTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireSwoole();
    }

    public function testGetLocalCacheReturnsSharedSwooleCacheUnderOctane(): void
    {
        $this->requireSwoole();

        $this->app['config']->set('recall.local_cache.driver', 'swoole');
        $this->app['config']->set('recall.local_cache.shared', true);
        $this->app->bind(Server::class, fn () => null);

        $octane = Mockery::mock();
        $octane->shouldReceive('table')->with('recall')->once()->andReturn(new Table(64));
        $octane->shouldReceive('table')->with('recall_epochs')->once()->andReturn(new Table(64));
        $this->app->instance('octane', $octane);

        $this->assertInstanceOf(SharedSwooleTableCache::class, $this->app->make(RecallManager::class)->getLocalCache());
    }

    public function testGetLocalCacheFallsBackToPerWorkerSwooleCacheOutsideOctane(): void
    {
        $this->requireSwoole();

        $this->app['config']->set('recall.local_cache.driver', 'swoole');
        $this->app['config']->set('recall.local_cache.shared', true);

        $octane = Mockery::mock();
        $octane->shouldReceive('table')->never();
        $this->app->instance('octane', $octane);

        $this->assertInstanceOf(SwooleTableCache::class, $this->app->make(RecallManager::class)->getLocalCache());
    }
}
