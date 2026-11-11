<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests\Cache;

use Redis;
use Mockery;
use RuntimeException;
use PHPUnit\Framework\TestCase;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Store;
use DefectiveCode\Recall\RecallManager;
use DefectiveCode\Recall\Cache\RecallStore;
use Illuminate\Contracts\Cache\LockProvider;
use DefectiveCode\Recall\Tracking\ClientTracker;
use DefectiveCode\Recall\Tracking\PendingRequest;
use DefectiveCode\Recall\Contracts\FencedLocalCache;
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
        $manager = $this->activeManager();

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

        $manager = $this->activeManager();

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

        $manager = $this->activeManager();
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

        $manager = $this->activeManager();
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

        $manager = $this->activeManager();

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

        $manager = $this->activeManager();

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

        $manager = $this->activeManager();
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

        $manager = $this->activeManager();

        $store = new RecallStore($redisStore, $localCache, $manager, ['enabled' => true]);

        $result = $store->many(['key1', 'key2']);

        $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], $result);
    }

    public function testPutDelegatesToRedisStore(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('put')->with('key', 'value', 60)->once()->andReturn(true);

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $manager = $this->activeManager();

        $store = new RecallStore($redisStore, $localCache, $manager, ['enabled' => false]);

        $this->assertTrue($store->put('key', 'value', 60));
    }

    public function testPutManyDelegatesToRedisStore(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('putMany')->with(['key1' => 'value1'], 60)->once()->andReturn(true);

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $manager = $this->activeManager();

        $store = new RecallStore($redisStore, $localCache, $manager, ['enabled' => false]);

        $this->assertTrue($store->putMany(['key1' => 'value1'], 60));
    }

    public function testIncrementDelegatesToRedisStore(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('increment')->with('counter', 1)->once()->andReturn(5);

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $manager = $this->activeManager();

        $store = new RecallStore($redisStore, $localCache, $manager, ['enabled' => false]);

        $this->assertEquals(5, $store->increment('counter'));
    }

    public function testDecrementDelegatesToRedisStore(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('decrement')->with('counter', 1)->once()->andReturn(3);

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $manager = $this->activeManager();

        $store = new RecallStore($redisStore, $localCache, $manager, ['enabled' => false]);

        $this->assertEquals(3, $store->decrement('counter'));
    }

    public function testForeverDelegatesToRedisStore(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('forever')->with('key', 'value')->once()->andReturn(true);

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $manager = $this->activeManager();

        $store = new RecallStore($redisStore, $localCache, $manager, ['enabled' => false]);

        $this->assertTrue($store->forever('key', 'value'));
    }

    public function testTouchExpiresRedisKeyAndClearsLocalCache(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('getPrefix')->andReturn('laravel_cache:');

        $connection = Mockery::mock(PhpRedisConnection::class);
        $redis = Mockery::mock(Redis::class);
        $redis->shouldReceive('getOption')->with(Redis::OPT_PREFIX)->andReturn('');
        $connection->shouldReceive('client')->andReturn($redis);
        $connection->shouldReceive('expire')->with('laravel_cache:key', 120)->once()->andReturn(1);
        $redisStore->shouldReceive('connection')->andReturn($connection);

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $localCache->shouldReceive('forget')->with('laravel_cache:key')->once()->andReturn(true);

        $manager = $this->activeManager();

        $store = new RecallStore($redisStore, $localCache, $manager);

        $this->assertTrue($store->touch('key', 120));
    }

    public function testTouchDoesNotClearLocalCacheWhenDisabled(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('getPrefix')->once()->andReturn('laravel_cache:');

        $connection = Mockery::mock(PhpRedisConnection::class);
        $connection->shouldReceive('expire')->with('laravel_cache:key', 1)->once()->andReturn(true);
        $redisStore->shouldReceive('connection')->once()->andReturn($connection);

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $localCache->shouldNotReceive('forget');

        $manager = $this->activeManager();

        $store = new RecallStore($redisStore, $localCache, $manager, ['enabled' => false]);

        $this->assertTrue($store->touch('key', 0));
    }

    public function testForgetDelegatesToRedisStore(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('forget')->with('key')->once()->andReturn(true);

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $manager = $this->activeManager();

        $store = new RecallStore($redisStore, $localCache, $manager, ['enabled' => false]);

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

        $manager = $this->activeManager();
        $manager->shouldReceive('getTracker')->andReturn($tracker);

        $store = new RecallStore($redisStore, $localCache, $manager);

        $this->assertTrue($store->flush());
    }

    public function testGetPrefixDelegatesToRedisStore(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('getPrefix')->once()->andReturn('laravel_cache:');

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $manager = $this->activeManager();

        $store = new RecallStore($redisStore, $localCache, $manager);

        $this->assertEquals('laravel_cache:', $store->getPrefix());
    }

    public function testImplementsLockProvider(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $localCache = Mockery::mock(LocalCacheInterface::class);
        $manager = $this->activeManager();

        $store = new RecallStore($redisStore, $localCache, $manager);

        $this->assertInstanceOf(LockProvider::class, $store);
    }

    public function testLockDelegatesToRedisStore(): void
    {
        $lock = Mockery::mock(Lock::class);

        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('lock')->with('my-lock', 10, 'owner-token')->once()->andReturn($lock);

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $manager = $this->activeManager();

        $store = new RecallStore($redisStore, $localCache, $manager);

        $this->assertSame($lock, $store->lock('my-lock', 10, 'owner-token'));
    }

    public function testLockDelegatesToRedisStoreWithDefaults(): void
    {
        $lock = Mockery::mock(Lock::class);

        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('lock')->with('my-lock', 0, null)->once()->andReturn($lock);

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $manager = $this->activeManager();

        $store = new RecallStore($redisStore, $localCache, $manager);

        $this->assertSame($lock, $store->lock('my-lock'));
    }

    public function testRestoreLockDelegatesToRedisStore(): void
    {
        $lock = Mockery::mock(Lock::class);

        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('restoreLock')->with('my-lock', 'owner-token')->once()->andReturn($lock);

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $manager = $this->activeManager();

        $store = new RecallStore($redisStore, $localCache, $manager);

        $this->assertSame($lock, $store->restoreLock('my-lock', 'owner-token'));
    }

    public function testWritePathsForgetTheLocalCopy(): void
    {
        $redisStore = $this->redisStoreWithPrefix();
        $redisStore->shouldReceive('put')->once()->andReturn(true);
        $redisStore->shouldReceive('putMany')->once()->andReturn(true);
        $redisStore->shouldReceive('increment')->once()->andReturn(2);
        $redisStore->shouldReceive('decrement')->once()->andReturn(1);
        $redisStore->shouldReceive('forever')->once()->andReturn(true);
        $redisStore->shouldReceive('forget')->once()->andReturn(true);

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $localCache->shouldReceive('forget')->with('p:a')->times(5)->andReturn(true);
        $localCache->shouldReceive('forget')->with('p:b')->times(2)->andReturn(true);

        $store = new RecallStore($redisStore, $localCache, $this->activeManager());

        $store->put('a', 1, 60);
        $store->putMany(['a' => 1, 'b' => 2], 60);
        $store->increment('a');
        $store->decrement('a');
        $store->forever('a', 1);
        $store->forget('b');
    }

    public function testWriteForgetsTheLocalCopyWhenRedisThrows(): void
    {
        $redisStore = $this->redisStoreWithPrefix();
        $redisStore->shouldReceive('put')->once()->andThrow(new RuntimeException('down'));

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $localCache->shouldReceive('forget')->with('p:a')->once()->andReturn(true);

        $store = new RecallStore($redisStore, $localCache, $this->activeManager());

        $this->expectException(RuntimeException::class);

        $store->put('a', 1, 60);
    }

    public function testWriteDoesNotForgetKeysOutsideCachePrefixes(): void
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('put')->once()->andReturn(true);

        $localCache = Mockery::mock(LocalCacheInterface::class);
        $localCache->shouldNotReceive('forget');

        $store = new RecallStore($redisStore, $localCache, $this->activeManager(), [
            'cache_prefixes' => ['users:'],
        ]);

        $store->put('other:1', 1, 60);
    }

    public function testGetFillsAFencedLocalCacheWithTheFenceTakenBeforeRedis(): void
    {
        $redisStore = $this->redisStoreWithPrefix();
        $redisStore->shouldReceive('get')->with('a')->once()->andReturn('v');

        $localCache = Mockery::mock(FencedLocalCache::class);
        $localCache->shouldReceive('get')->with('p:a')->once()->andReturn(null);
        $localCache->shouldReceive('fence')->with('p:a')->once()->andReturn(7);
        $localCache->shouldReceive('putFenced')->with('p:a', 'v', 7)->once()->andReturn(true);
        $localCache->shouldNotReceive('put');

        $pendingRequest = new PendingRequest('p:a');
        $tracker = Mockery::mock(ClientTracker::class);
        $tracker->shouldReceive('registerPendingRequest')->andReturn($pendingRequest);
        $tracker->shouldReceive('completePendingRequest')->andReturn(true);

        $manager = $this->activeManager();
        $manager->shouldReceive('getTracker')->andReturn($tracker);

        $store = new RecallStore($redisStore, $localCache, $manager);

        $this->assertSame('v', $store->get('a'));
    }

    protected function redisStoreWithPrefix(): RedisStore
    {
        $redisStore = Mockery::mock(RedisStore::class);
        $redisStore->shouldReceive('getPrefix')->andReturn('p:');

        $connection = Mockery::mock(PhpRedisConnection::class);
        $redis = Mockery::mock(Redis::class);
        $redis->shouldReceive('getOption')->with(Redis::OPT_PREFIX)->andReturn('');
        $connection->shouldReceive('client')->andReturn($redis);
        $redisStore->shouldReceive('connection')->andReturn($connection);

        return $redisStore;
    }

    protected function activeManager(): RecallManager
    {
        $manager = Mockery::mock(RecallManager::class);
        $manager->shouldReceive('isActive')->andReturn(true);
        $manager->shouldReceive('isQueueMode')->andReturn(false);

        return $manager;
    }
}
