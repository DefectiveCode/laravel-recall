<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Redis;

use DefectiveCode\Recall\Exceptions\ConnectionException;

class StreamSocket
{
    /** @var resource|null */
    protected $socket = null;

    protected bool $connected = false;

    public function __construct(
        protected string $host,
        protected int $port,
        protected float $timeout = 5.0,
        protected string $scheme = 'tcp',
    ) {}

    public function connect(): void
    {
        $errno = 0;
        $errstr = '';

        $isTls = in_array($this->scheme, ['tls', 'ssl'], true);
        $protocol = $isTls ? 'tls' : 'tcp';

        $this->socket = @stream_socket_client(
            "{$protocol}://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
        );

        if ($this->socket === false) {
            throw new ConnectionException(
                "Failed to connect to Redis at {$this->host}:{$this->port}: {$errstr}",
                $errno,
            );
        }

        stream_set_timeout(
            $this->socket,
            (int) $this->timeout,
            (int) (($this->timeout - (int) $this->timeout) * 1000000),
        );

        $this->connected = true;
    }

    public function setNonBlocking(): void
    {
        if ($this->socket !== null) {
            stream_set_blocking($this->socket, false);
        }
    }

    public function write(string $data): int
    {
        $this->ensureConnected();

        $written = @fwrite($this->socket, $data);

        if ($written === false) {
            $this->connected = false;
            throw new ConnectionException('Failed to write to socket');
        }

        return $written;
    }

    public function read(int $length): string
    {
        $this->ensureConnected();

        $data = @fread($this->socket, $length);

        if ($data === false) {
            $this->connected = false;
            throw new ConnectionException('Failed to read from socket');
        }

        return $data;
    }

    public function readLine(): string
    {
        $this->ensureConnected();

        $line = @fgets($this->socket);

        if ($line === false) {
            if (@feof($this->socket)) {
                $this->connected = false;
                throw new ConnectionException('Connection closed by server');
            }

            return '';
        }

        return rtrim($line, "\r\n");
    }

    public function hasData(): bool
    {
        if (! $this->connected || $this->socket === null) {
            return false;
        }

        $read = [$this->socket];
        $write = [];
        $except = [];

        $changed = @stream_select($read, $write, $except, 0, 0);

        return $changed > 0;
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
            $this->connected = false;
        }
    }

    public function isConnected(): bool
    {
        if (! $this->connected || $this->socket === null) {
            return false;
        }

        return ! @feof($this->socket);
    }

    protected function ensureConnected(): void
    {
        if (! $this->isConnected()) {
            throw new ConnectionException('Not connected to Redis server');
        }
    }
}
