<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Protocol;

use DefectiveCode\Recall\Redis\StreamSocket;
use DefectiveCode\Recall\Protocol\Types\Push;
use DefectiveCode\Recall\Protocol\Types\Error;
use DefectiveCode\Recall\Protocol\Types\Integer;
use DefectiveCode\Recall\Protocol\Types\RespNull;
use DefectiveCode\Recall\Protocol\Types\RespType;
use DefectiveCode\Recall\Protocol\Types\RespArray;
use DefectiveCode\Recall\Protocol\Types\BulkString;
use DefectiveCode\Recall\Protocol\Types\SimpleString;
use DefectiveCode\Recall\Exceptions\ProtocolException;

/**
 * Parser for the Redis Serialization Protocol (RESP).
 *
 * RESP uses a type prefix byte followed by data:
 *
 * RESP2:
 *   +  Simple String: +OK\r\n
 *   -  Error:         -ERR message\r\n
 *   :  Integer:       :1000\r\n
 *   $  Bulk String:   $6\r\nfoobar\r\n (or $-1\r\n for null)
 *   *  Array:         *2\r\n$3\r\nfoo\r\n$3\r\nbar\r\n
 *
 * RESP3 additions:
 *   _  Null:          _\r\n
 *   >  Push:          >2\r\n... (server-initiated messages)
 *   #  Boolean:       #t\r\n or #f\r\n
 *   ,  Double:        ,1.23\r\n
 *   (  Big Number:    (12345678901234567890\r\n
 *   !  Blob Error:    !<len>\r\n<error>\r\n
 *   =  Verbatim:      =<len>\r\ntxt:<data>\r\n
 *   %  Map:           %2\r\n... (key-value pairs)
 *   ~  Set:           ~2\r\n... (unique elements)
 *
 * @see https://redis.io/docs/latest/develop/reference/protocol-spec/
 */
class RespParser
{
    public function __construct(
        protected StreamSocket $socket,
    ) {}

    public function parse(): RespType
    {
        $line = $this->socket->readLine();

        if ($line === '') {
            throw new ProtocolException('Empty response from server');
        }

        $type = $line[0];
        $data = substr($line, 1);

        return match ($type) {
            '+' => new SimpleString($data),
            '-' => new Error($data),
            ':' => new Integer((int) $data),
            '$' => $this->parseBulkString((int) $data),
            '*' => $this->parseArray((int) $data),
            '_' => new RespNull,
            '>' => $this->parsePush((int) $data),
            '#' => $this->parseBoolean($data),
            ',' => $this->parseDouble($data),
            '(' => $this->parseBigNumber($data),
            '!' => $this->parseBlobError((int) $data),
            '=' => $this->parseVerbatimString((int) $data),
            '%' => $this->parseMap((int) $data),
            '~' => $this->parseSet((int) $data),
            default => throw new ProtocolException("Unknown RESP type: {$type}"),
        };
    }

    protected function parseBulkString(int $length): BulkString|RespNull
    {
        if ($length === -1) {
            return new RespNull;
        }

        $data = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = $this->socket->read(min($remaining, 8192));
            if ($chunk === '') {
                throw new ProtocolException('Unexpected end of stream while reading bulk string');
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        $this->socket->read(2);

        return new BulkString($data);
    }

    protected function parseArray(int $count): RespArray|RespNull
    {
        if ($count === -1) {
            return new RespNull;
        }

        $elements = [];

        for ($i = 0; $i < $count; $i++) {
            $elements[] = $this->parse();
        }

        return new RespArray($elements);
    }

    protected function parsePush(int $count): Push
    {
        $elements = [];

        for ($i = 0; $i < $count; $i++) {
            $elements[] = $this->parse();
        }

        return new Push($elements);
    }

    protected function parseBoolean(string $data): SimpleString
    {
        return new SimpleString($data === 't' ? 'true' : 'false');
    }

    protected function parseDouble(string $data): SimpleString
    {
        return new SimpleString($data);
    }

    protected function parseBigNumber(string $data): SimpleString
    {
        return new SimpleString($data);
    }

    protected function parseBlobError(int $length): Error
    {
        $data = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = $this->socket->read(min($remaining, 8192));
            if ($chunk === '') {
                throw new ProtocolException('Unexpected end of stream while reading blob error');
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        $this->socket->read(2);

        return new Error($data);
    }

    protected function parseVerbatimString(int $length): BulkString
    {
        $data = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = $this->socket->read(min($remaining, 8192));
            if ($chunk === '') {
                throw new ProtocolException('Unexpected end of stream while reading verbatim string');
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        $this->socket->read(2);

        if (strlen($data) > 4 && $data[3] === ':') {
            $data = substr($data, 4);
        }

        return new BulkString($data);
    }

    protected function parseMap(int $count): RespArray
    {
        $elements = [];

        for ($i = 0; $i < $count; $i++) {
            $elements[] = $this->parse();
            $elements[] = $this->parse();
        }

        return new RespArray($elements);
    }

    protected function parseSet(int $count): RespArray
    {
        $elements = [];

        for ($i = 0; $i < $count; $i++) {
            $elements[] = $this->parse();
        }

        return new RespArray($elements);
    }
}
