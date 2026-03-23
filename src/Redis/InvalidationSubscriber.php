<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Redis;

use DefectiveCode\Recall\Protocol\RespParser;
use DefectiveCode\Recall\Protocol\RespWriter;
use DefectiveCode\Recall\Protocol\Types\Error;
use DefectiveCode\Recall\Protocol\Types\Integer;
use DefectiveCode\Recall\Protocol\Types\RespNull;
use DefectiveCode\Recall\Protocol\Types\RespArray;
use DefectiveCode\Recall\Protocol\Types\BulkString;
use DefectiveCode\Recall\Exceptions\ConnectionException;

class InvalidationSubscriber
{
    public const INVALIDATION_CHANNEL = '__redis__:invalidate';

    protected ?StreamSocket $socket = null;

    protected ?RespParser $parser = null;

    protected RespWriter $writer;

    protected int $connectionId = 0;

    protected bool $subscribed = false;

    /**
     * @param  array{host: string, port: int, password?: string|null, username?: string|null, database?: int, timeout?: float, scheme?: string}  $config
     */
    public function __construct(
        protected array $config,
    ) {
        $this->writer = new RespWriter;
    }

    public function connect(): void
    {
        $this->socket = new StreamSocket(
            $this->config['host'],
            $this->config['port'],
            $this->config['timeout'] ?? 5.0,
            $this->config['scheme'] ?? 'tcp',
        );

        $this->socket->connect();
        $this->parser = new RespParser($this->socket);

        $this->authenticate();
        $this->selectDatabase();
        $this->fetchConnectionId();
    }

    public function getConnectionId(): int
    {
        return $this->connectionId;
    }

    public function subscribe(): void
    {
        if ($this->subscribed) {
            return;
        }

        $command = $this->writer->subscribe(self::INVALIDATION_CHANNEL);
        $this->socket->write($command);

        $this->parser->parse();
        $this->socket->setNonBlocking();
        $this->subscribed = true;
    }

    /**
     * Read all pending invalidation messages (non-blocking).
     *
     * @return array<string> List of invalidated keys, or ['__flush_all__'] for FLUSHALL/FLUSHDB
     */
    public function readInvalidations(): array
    {
        if (! $this->subscribed || $this->socket === null) {
            return [];
        }

        $invalidatedKeys = [];

        while ($this->socket->hasData()) {
            try {
                $message = $this->parser->parse();
                $keys = $this->parseInvalidationMessage($message);
                $invalidatedKeys = array_merge($invalidatedKeys, $keys);
            } catch (\Throwable) {
                break;
            }
        }

        return $invalidatedKeys;
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            $this->socket->close();
            $this->socket = null;
        }
        $this->subscribed = false;
    }

    public function isConnected(): bool
    {
        return $this->socket !== null && $this->socket->isConnected();
    }

    protected function fetchConnectionId(): void
    {
        $command = $this->writer->clientId();
        $this->socket->write($command);

        $response = $this->parser->parse();

        if ($response instanceof Integer) {
            $this->connectionId = $response->getValue();
        } else {
            throw new ConnectionException('Failed to get connection ID');
        }
    }

    /**
     * @return array<string>
     */
    protected function parseInvalidationMessage(mixed $message): array
    {
        if (! $message instanceof RespArray) {
            return [];
        }

        $elements = $message->getElements();

        if (count($elements) < 3) {
            return [];
        }

        $type = $elements[0] instanceof BulkString ? $elements[0]->getValue() : '';
        $channel = $elements[1] instanceof BulkString ? $elements[1]->getValue() : '';

        if ($type !== 'message' || $channel !== self::INVALIDATION_CHANNEL) {
            return [];
        }

        $keysElement = $elements[2];

        if ($keysElement instanceof RespNull) {
            return ['__flush_all__'];
        }

        if ($keysElement instanceof RespArray) {
            $keys = [];
            foreach ($keysElement->getElements() as $keyElement) {
                if ($keyElement instanceof BulkString) {
                    $keys[] = $keyElement->getValue();
                }
            }

            return $keys;
        }

        return [];
    }

    protected function authenticate(): void
    {
        $password = $this->config['password'] ?? null;

        if ($password === null || $password === '') {
            return;
        }

        $username = $this->config['username'] ?? null;
        $command = $this->writer->auth($password, $username);

        $this->socket->write($command);
        $response = $this->parser->parse();

        if ($response instanceof Error) {
            throw new ConnectionException("Authentication failed: {$response->getValue()}");
        }
    }

    protected function selectDatabase(): void
    {
        $database = $this->config['database'] ?? 0;

        if ($database === 0) {
            return;
        }

        $command = $this->writer->select($database);
        $this->socket->write($command);
        $response = $this->parser->parse();

        if ($response instanceof Error) {
            throw new ConnectionException("Failed to select database: {$response->getValue()}");
        }
    }
}
