<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests\Redis;

use ReflectionClass;
use ReflectionProperty;
use PHPUnit\Framework\TestCase;
use DefectiveCode\Recall\Redis\StreamSocket;
use DefectiveCode\Recall\Protocol\RespParser;
use DefectiveCode\Recall\Protocol\Types\RespNull;
use DefectiveCode\Recall\Protocol\Types\RespArray;
use DefectiveCode\Recall\Protocol\Types\BulkString;
use DefectiveCode\Recall\Protocol\Types\SimpleString;
use DefectiveCode\Recall\Redis\InvalidationSubscriber;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use DefectiveCode\Recall\Exceptions\ConnectionException;

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

        return $method->invokeArgs($object, $parameters);
    }

    public function testItSendsTrackingBeforeSubscribeWithAllPrefixes(): void
    {
        $peer = $this->attachSocket(['tracking_prefixes' => ['app:users:', 'app:settings:']]);
        fwrite($peer, "+OK\r\n*3\r\n\$9\r\nsubscribe\r\n\$20\r\n__redis__:invalidate\r\n:1\r\n");

        $this->subscriber->subscribe();

        $sent = stream_get_contents($peer);
        $tracking = strpos($sent, "CLIENT\r\n\$8\r\nTRACKING\r\n\$2\r\nON\r\n\$8\r\nREDIRECT\r\n\$2\r\n77\r\n\$5\r\nBCAST");

        $this->assertNotFalse($tracking);
        $this->assertStringContainsString("PREFIX\r\n\$10\r\napp:users:\r\n\$6\r\nPREFIX\r\n\$13\r\napp:settings:", $sent);
        $this->assertGreaterThan($tracking, strpos($sent, 'SUBSCRIBE'));
    }

    public function testItSkipsTrackingWithoutPrefixes(): void
    {
        $peer = $this->attachSocket([]);
        fwrite($peer, "*3\r\n\$9\r\nsubscribe\r\n\$20\r\n__redis__:invalidate\r\n:1\r\n");

        $this->subscriber->subscribe();

        $this->assertStringNotContainsString('TRACKING', stream_get_contents($peer));
    }

    public function testItFailsTheConnectWhenTrackingIsRejected(): void
    {
        $peer = $this->attachSocket(['tracking_prefixes' => ['app:']]);
        fwrite($peer, "-ERR Prefix 'app:' overlaps with an existing prefix\r\n");

        try {
            $this->subscriber->subscribe();
            $this->fail('Expected a ConnectionException');
        } catch (ConnectionException) {
            $this->assertFalse($this->subscriber->isConnected());
        }
    }

    public function testItFailsTheConnectWhenSubscribeIsRejected(): void
    {
        $peer = $this->attachSocket([]);
        fwrite($peer, "-NOPERM this user has no permissions to access one of the channels\r\n");

        try {
            $this->subscriber->subscribe();
            $this->fail('Expected a ConnectionException');
        } catch (ConnectionException) {
            $this->assertFalse($this->subscriber->isConnected());
        }
    }

    public function testItFailsTheConnectWhenTheSubscribeReplyIsUnexpected(): void
    {
        $peer = $this->attachSocket([]);
        fwrite($peer, "+OK\r\n");

        $this->expectException(ConnectionException::class);

        $this->subscriber->subscribe();
    }

    /**
     * @param  array<string, mixed>  $config
     * @return resource
     */
    protected function attachSocket(array $config)
    {
        [$local, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $socket = new StreamSocket('127.0.0.1', 6379);
        (new ReflectionProperty(StreamSocket::class, 'socket'))->setValue($socket, $local);
        (new ReflectionProperty(StreamSocket::class, 'connected'))->setValue($socket, true);

        $subscriber = new InvalidationSubscriber(['host' => '127.0.0.1', 'port' => 6379] + $config);
        (new ReflectionProperty(InvalidationSubscriber::class, 'socket'))->setValue($subscriber, $socket);
        (new ReflectionProperty(InvalidationSubscriber::class, 'parser'))->setValue($subscriber, new RespParser($socket));
        (new ReflectionProperty(InvalidationSubscriber::class, 'connectionId'))->setValue($subscriber, 77);
        $this->subscriber = $subscriber;

        stream_set_blocking($peer, false);

        return $peer;
    }
}
