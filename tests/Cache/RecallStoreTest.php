<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests\Cache;

use Redis;
use Mockery;
use RuntimeException;
use PHPUnit\Framework\TestCase;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Store;
use DefectiveCode\Recall\RecallManager;
use DefectiveCode\Recall\Cache\RecallStore;
use DefectiveCode\Recall\Tracking\ClientTracker;
use DefectiveCode\Recall\Tracking\PendingRequest;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use DefectiveCode\Recall\Contracts\LocalCacheInterface;

class RecallStoreTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testThrowsWhenNotRedisStore(): void
    {
        $fakeStore = Mockery::mock(Store::class);
        $localCache = Mockery::mock(LocalCacheInterface::class);
        $manager = Mockery::mock(RecallManager::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RecallStore requires a RedisStore instance');

        new RecallStore($fakeStore, $localCache, $manager);
    }

    public function testGetReturnsFromLocalCacheWhenHit(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('getPrefix')->andReturn('laravel_cache:');

        $connection = Mockery::mock(PhpRedisConnection::class);
        $redis = Mockery::mock(Redis::class);
        $redis->shouldReceive('getOption')->with(Redis::OPT_PREFIX)->andReturn('');
        $connection->shouldReceive('client')->andReturn($redis);
        $redisStore->shouldReceive('connection')->andReturn($connection);

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $localCache->shouldReceive('get')->with('laravel_cache:mykey')->once()->andReturn('cached_value');

        $manager = Mockery::mock(RecallManager::class);

        $store = new RecallStore($redisStore, $localCache, $manager, ['enabled' => true]);

        $this->assertEquals('cached_value', $store->get('mykey'));
    }

    public function testGetFetchesFromRedisWhenLocalCacheMiss(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('getPrefix')->andReturn('laravel_cache:');
        $redisStore->shouldReceive('get')->with('mykey')->once()->andReturn('redis_value');

        $connection = Mockery::mock(PhpRedisConnection::class);
        $redis = Mockery::mock(Redis::class);
        $redis->shouldReceive('getOption')->with(Redis::OPT_PREFIX)->andReturn('');
        $connection->shouldReceive('client')->andReturn($redis);
        $redisStore->shouldReceive('connection')->andReturn($connection);

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $localCache->shouldReceive('get')->with('laravel_cache:mykey')->once()->andReturn(null);
        $localCache->shouldReceive('put')->with('laravel_cache:mykey', 'redis_value')->once();

        $pendingRequest = new PendingRequest('laravel_cache:mykey');
        $tracker = Mockery::mock(ClientTracker::class);
        $tracker->shouldReceive('registerPendingRequest')->with('laravel_cache:mykey')->once()->andReturn($pendingRequest);
        $tracker->shouldReceive('completePendingRequest')->with($pendingRequest)->once()->andReturn(true);

        $manager = Mockery::mock(RecallManager::class);
        $manager->shouldReceive('getTracker')->andReturn($tracker);

        $store = new RecallStore($redisStore, $localCache, $manager, ['enabled' => true]);

        $this->assertEquals('redis_value', $store->get('mykey'));
    }

    public function testGetDoesNotCacheWhenPendingRequestInvalidated(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('getPrefix')->andReturn('laravel_cache:');
        $redisStore->shouldReceive('get')->with('mykey')->once()->andReturn('redis_value');

        $connection = Mockery::mock(PhpRedisConnection::class);
        $redis = Mockery::mock(Redis::class);
        $redis->shouldReceive('getOption')->with(Redis::OPT_PREFIX)->andReturn('');
        $connection->shouldReceive('client')->andReturn($redis);
        $redisStore->shouldReceive('connection')->andReturn($connection);

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $localCache->shouldReceive('get')->with('laravel_cache:mykey')->once()->andReturn(null);
        $localCache->shouldNotReceive('put');

        $pendingRequest = new PendingRequest('laravel_cache:mykey');
        $tracker = Mockery::mock(ClientTracker::class);
        $tracker->shouldReceive('registerPendingRequest')->with('laravel_cache:mykey')->once()->andReturn($pendingRequest);
        $tracker->shouldReceive('completePendingRequest')->with($pendingRequest)->once()->andReturn(false);

        $manager = Mockery::mock(RecallManager::class);
        $manager->shouldReceive('getTracker')->andReturn($tracker);

        $store = new RecallStore($redisStore, $localCache, $manager, ['enabled' => true]);

        $this->assertEquals('redis_value', $store->get('mykey'));
    }

    public function testGetPassesThroughWhenDisabled(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('get')->with('mykey')->once()->andReturn('redis_value');

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $localCache->shouldNotReceive('get');
        $localCache->shouldNotReceive('put');

        $manager = Mockery::mock(RecallManager::class);

        $store = new RecallStore($redisStore, $localCache, $manager, ['enabled' => false]);

        $this->assertEquals('redis_value', $store->get('mykey'));
    }

    public function testGetPassesThroughWhenPrefixDoesNotMatch(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('get')->with('other:key')->once()->andReturn('redis_value');

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $localCache->shouldNotReceive('get');
        $localCache->shouldNotReceive('put');

        $manager = Mockery::mock(RecallManager::class);

        $store = new RecallStore($redisStore, $localCache, $manager, [
            'enabled' => true,
            'cache_prefixes' => ['users:', 'config:'],
        ]);

        $this->assertEquals('redis_value', $store->get('other:key'));
    }

    public function testGetCachesWhenPrefixMatches(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('getPrefix')->andReturn('laravel_cache:');
        $redisStore->shouldReceive('get')->with('users:1')->once()->andReturn('user_data');

        $connection = Mockery::mock(PhpRedisConnection::class);
        $redis = Mockery::mock(Redis::class);
        $redis->shouldReceive('getOption')->with(Redis::OPT_PREFIX)->andReturn('');
        $connection->shouldReceive('client')->andReturn($redis);
        $redisStore->shouldReceive('connection')->andReturn($connection);

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $localCache->shouldReceive('get')->with('laravel_cache:users:1')->once()->andReturn(null);
        $localCache->shouldReceive('put')->with('laravel_cache:users:1', 'user_data')->once();

        $pendingRequest = new PendingRequest('laravel_cache:users:1');
        $tracker = Mockery::mock(ClientTracker::class);
        $tracker->shouldReceive('registerPendingRequest')->with('laravel_cache:users:1')->once()->andReturn($pendingRequest);
        $tracker->shouldReceive('completePendingRequest')->with($pendingRequest)->once()->andReturn(true);

        $manager = Mockery::mock(RecallManager::class);
        $manager->shouldReceive('getTracker')->andReturn($tracker);

        $store = new RecallStore($redisStore, $localCache, $manager, [
            'enabled' => true,
            'cache_prefixes' => ['users:', 'config:'],
        ]);

        $this->assertEquals('user_data', $store->get('users:1'));
    }

    public function testManyCallsGetForEachKey(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('getPrefix')->andReturn('');

        $connection = Mockery::mock(PhpRedisConnection::class);
        $redis = Mockery::mock(Redis::class);
        $redis->shouldReceive('getOption')->with(Redis::OPT_PREFIX)->andReturn('');
        $connection->shouldReceive('client')->andReturn($redis);
        $redisStore->shouldReceive('connection')->andReturn($connection);

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $localCache->shouldReceive('get')->with('key1')->once()->andReturn('value1');
        $localCache->shouldReceive('get')->with('key2')->once()->andReturn('value2');

        $manager = Mockery::mock(RecallManager::class);

        $store = new RecallStore($redisStore, $localCache, $manager, ['enabled' => true]);

        $result = $store->many(['key1', 'key2']);

        $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], $result);
    }

    public function testPutDelegatesToRedisStore(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('put')->with('key', 'value', 60)->once()->andReturn(true);

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $manager = Mockery::mock(RecallManager::class);

        $store = new RecallStore($redisStore, $localCache, $manager);

        $this->assertTrue($store->put('key', 'value', 60));
    }

    public function testPutManyDelegatesToRedisStore(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('putMany')->with(['key1' => 'value1'], 60)->once()->andReturn(true);

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $manager = Mockery::mock(RecallManager::class);

        $store = new RecallStore($redisStore, $localCache, $manager);

        $this->assertTrue($store->putMany(['key1' => 'value1'], 60));
    }

    public function testIncrementDelegatesToRedisStore(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('increment')->with('counter', 1)->once()->andReturn(5);

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $manager = Mockery::mock(RecallManager::class);

        $store = new RecallStore($redisStore, $localCache, $manager);

        $this->assertEquals(5, $store->increment('counter'));
    }

    public function testDecrementDelegatesToRedisStore(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('decrement')->with('counter', 1)->once()->andReturn(3);

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $manager = Mockery::mock(RecallManager::class);

        $store = new RecallStore($redisStore, $localCache, $manager);

        $this->assertEquals(3, $store->decrement('counter'));
    }

    public function testForeverDelegatesToRedisStore(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('forever')->with('key', 'value')->once()->andReturn(true);

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $manager = Mockery::mock(RecallManager::class);

        $store = new RecallStore($redisStore, $localCache, $manager);

        $this->assertTrue($store->forever('key', 'value'));
    }

    public function testForgetDelegatesToRedisStore(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('forget')->with('key')->once()->andReturn(true);

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $manager = Mockery::mock(RecallManager::class);

        $store = new RecallStore($redisStore, $localCache, $manager);

        $this->assertTrue($store->forget('key'));
    }

    public function testFlushClearsLocalCacheAndRedis(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('flush')->once()->andReturn(true);

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $localCache->shouldReceive('flush')->once();

        $tracker = Mockery::mock(ClientTracker::class);
        $tracker->shouldReceive('clearPendingRequests')->once();

        $manager = Mockery::mock(RecallManager::class);
        $manager->shouldReceive('getTracker')->andReturn($tracker);

        $store = new RecallStore($redisStore, $localCache, $manager);

        $this->assertTrue($store->flush());
    }

    public function testGetPrefixDelegatesToRedisStore(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('getPrefix')->once()->andReturn('laravel_cache:');

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $manager = Mockery::mock(RecallManager::class);

        $store = new RecallStore($redisStore, $localCache, $manager);

        $this->assertEquals('laravel_cache:', $store->getPrefix());
    }
}
