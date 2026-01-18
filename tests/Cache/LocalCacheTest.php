<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests\Cache;

use stdClass;
use PHPUnit\Framework\TestCase;
use DefectiveCode\Recall\Cache\LocalCache;
use DefectiveCode\Recall\Tests\RequiresExtensions;

class LocalCacheTest extends TestCase
{
    use RequiresExtensions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireApcu();
    }

    protected function tearDown(): void
    {
        apcu_clear_cache();
        parent::tearDown();
    }

    public function testGetReturnsNullForMissingKey(): void
    {
        $cache = new LocalCache;

        $this->assertNull($cache->get('nonexistent'));
    }

    public function testPutAndGetValue(): void
    {
        $cache = new LocalCache;

        $cache->put('mykey', 'myvalue');
        $this->assertEquals('myvalue', $cache->get('mykey'));
    }

    public function testPutAndGetArray(): void
    {
        $cache = new LocalCache;

        $data = ['foo' => 'bar', 'baz' => 123];
        $cache->put('arraykey', $data);

        $this->assertEquals($data, $cache->get('arraykey'));
    }

    public function testPutAndGetObject(): void
    {
        $cache = new LocalCache;

        $obj = new stdClass;
        $obj->name = 'test';
        $cache->put('objkey', $obj);

        $retrieved = $cache->get('objkey');
        $this->assertInstanceOf(stdClass::class, $retrieved);
        $this->assertEquals('test', $retrieved->name);
    }

    public function testForgetRemovesKey(): void
    {
        $cache = new LocalCache;

        $cache->put('toforget', 'value');
        $this->assertEquals('value', $cache->get('toforget'));

        $cache->forget('toforget');
        $this->assertNull($cache->get('toforget'));
    }

    public function testFlushRemovesOnlyPrefixedKeys(): void
    {
        $cache = new LocalCache(['key_prefix' => 'test_flush:']);

        $cache->put('key1', 'value1');
        $cache->put('key2', 'value2');

        apcu_store('other_prefix:key', 'should_remain');

        $cache->flush();

        $this->assertNull($cache->get('key1'));
        $this->assertNull($cache->get('key2'));

        $success = false;
        $remaining = apcu_fetch('other_prefix:key', $success);
        $this->assertTrue($success);
        $this->assertEquals('should_remain', $remaining);
    }

    public function testUsesDefaultTtl(): void
    {
        $cache = new LocalCache(['default_ttl' => 3600]);

        $cache->put('key', 'value');
        $this->assertEquals('value', $cache->get('key'));
    }

    public function testUsesCustomTtl(): void
    {
        $cache = new LocalCache;

        $cache->put('key', 'value', 60);
        $this->assertEquals('value', $cache->get('key'));
    }

    public function testDefaultKeyPrefix(): void
    {
        $cache = new LocalCache;

        $cache->put('testkey', 'testvalue');
        $this->assertEquals('testvalue', $cache->get('testkey'));

        $success = false;
        apcu_fetch('recall:testkey', $success);
        $this->assertTrue($success);
    }
}
