<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests;

use Mockery;
use stdClass;
use RuntimeException;
use ReflectionProperty;
use Illuminate\Cache\RedisStore;
use Illuminate\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\Looping;
use DefectiveCode\Recall\RecallManager;
use Illuminate\Queue\Events\JobProcessing;
use DefectiveCode\Recall\Cache\RecallStore;
use Illuminate\Redis\Connections\Connection;
use DefectiveCode\Recall\Cache\ArrayLocalCache;
use DefectiveCode\Recall\RecallServiceProvider;
use DefectiveCode\Recall\Tracking\ClientTracker;
use Illuminate\Contracts\Debug\ExceptionHandler;
use DefectiveCode\Recall\Tracking\PendingRequest;
use DefectiveCode\Recall\Redis\InvalidationSubscriber;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use DefectiveCode\Recall\Contracts\LocalCacheInterface;
use DefectiveCode\Recall\Listeners\ProcessQueueInvalidations;

class QueueModeTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testItPassesThroughWhenTheManagerIsInactive(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('get')->with('key')->once()->andReturn('value');
        $redisStore->shouldReceive('put')->with('key', 'new', 10)->once()->andReturn(true);

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $localCache->shouldNotReceive('get');
        $localCache->shouldNotReceive('put');
        $localCache->shouldNotReceive('forget');

        $store = new RecallStore($redisStore, $localCache, new RecallManager($this->app));

        $this->assertSame('value', $store->get('key'));
        $this->assertTrue($store->put('key', 'new', 10));
    }

    public function testItActivatesAndReturnsNullOnLooping(): void
    {
        $manager = Mockery::mock(RecallManager::class);
        $manager->shouldReceive('activateForQueue')->once();
        $manager->shouldReceive('isActive')->andReturn(true);
        $manager->shouldReceive('processInvalidations')->once();

        $this->assertNull((new ProcessQueueInvalidations($manager))->handle(new Looping('redis', 'default')));
    }

    public function testItDrainsInvalidationsOnJobProcessingWhenActive(): void
    {
        $manager = Mockery::mock(RecallManager::class);
        $manager->shouldNotReceive('activateForQueue');
        $manager->shouldReceive('isActive')->andReturn(true);
        $manager->shouldReceive('processInvalidations')->once();

        (new ProcessQueueInvalidations($manager))->handle(new JobProcessing('redis', Mockery::mock(Job::class)));
    }

    public function testItDoesNotDrainOnJobProcessingWhenInactive(): void
    {
        $manager = Mockery::mock(RecallManager::class);
        $manager->shouldReceive('isActive')->andReturn(false);
        $manager->shouldNotReceive('processInvalidations');

        (new ProcessQueueInvalidations($manager))->handle(new JobProcessing('redis', Mockery::mock(Job::class)));
    }

    public function testItUsesAnArrayLocalCacheInQueueMode(): void
    {
        $this->app['config']->set('recall.queue.max_entries', 5);
        $manager = new RecallManager($this->app);

        $this->assertFalse($manager->isActive());

        $manager->activateForQueue();

        $this->assertTrue($manager->isActive());
        $this->assertTrue($manager->isQueueMode());
        $this->assertInstanceOf(ArrayLocalCache::class, $manager->getLocalCache());
    }

    public function testItDoesNotEnterQueueModeWhenAlreadyActive(): void
    {
        $manager = new RecallManager($this->app);
        $manager->activate();
        $manager->activateForQueue();

        $this->assertTrue($manager->isActive());
        $this->assertFalse($manager->isQueueMode());
    }

    public function testItDrainsInvalidationsBeforeServingAQueueModeLocalHit(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('getPrefix')->andReturn('p:');
        $redisStore->shouldReceive('connection')->andReturn(Mockery::mock(Connection::class));

        $cache = new ArrayLocalCache;
        $cache->put('p:key', 'stale');

        $manager = Mockery::mock(RecallManager::class);
        $manager->shouldReceive('isActive')->andReturn(true);
        $manager->shouldReceive('isQueueMode')->andReturn(true);
        $manager->shouldReceive('processInvalidations')->once()->andReturnUsing(function () use ($cache): void {
            $cache->forget('p:key');
        });
        $manager->shouldReceive('getTracker')->andReturn(
            Mockery::mock(ClientTracker::class, function ($tracker): void {
                $tracker->shouldReceive('registerPendingRequest')->andReturn(new PendingRequest('p:key'));
                $tracker->shouldReceive('completePendingRequest')->andReturn(false);
            })
        );
        $redisStore->shouldReceive('get')->with('key')->once()->andReturn('fresh');

        $store = new RecallStore($redisStore, $cache, $manager);

        $this->assertSame('fresh', $store->get('key'));
    }

    public function testItResetsQueueStateWhenThePidChanges(): void
    {
        $manager = new RecallManager($this->app);
        $manager->activateForQueue();

        $pid = new ReflectionProperty(RecallManager::class, 'activePid');
        $pid->setValue($manager, $pid->getValue($manager) + 1);

        $this->assertFalse($manager->isActive());
        $this->assertFalse($manager->isQueueMode());

        $manager->activateForQueue();

        $this->assertTrue($manager->isQueueMode());
    }

    public function testItRegistersQueueListenersWhenEnabled(): void
    {
        $this->bootProviderWith(true, true);

        $this->assertTrue($this->app['events']->hasListeners(Looping::class));
        $this->assertTrue($this->app['events']->hasListeners(JobProcessing::class));
    }

    public function testItDoesNotRegisterQueueListenersWhenQueueListenerIsOff(): void
    {
        $this->bootProviderWith(true, false);

        $this->assertFalse($this->app['events']->hasListeners(Looping::class));
        $this->assertFalse($this->app['events']->hasListeners(JobProcessing::class));
    }

    public function testItDoesNotRegisterQueueListenersWhenRecallIsDisabled(): void
    {
        $this->bootProviderWith(false, true);

        $this->assertFalse($this->app['events']->hasListeners(Looping::class));
    }

    protected function bootProviderWith(bool $enabled, bool $queue): void
    {
        $this->app['config']->set('recall.enabled', $enabled);
        $this->app['config']->set('recall.listeners.queue', $queue);
        $this->app->instance('events', new Dispatcher($this->app));

        (new RecallServiceProvider($this->app))->boot();
    }

    public function testItDeactivatesAndStaysPassThroughWhenEnablingTrackingFails(): void
    {
        $manager = Mockery::mock(RecallManager::class);
        $manager->shouldReceive('activateForQueue')->once();
        $manager->shouldReceive('isActive')->andReturn(true);
        $manager->shouldReceive('processInvalidations')->once()->andThrow(new RuntimeException('redis down'));
        $manager->shouldReceive('deactivate')->once();

        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once();
        $this->app->instance(ExceptionHandler::class, $handler);

        $this->assertNull((new ProcessQueueInvalidations($manager))->handle(new Looping('redis', 'default')));
    }

    public function testItFlushesAndReEnablesTrackingWhenTheSubscriberIsDisconnected(): void
    {
        $client = new stdClass;
        $manager = $this->trackingManager($client);

        $subscriber = Mockery::mock(InvalidationSubscriber::class);
        $subscriber->shouldReceive('isConnected')->andReturn(false);
        $subscriber->shouldReceive('close')->once();
        $this->setProperty($manager, 'subscriber', $subscriber);
        $this->setProperty($manager, 'trackedClient', $client);

        $manager->processInvalidations();

        $this->assertSame(1, $manager->flushes);
        $this->assertSame(1, $manager->enables);
    }

    public function testItFlushesAndReEnablesTrackingWhenTheDataClientIsReplaced(): void
    {
        $manager = $this->trackingManager(new stdClass);

        $subscriber = Mockery::mock(InvalidationSubscriber::class);
        $subscriber->shouldReceive('isConnected')->andReturn(true);
        $subscriber->shouldReceive('close')->once();
        $this->setProperty($manager, 'subscriber', $subscriber);
        $this->setProperty($manager, 'trackedClient', new stdClass);

        $manager->processInvalidations();

        $this->assertSame(1, $manager->flushes);
        $this->assertSame(1, $manager->enables);
    }

    public function testItDoesNotFlushWhenTrackingIsIntact(): void
    {
        $client = new stdClass;
        $manager = $this->trackingManager($client);

        $subscriber = Mockery::mock(InvalidationSubscriber::class);
        $subscriber->shouldReceive('isConnected')->andReturn(true);
        $this->setProperty($manager, 'subscriber', $subscriber);
        $this->setProperty($manager, 'trackedClient', $client);

        $manager->processInvalidations();

        $this->assertSame(0, $manager->flushes);
        $this->assertSame(0, $manager->enables);
    }

    protected function trackingManager(object $client): RecallManager
    {
        $manager = new class($this->app, $client) extends RecallManager
        {
            public int $flushes = 0;

            public int $enables = 0;

            public function __construct($app, protected object $client)
            {
                parent::__construct($app);
            }

            public function getLocalCache(): LocalCacheInterface
            {
                $cache = Mockery::mock(LocalCacheInterface::class);
                $cache->shouldReceive('flush')->andReturnUsing(function (): bool {
                    $this->flushes++;

                    return true;
                });

                return $cache;
            }

            public function getTracker(): ClientTracker
            {
                return Mockery::mock(ClientTracker::class, function ($tracker): void {
                    $tracker->shouldReceive('clearPendingRequests');
                    $tracker->shouldReceive('processInvalidations');
                });
            }

            public function enableTracking(): void
            {
                $this->enables++;
                $this->trackingEnabled = true;
            }

            protected function dataClient(): object
            {
                return $this->client;
            }
        };

        $this->setProperty($manager, 'trackingEnabled', true);

        return $manager;
    }

    protected function setProperty(object $object, string $name, mixed $value): void
    {
        (new ReflectionProperty(RecallManager::class, $name))->setValue($object, $value);
    }

    public function testItFlushesTheLocalCacheAfterRedisOnFlush(): void
    {
        $order = [];

        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('flush')->once()->andReturnUsing(function () use (&$order): bool {
            $order[] = 'redis';

            return true;
        });

        $cache = Mockery::mock(LocalCacheInterface::class);
        $cache->shouldReceive('flush')->once()->andReturnUsing(function () use (&$order): bool {
            $order[] = 'local';

            return true;
        });

        $tracker = Mockery::mock(ClientTracker::class);
        $tracker->shouldReceive('clearPendingRequests')->once();

        $manager = Mockery::mock(RecallManager::class);
        $manager->shouldReceive('isActive')->andReturn(true);
        $manager->shouldReceive('getTracker')->andReturn($tracker);

        (new RecallStore($redisStore, $cache, $manager))->flush();

        $this->assertSame(['redis', 'local'], $order);
    }
}
