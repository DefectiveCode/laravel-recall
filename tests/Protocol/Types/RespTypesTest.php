<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests\Protocol\Types;

use PHPUnit\Framework\TestCase;
use DefectiveCode\Recall\Protocol\Types\Push;
use DefectiveCode\Recall\Protocol\Types\Error;
use DefectiveCode\Recall\Protocol\Types\Integer;
use DefectiveCode\Recall\Protocol\Types\RespNull;
use DefectiveCode\Recall\Protocol\Types\RespArray;
use DefectiveCode\Recall\Protocol\Types\BulkString;
use DefectiveCode\Recall\Protocol\Types\SimpleString;

class RespTypesTest extends TestCase
{
    public function testSimpleStringStoresValue(): void
    {
        $string = new SimpleString('OK');

        $this->assertEquals('OK', $string->getValue());
        $this->assertEquals('simple_string', $string->getType());
    }

    public function testErrorParsesPrefixAndMessage(): void
    {
        $error = new Error('ERR unknown command');

        $this->assertEquals('ERR', $error->getPrefix());
        $this->assertEquals('unknown command', $error->getMessage());
        $this->assertEquals('ERR unknown command', $error->getValue());
        $this->assertEquals('error', $error->getType());
    }

    public function testErrorHandlesPrefixOnly(): void
    {
        $error = new Error('WRONGTYPE');

        $this->assertEquals('WRONGTYPE', $error->getPrefix());
        $this->assertEquals('', $error->getMessage());
    }

    public function testIntegerStoresValue(): void
    {
        $int = new Integer(42);

        $this->assertEquals(42, $int->getValue());
        $this->assertEquals('integer', $int->getType());
    }

    public function testIntegerStoresNegative(): void
    {
        $int = new Integer(-100);

        $this->assertEquals(-100, $int->getValue());
    }

    public function testBulkStringStoresValue(): void
    {
        $bulk = new BulkString('hello world');

        $this->assertEquals('hello world', $bulk->getValue());
        $this->assertEquals('bulk_string', $bulk->getType());
    }

    public function testBulkStringHandlesEmpty(): void
    {
        $bulk = new BulkString('');

        $this->assertEquals('', $bulk->getValue());
    }

    public function testRespNullReturnsNull(): void
    {
        $null = new RespNull;

        $this->assertNull($null->getValue());
        $this->assertEquals('null', $null->getType());
    }

    public function testRespArrayStoresElements(): void
    {
        $elements = [
            new SimpleString('OK'),
            new Integer(42),
            new BulkString('test'),
        ];

        $array = new RespArray($elements);

        $this->assertEquals($elements, $array->getValue());
        $this->assertEquals($elements, $array->getElements());
        $this->assertEquals(3, $array->count());
        $this->assertEquals('array', $array->getType());
    }

    public function testRespArrayGetElement(): void
    {
        $elements = [
            new SimpleString('first'),
            new SimpleString('second'),
        ];

        $array = new RespArray($elements);

        $this->assertInstanceOf(SimpleString::class, $array->get(0));
        $this->assertEquals('first', $array->get(0)->getValue());
        $this->assertEquals('second', $array->get(1)->getValue());
        $this->assertNull($array->get(99));
    }

    public function testRespArrayHandlesEmpty(): void
    {
        $array = new RespArray([]);

        $this->assertEquals([], $array->getElements());
        $this->assertEquals(0, $array->count());
    }

    public function testPushStoresElements(): void
    {
        $elements = [
            new SimpleString('invalidate'),
            new RespArray([new BulkString('key1'), new BulkString('key2')]),
        ];

        $push = new Push($elements);

        $this->assertEquals($elements, $push->getValue());
        $this->assertEquals($elements, $push->getElements());
        $this->assertEquals(2, $push->count());
        $this->assertEquals('push', $push->getType());
    }

    public function testPushGetKind(): void
    {
        $push = new Push([
            new SimpleString('invalidate'),
            new RespArray([]),
        ]);

        $this->assertEquals('invalidate', $push->getKind());
    }

    public function testPushGetKindWithBulkString(): void
    {
        $push = new Push([
            new BulkString('message'),
            new BulkString('channel'),
        ]);

        $this->assertEquals('message', $push->getKind());
    }

    public function testPushGetKindReturnsNullForEmpty(): void
    {
        $push = new Push([]);

        $this->assertNull($push->getKind());
    }
}
