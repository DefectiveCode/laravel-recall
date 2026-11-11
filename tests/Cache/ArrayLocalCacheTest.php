<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests\Cache;

use stdClass;
use PHPUnit\Framework\TestCase;
use DefectiveCode\Recall\Cache\ArrayLocalCache;

class ArrayLocalCacheTest extends TestCase
{
    public function testItStoresAndReturnsValues(): void
    {
        $cache = new ArrayLocalCache;

        $this->assertNull($cache->get('missing'));
        $this->assertTrue($cache->put('key', ['a' => 1]));
        $this->assertSame(['a' => 1], $cache->get('key'));
    }

    public function testItExpiresEntries(): void
    {
        $cache = new ArrayLocalCache(['default_ttl' => 1]);

        $cache->put('key', 'value');
        $cache->put('other', 'value', 100);

        sleep(2);

        $this->assertNull($cache->get('key'));
        $this->assertSame('value', $cache->get('other'));
    }

    public function testItForgetsEntries(): void
    {
        $cache = new ArrayLocalCache;

        $cache->put('key', 'value');

        $this->assertTrue($cache->forget('key'));
        $this->assertFalse($cache->forget('key'));
        $this->assertNull($cache->get('key'));
    }

    public function testItFlushesEntries(): void
    {
        $cache = new ArrayLocalCache;

        $cache->put('a', 1);
        $cache->put('b', 2);

        $this->assertTrue($cache->flush());
        $this->assertNull($cache->get('a'));
        $this->assertNull($cache->get('b'));
    }

    public function testItEvictsTheLeastRecentlyUsedEntryWhenFull(): void
    {
        $cache = new ArrayLocalCache(['max_entries' => 2]);

        $cache->put('a', 1);
        $cache->put('b', 2);
        $cache->get('a');
        $cache->put('c', 3);

        $this->assertSame(1, $cache->get('a'));
        $this->assertNull($cache->get('b'));
        $this->assertSame(3, $cache->get('c'));
    }

    public function testItDoesNotEvictWhenOverwritingAnExistingKey(): void
    {
        $cache = new ArrayLocalCache(['max_entries' => 2]);

        $cache->put('a', 1);
        $cache->put('b', 2);
        $cache->put('a', 10);

        $this->assertSame(10, $cache->get('a'));
        $this->assertSame(2, $cache->get('b'));
    }

    public function testItIsolatesStoredObjectsFromMutation(): void
    {
        $cache = new ArrayLocalCache;
        $object = new stdClass;
        $object->value = 1;

        $cache->put('key', $object);
        $object->value = 2;
        $cache->get('key')->value = 3;

        $this->assertSame(1, $cache->get('key')->value);
    }
}
