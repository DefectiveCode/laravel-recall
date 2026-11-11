<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests\Cache;

use stdClass;
use PHPUnit\Framework\TestCase;
use DefectiveCode\Recall\Cache\SwooleTableCache;
use DefectiveCode\Recall\Tests\RequiresExtensions;

class SwooleTableCacheTest extends TestCase
{
    use RequiresExtensions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireSwoole();
    }

    public function testGetReturnsNullForMissingKey(): void
    {
        $cache = new SwooleTableCache;

        $this->assertNull($cache->get('nonexistent'));
    }

    public function testPutAndGetValue(): void
    {
        $cache = new SwooleTableCache;

        $cache->put('mykey', 'myvalue');
        $this->assertEquals('myvalue', $cache->get('mykey'));
    }

    public function testPutAndGetArray(): void
    {
        $cache = new SwooleTableCache;

        $data = ['foo' => 'bar', 'baz' => 123];
        $cache->put('arraykey', $data);

        $this->assertEquals($data, $cache->get('arraykey'));
    }

    public function testPutAndGetObject(): void
    {
        $cache = new SwooleTableCache;

        $obj = new stdClass;
        $obj->name = 'test';
        $cache->put('objkey', $obj);

        $retrieved = $cache->get('objkey');
        $this->assertInstanceOf(stdClass::class, $retrieved);
        $this->assertEquals('test', $retrieved->name);
    }

    public function testItDoesNotStoreAnOversizeValue(): void
    {
        $cache = new SwooleTableCache(['table_size' => 64, 'value_size' => 1024]);

        $cache->put('big', 'small');
        $stored = $cache->put('big', str_repeat('x', 4096));

        $this->assertFalse($stored);
        $this->assertNull($cache->get('big'));
    }

    public function testItStoresAValueAtTheSizeLimit(): void
    {
        $cache = new SwooleTableCache(['table_size' => 64, 'value_size' => 1024]);
        $value = str_repeat('x', 1014);

        $this->assertSame(1024, strlen(serialize($value)));
        $this->assertTrue($cache->put('exact', $value));
        $this->assertSame($value, $cache->get('exact'));
    }

    public function testItDoesNotStoreAValueOneByteOverTheSizeLimit(): void
    {
        $cache = new SwooleTableCache(['table_size' => 64, 'value_size' => 1024]);
        $value = str_repeat('x', 1015);

        $this->assertSame(1025, strlen(serialize($value)));
        $this->assertFalse($cache->put('over', $value));
        $this->assertNull($cache->get('over'));
    }

    public function testForgetRemovesKey(): void
    {
        $cache = new SwooleTableCache;

        $cache->put('toforget', 'value');
        $this->assertEquals('value', $cache->get('toforget'));

        $cache->forget('toforget');
        $this->assertNull($cache->get('toforget'));
    }

    public function testFlushRemovesKeys(): void
    {
        $cache = new SwooleTableCache;

        $cache->put('key1', 'value1');
        $cache->put('key2', 'value2');

        $cache->flush();

        $this->assertNull($cache->get('key1'));
        $this->assertNull($cache->get('key2'));
    }

    public function testExpiredValueReturnsNull(): void
    {
        $cache = new SwooleTableCache(['default_ttl' => 1]);

        $cache->put('expiring', 'value', 1);

        $this->assertEquals('value', $cache->get('expiring'));

        sleep(2);

        $this->assertNull($cache->get('expiring'));
    }

    public function testUsesDefaultTtl(): void
    {
        $cache = new SwooleTableCache(['default_ttl' => 3600]);

        $cache->put('key', 'value');
        $this->assertEquals('value', $cache->get('key'));
    }

    public function testUsesCustomTtl(): void
    {
        $cache = new SwooleTableCache;

        $cache->put('key', 'value', 60);
        $this->assertEquals('value', $cache->get('key'));
    }

    public function testDefaultKeyPrefix(): void
    {
        $cache = new SwooleTableCache;

        $cache->put('testkey', 'testvalue');
        $this->assertEquals('testvalue', $cache->get('testkey'));
    }

    public function testCustomTableSize(): void
    {
        $cache = new SwooleTableCache([
            'table_size' => 1024,
            'value_size' => 4096,
        ]);

        $cache->put('key', 'value');
        $this->assertEquals('value', $cache->get('key'));
    }

    public function testLongKeyIsHashedAndWorks(): void
    {
        $cache = new SwooleTableCache;

        $longKey = 'core.workspace.system.database.core.organizations.columns';
        $cache->put($longKey, 'value');

        $this->assertEquals('value', $cache->get($longKey));

        $cache->forget($longKey);
        $this->assertNull($cache->get($longKey));
    }
}
