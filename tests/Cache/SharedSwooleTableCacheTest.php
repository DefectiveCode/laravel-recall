<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests\Cache;

use Swoole\Table;
use ErrorException;
use PHPUnit\Framework\TestCase;
use DefectiveCode\Recall\Tests\RequiresExtensions;
use DefectiveCode\Recall\Cache\SharedSwooleTableCache;
use DefectiveCode\Recall\Exceptions\LocalCacheException;

class SharedSwooleTableCacheTest extends TestCase
{
    use RequiresExtensions;

    protected Table $data;

    protected Table $epochs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireSwoole();

        $this->data = new Table(256, 1);
        $this->data->column('value', Table::TYPE_STRING, 1024);
        $this->data->column('expires_at', Table::TYPE_INT);
        $this->data->create();

        $this->epochs = new Table(64, 1);
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

    public function testItPutFencedStoresWhenTheEpochIsUnchanged(): void
    {
        $cache = $this->cache();

        $fence = $cache->fence('k');

        $this->assertTrue($cache->putFenced('k', 'v', $fence));
        $this->assertSame('v', $cache->get('k'));
    }

    public function testItPutFencedRefusesAfterAForgetInTheSameStripe(): void
    {
        $cache = $this->cache(['epoch_stripes' => 1]);

        $fence = $cache->fence('k');
        $cache->forget('other');

        $this->assertFalse($cache->putFenced('k', 'v', $fence));
        $this->assertNull($cache->get('k'));
    }

    public function testItPutFencedRefusesAfterAFlush(): void
    {
        $cache = $this->cache(['epoch_stripes' => 4]);

        $fence = $cache->fence('k');
        $cache->flush();

        $this->assertFalse($cache->putFenced('k', 'v', $fence));
        $this->assertNull($cache->get('k'));
    }

    public function testItInstancesOverTheSameTablesShareValuesAndEpochs(): void
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

    public function testItPreCreatesEveryEpochStripe(): void
    {
        $this->cache(['epoch_stripes' => 8]);

        $this->assertSame(8, $this->epochs->count());
    }

    public function testItFailsAtBootWhenTheEpochTableIsTooSmall(): void
    {
        $this->expectException(LocalCacheException::class);

        @$this->cache(['epoch_stripes' => 100000]);
    }

    public function testItSkipsTheFillWhenTheDataTableIsFull(): void
    {
        $data = new Table(8, 1);
        $data->column('value', Table::TYPE_STRING, 1024);
        $data->column('expires_at', Table::TYPE_INT);
        $data->create();

        $cache = new SharedSwooleTableCache($data, $this->epochs, ['value_size' => 1024, 'epoch_stripes' => 8]);

        set_error_handler(static function (int $severity, string $message): bool {
            if (! (error_reporting() & $severity)) {
                return false;
            }

            throw new ErrorException($message, 0, $severity);
        });

        try {
            $results = [];

            for ($i = 0; $i < 4096; $i++) {
                $results[] = $cache->put("k{$i}", 'v');
            }
        } finally {
            restore_error_handler();
        }

        $this->assertContains(false, $results);
        $this->assertContains(true, $results);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function cache(array $config = []): SharedSwooleTableCache
    {
        return new SharedSwooleTableCache($this->data, $this->epochs, $config + ['value_size' => 1024, 'epoch_stripes' => 8]);
    }
}
