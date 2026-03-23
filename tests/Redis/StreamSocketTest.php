<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests\Redis;

use PHPUnit\Framework\TestCase;
use DefectiveCode\Recall\Redis\StreamSocket;
use DefectiveCode\Recall\Exceptions\ConnectionException;

class StreamSocketTest extends TestCase
{
    private StreamSocket $socket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->socket = new StreamSocket('127.0.0.1', 6379);
    }

    public function testConstructorStoresConfig(): void
    {
        $socket = new StreamSocket('127.0.0.1', 6379, 5.0);

        $this->assertFalse($socket->isConnected());
    }

    public function testIsConnectedReturnsFalseBeforeConnect(): void
    {
        $this->assertFalse($this->socket->isConnected());
    }

    public function testConnectThrowsOnInvalidHost(): void
    {
        $socket = new StreamSocket('invalid.host.that.does.not.exist', 6379, 0.1);

        $this->expectException(ConnectionException::class);

        $socket->connect();
    }

    public function testWriteThrowsWhenNotConnected(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Not connected to Redis server');

        $this->socket->write('PING');
    }

    public function testReadThrowsWhenNotConnected(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Not connected to Redis server');

        $this->socket->read(10);
    }

    public function testReadLineThrowsWhenNotConnected(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Not connected to Redis server');

        $this->socket->readLine();
    }

    public function testHasDataReturnsFalseWhenNotConnected(): void
    {
        $this->assertFalse($this->socket->hasData());
    }

    public function testCloseWhenNotConnected(): void
    {
        $this->socket->close();

        $this->assertFalse($this->socket->isConnected());
    }

    public function testSetNonBlockingWhenNotConnected(): void
    {
        $this->socket->setNonBlocking();

        $this->assertFalse($this->socket->isConnected());
    }

    public function testItDefaultsToTcpScheme(): void
    {
        $socket = new StreamSocket('127.0.0.1', 6379);

        $this->assertFalse($socket->isConnected());
    }

    public function testItAcceptsTlsScheme(): void
    {
        $socket = new StreamSocket('127.0.0.1', 6379, 5.0, 'tls');

        $this->assertFalse($socket->isConnected());
    }

    public function testItAcceptsSslScheme(): void
    {
        $socket = new StreamSocket('127.0.0.1', 6379, 5.0, 'ssl');

        $this->assertFalse($socket->isConnected());
    }

    public function testItThrowsConnectionExceptionForTlsOnPlainPort(): void
    {
        $socket = new StreamSocket('127.0.0.1', 6379, 0.5, 'tls');

        $this->expectException(ConnectionException::class);

        $socket->connect();
    }

    public function testItThrowsConnectionExceptionForSslOnPlainPort(): void
    {
        $socket = new StreamSocket('127.0.0.1', 6379, 0.5, 'ssl');

        $this->expectException(ConnectionException::class);

        $socket->connect();
    }
}
