<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests\Redis;

use ReflectionClass;
use PHPUnit\Framework\TestCase;
use DefectiveCode\Recall\Protocol\Types\RespNull;
use DefectiveCode\Recall\Protocol\Types\RespArray;
use DefectiveCode\Recall\Protocol\Types\BulkString;
use DefectiveCode\Recall\Protocol\Types\SimpleString;
use DefectiveCode\Recall\Redis\InvalidationSubscriber;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class InvalidationSubscriberTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private InvalidationSubscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subscriber = new InvalidationSubscriber([
            'host' => '127.0.0.1',
            'port' => 6379,
        ]);
    }

    public function testConstructorStoresConfig(): void
    {
        $this->assertFalse($this->subscriber->isConnected());
    }

    public function testGetConnectionIdReturnsZeroBeforeConnect(): void
    {
        $this->assertEquals(0, $this->subscriber->getConnectionId());
    }

    public function testIsConnectedReturnsFalseBeforeConnect(): void
    {
        $this->assertFalse($this->subscriber->isConnected());
    }

    public function testReadInvalidationsReturnsEmptyWhenNotSubscribed(): void
    {
        $this->assertEquals([], $this->subscriber->readInvalidations());
    }

    public function testCloseWhenNotConnected(): void
    {
        $this->subscriber->close();

        $this->assertFalse($this->subscriber->isConnected());
    }

    public function testParseInvalidationMessageReturnsEmptyForNonArray(): void
    {
        $result = $this->invokeMethod($this->subscriber, 'parseInvalidationMessage', [new SimpleString('OK')]);

        $this->assertEquals([], $result);
    }

    public function testParseInvalidationMessageReturnsEmptyForShortArray(): void
    {
        $message = new RespArray([
            new BulkString('message'),
        ]);

        $result = $this->invokeMethod($this->subscriber, 'parseInvalidationMessage', [$message]);

        $this->assertEquals([], $result);
    }

    public function testParseInvalidationMessageReturnsEmptyForWrongType(): void
    {
        $message = new RespArray([
            new BulkString('subscribe'),
            new BulkString(InvalidationSubscriber::INVALIDATION_CHANNEL),
            new RespArray([new BulkString('key1')]),
        ]);

        $result = $this->invokeMethod($this->subscriber, 'parseInvalidationMessage', [$message]);

        $this->assertEquals([], $result);
    }

    public function testParseInvalidationMessageReturnsEmptyForWrongChannel(): void
    {
        $message = new RespArray([
            new BulkString('message'),
            new BulkString('other_channel'),
            new RespArray([new BulkString('key1')]),
        ]);

        $result = $this->invokeMethod($this->subscriber, 'parseInvalidationMessage', [$message]);

        $this->assertEquals([], $result);
    }

    public function testParseInvalidationMessageReturnsKeys(): void
    {
        $message = new RespArray([
            new BulkString('message'),
            new BulkString(InvalidationSubscriber::INVALIDATION_CHANNEL),
            new RespArray([
                new BulkString('key1'),
                new BulkString('key2'),
            ]),
        ]);

        $result = $this->invokeMethod($this->subscriber, 'parseInvalidationMessage', [$message]);

        $this->assertEquals(['key1', 'key2'], $result);
    }

    public function testParseInvalidationMessageReturnsFlushAllForNull(): void
    {
        $message = new RespArray([
            new BulkString('message'),
            new BulkString(InvalidationSubscriber::INVALIDATION_CHANNEL),
            new RespNull,
        ]);

        $result = $this->invokeMethod($this->subscriber, 'parseInvalidationMessage', [$message]);

        $this->assertEquals(['__flush_all__'], $result);
    }

    public function testInvalidationChannelConstant(): void
    {
        $this->assertEquals('__redis__:invalidate', InvalidationSubscriber::INVALIDATION_CHANNEL);
    }

    public function testItAcceptsSchemeInConfig(): void
    {
        $subscriber = new InvalidationSubscriber([
            'host' => '127.0.0.1',
            'port' => 6379,
            'scheme' => 'tls',
        ]);

        $this->assertFalse($subscriber->isConnected());
    }

    protected function invokeMethod(object $object, string $methodName, array $parameters = []): mixed
    {
        $reflection = new ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}
