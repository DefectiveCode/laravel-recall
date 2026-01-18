<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests\Protocol;

use Mockery;
use PHPUnit\Framework\TestCase;
use DefectiveCode\Recall\Redis\StreamSocket;
use DefectiveCode\Recall\Protocol\RespParser;
use DefectiveCode\Recall\Protocol\Types\Push;
use DefectiveCode\Recall\Protocol\Types\Error;
use DefectiveCode\Recall\Protocol\Types\Integer;
use DefectiveCode\Recall\Protocol\Types\RespNull;
use DefectiveCode\Recall\Protocol\Types\RespArray;
use DefectiveCode\Recall\Protocol\Types\BulkString;
use DefectiveCode\Recall\Protocol\Types\SimpleString;
use DefectiveCode\Recall\Exceptions\ProtocolException;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class RespParserTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private StreamSocket $socket;

    private RespParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->socket = Mockery::mock(StreamSocket::class);
        $this->parser = new RespParser($this->socket);
    }

    public function testParsesSimpleString(): void
    {
        $this->socket->shouldReceive('readLine')->once()->andReturn('+OK');

        $result = $this->parser->parse();

        $this->assertInstanceOf(SimpleString::class, $result);
        $this->assertEquals('OK', $result->getValue());
    }

    public function testParsesError(): void
    {
        $this->socket->shouldReceive('readLine')->once()->andReturn('-ERR unknown command');

        $result = $this->parser->parse();

        $this->assertInstanceOf(Error::class, $result);
        $this->assertEquals('ERR unknown command', $result->getValue());
    }

    public function testParsesInteger(): void
    {
        $this->socket->shouldReceive('readLine')->once()->andReturn(':1000');

        $result = $this->parser->parse();

        $this->assertInstanceOf(Integer::class, $result);
        $this->assertEquals(1000, $result->getValue());
    }

    public function testParsesNegativeInteger(): void
    {
        $this->socket->shouldReceive('readLine')->once()->andReturn(':-50');

        $result = $this->parser->parse();

        $this->assertInstanceOf(Integer::class, $result);
        $this->assertEquals(-50, $result->getValue());
    }

    public function testParsesBulkString(): void
    {
        $this->socket->shouldReceive('readLine')->once()->andReturn('$6');
        $this->socket->shouldReceive('read')->with(6)->once()->andReturn('foobar');
        $this->socket->shouldReceive('read')->with(2)->once()->andReturn("\r\n");

        $result = $this->parser->parse();

        $this->assertInstanceOf(BulkString::class, $result);
        $this->assertEquals('foobar', $result->getValue());
    }

    public function testParsesNullBulkString(): void
    {
        $this->socket->shouldReceive('readLine')->once()->andReturn('$-1');

        $result = $this->parser->parse();

        $this->assertInstanceOf(RespNull::class, $result);
        $this->assertNull($result->getValue());
    }

    public function testParsesEmptyBulkString(): void
    {
        $this->socket->shouldReceive('readLine')->once()->andReturn('$0');
        $this->socket->shouldReceive('read')->with(2)->once()->andReturn("\r\n");

        $result = $this->parser->parse();

        $this->assertInstanceOf(BulkString::class, $result);
        $this->assertEquals('', $result->getValue());
    }

    public function testParsesArray(): void
    {
        $this->socket->shouldReceive('readLine')->once()->andReturn('*2');
        $this->socket->shouldReceive('readLine')->once()->andReturn('$3');
        $this->socket->shouldReceive('read')->with(3)->once()->andReturn('foo');
        $this->socket->shouldReceive('read')->with(2)->once()->andReturn("\r\n");
        $this->socket->shouldReceive('readLine')->once()->andReturn('$3');
        $this->socket->shouldReceive('read')->with(3)->once()->andReturn('bar');
        $this->socket->shouldReceive('read')->with(2)->once()->andReturn("\r\n");

        $result = $this->parser->parse();

        $this->assertInstanceOf(RespArray::class, $result);
        $this->assertEquals(2, $result->count());
        $this->assertEquals('foo', $result->get(0)->getValue());
        $this->assertEquals('bar', $result->get(1)->getValue());
    }

    public function testParsesEmptyArray(): void
    {
        $this->socket->shouldReceive('readLine')->once()->andReturn('*0');

        $result = $this->parser->parse();

        $this->assertInstanceOf(RespArray::class, $result);
        $this->assertEquals(0, $result->count());
    }

    public function testParsesNullArray(): void
    {
        $this->socket->shouldReceive('readLine')->once()->andReturn('*-1');

        $result = $this->parser->parse();

        $this->assertInstanceOf(RespNull::class, $result);
    }

    public function testParsesResp3Null(): void
    {
        $this->socket->shouldReceive('readLine')->once()->andReturn('_');

        $result = $this->parser->parse();

        $this->assertInstanceOf(RespNull::class, $result);
    }

    public function testParsesPush(): void
    {
        $this->socket->shouldReceive('readLine')->once()->andReturn('>2');
        $this->socket->shouldReceive('readLine')->once()->andReturn('+invalidate');
        $this->socket->shouldReceive('readLine')->once()->andReturn('*1');
        $this->socket->shouldReceive('readLine')->once()->andReturn('$3');
        $this->socket->shouldReceive('read')->with(3)->once()->andReturn('key');
        $this->socket->shouldReceive('read')->with(2)->once()->andReturn("\r\n");

        $result = $this->parser->parse();

        $this->assertInstanceOf(Push::class, $result);
        $this->assertEquals(2, $result->count());
        $this->assertEquals('invalidate', $result->getKind());
    }

    public function testParsesBooleanTrue(): void
    {
        $this->socket->shouldReceive('readLine')->once()->andReturn('#t');

        $result = $this->parser->parse();

        $this->assertInstanceOf(SimpleString::class, $result);
        $this->assertEquals('true', $result->getValue());
    }

    public function testParsesBooleanFalse(): void
    {
        $this->socket->shouldReceive('readLine')->once()->andReturn('#f');

        $result = $this->parser->parse();

        $this->assertInstanceOf(SimpleString::class, $result);
        $this->assertEquals('false', $result->getValue());
    }

    public function testParsesDouble(): void
    {
        $this->socket->shouldReceive('readLine')->once()->andReturn(',1.23');

        $result = $this->parser->parse();

        $this->assertInstanceOf(SimpleString::class, $result);
        $this->assertEquals('1.23', $result->getValue());
    }

    public function testParsesBigNumber(): void
    {
        $this->socket->shouldReceive('readLine')->once()->andReturn('(12345678901234567890');

        $result = $this->parser->parse();

        $this->assertInstanceOf(SimpleString::class, $result);
        $this->assertEquals('12345678901234567890', $result->getValue());
    }

    public function testParsesBlobError(): void
    {
        $this->socket->shouldReceive('readLine')->once()->andReturn('!11');
        $this->socket->shouldReceive('read')->with(11)->once()->andReturn('SYNTAX test');
        $this->socket->shouldReceive('read')->with(2)->once()->andReturn("\r\n");

        $result = $this->parser->parse();

        $this->assertInstanceOf(Error::class, $result);
        $this->assertEquals('SYNTAX test', $result->getValue());
    }

    public function testParsesVerbatimString(): void
    {
        $this->socket->shouldReceive('readLine')->once()->andReturn('=15');
        $this->socket->shouldReceive('read')->with(15)->once()->andReturn('txt:Hello World');
        $this->socket->shouldReceive('read')->with(2)->once()->andReturn("\r\n");

        $result = $this->parser->parse();

        $this->assertInstanceOf(BulkString::class, $result);
        $this->assertEquals('Hello World', $result->getValue());
    }

    public function testParsesMap(): void
    {
        $this->socket->shouldReceive('readLine')->once()->andReturn('%2');
        $this->socket->shouldReceive('readLine')->once()->andReturn('+key1');
        $this->socket->shouldReceive('readLine')->once()->andReturn(':1');
        $this->socket->shouldReceive('readLine')->once()->andReturn('+key2');
        $this->socket->shouldReceive('readLine')->once()->andReturn(':2');

        $result = $this->parser->parse();

        $this->assertInstanceOf(RespArray::class, $result);
        $this->assertEquals(4, $result->count());
        $this->assertEquals('key1', $result->get(0)->getValue());
        $this->assertEquals(1, $result->get(1)->getValue());
    }

    public function testParsesSet(): void
    {
        $this->socket->shouldReceive('readLine')->once()->andReturn('~3');
        $this->socket->shouldReceive('readLine')->once()->andReturn(':1');
        $this->socket->shouldReceive('readLine')->once()->andReturn(':2');
        $this->socket->shouldReceive('readLine')->once()->andReturn(':3');

        $result = $this->parser->parse();

        $this->assertInstanceOf(RespArray::class, $result);
        $this->assertEquals(3, $result->count());
    }

    public function testThrowsOnEmptyResponse(): void
    {
        $this->socket->shouldReceive('readLine')->once()->andReturn('');

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Empty response from server');

        $this->parser->parse();
    }

    public function testThrowsOnUnknownType(): void
    {
        $this->socket->shouldReceive('readLine')->once()->andReturn('@unknown');

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Unknown RESP type: @');

        $this->parser->parse();
    }
}
