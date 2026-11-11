<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests\Cache;

use Swoole\Table;
use PHPUnit\Framework\TestCase;
use DefectiveCode\Recall\Tests\RequiresExtensions;
use DefectiveCode\Recall\Cache\SharedSwooleTableCache;

class SharedSwooleTableCacheTest extends TestCase
{
    use RequiresExtensions;

    protected Table $data;

    protected Table $epochs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireSwoole();

        $this->data = new Table(256);
        $this->data->column('value', Table::TYPE_STRING, 1024);
        $this->data->column('expires_at', Table::TYPE_INT);
        $this->data->create();

        $this->epochs = new Table(64);
        $this->epochs->column('epoch', Table::TYPE_INT);
        $this->epochs->create();
    }

    public function testItPutsGetsAndForgets(): void
    {
        $cache = $this->cache();

        $this->assertNull($cache->get('k'));

        $cache->put('k', ['a' => 1]);
        $this->assertSame(['a' => 1], $cache->get('k'));

        $cache->forget('k');
        $this->assertNull($cache->get('k'));
    }

    public function testItExpiresValues(): void
    {
        $cache = $this->cache();

        $cache->put('k', 'v', 1);
        $this->assertSame('v', $cache->get('k'));

        sleep(2);

        $this->assertNull($cache->get('k'));
    }

    public function testItDoesNotStoreAnOversizeValue(): void
    {
        $cache = $this->cache();

        $cache->put('k', 'small');

        $this->assertFalse($cache->put('k', str_repeat('x', 4096)));
        $this->assertNull($cache->get('k'));
    }

    public function testItFlushesValues(): void
    {
        $cache = $this->cache();

        $cache->put('a', 1);
        $cache->put('b', 2);
        $cache->flush();

        $this->assertNull($cache->get('a'));
        $this->assertNull($cache->get('b'));
    }

    public function testPutFencedStoresWhenTheEpochIsUnchanged(): void
    {
        $cache = $this->cache();

        $fence = $cache->fence('k');

        $this->assertTrue($cache->putFenced('k', 'v', $fence));
        $this->assertSame('v', $cache->get('k'));
    }

    public function testPutFencedRefusesAfterAForgetInTheSameStripe(): void
    {
        $cache = $this->cache(['epoch_stripes' => 1]);

        $fence = $cache->fence('k');
        $cache->forget('other');

        $this->assertFalse($cache->putFenced('k', 'v', $fence));
        $this->assertNull($cache->get('k'));
    }

    public function testPutFencedRefusesAfterAFlush(): void
    {
        $cache = $this->cache(['epoch_stripes' => 4]);

        $fence = $cache->fence('k');
        $cache->flush();

        $this->assertFalse($cache->putFenced('k', 'v', $fence));
        $this->assertNull($cache->get('k'));
    }

    public function testInstancesOverTheSameTablesShareValuesAndEpochs(): void
    {
        $workerA = $this->cache();
        $workerB = $this->cache();

        $workerA->put('k', 'v');
        $this->assertSame('v', $workerB->get('k'));

        $fence = $workerA->fence('k');
        $workerB->forget('k');

        $this->assertNull($workerA->get('k'));
        $this->assertFalse($workerA->putFenced('k', 'stale', $fence));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function cache(array $config = []): SharedSwooleTableCache
    {
        return new SharedSwooleTableCache($this->data, $this->epochs, $config + ['value_size' => 1024, 'epoch_stripes' => 8]);
    }
}
