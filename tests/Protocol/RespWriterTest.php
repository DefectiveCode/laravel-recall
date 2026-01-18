<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use DefectiveCode\Recall\Protocol\RespWriter;

class RespWriterTest extends TestCase
{
    private RespWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = new RespWriter;
    }

    public function testCommandSerializesSimpleCommand(): void
    {
        $result = $this->writer->command('PING');

        $this->assertEquals("*1\r\n\$4\r\nPING\r\n", $result);
    }

    public function testCommandSerializesCommandWithArgs(): void
    {
        $result = $this->writer->command('SET', ['key', 'value']);

        $this->assertEquals("*3\r\n\$3\r\nSET\r\n\$3\r\nkey\r\n\$5\r\nvalue\r\n", $result);
    }

    public function testAuthWithPasswordOnly(): void
    {
        $result = $this->writer->auth('secret');

        $this->assertEquals("*2\r\n\$4\r\nAUTH\r\n\$6\r\nsecret\r\n", $result);
    }

    public function testAuthWithUsernameAndPassword(): void
    {
        $result = $this->writer->auth('secret', 'user');

        $this->assertEquals("*3\r\n\$4\r\nAUTH\r\n\$4\r\nuser\r\n\$6\r\nsecret\r\n", $result);
    }

    public function testSelectDatabase(): void
    {
        $result = $this->writer->select(2);

        $this->assertEquals("*2\r\n\$6\r\nSELECT\r\n\$1\r\n2\r\n", $result);
    }

    public function testClientId(): void
    {
        $result = $this->writer->clientId();

        $this->assertEquals("*2\r\n\$6\r\nCLIENT\r\n\$2\r\nID\r\n", $result);
    }

    public function testSubscribe(): void
    {
        $result = $this->writer->subscribe('channel1', 'channel2');

        $this->assertEquals("*3\r\n\$9\r\nSUBSCRIBE\r\n\$8\r\nchannel1\r\n\$8\r\nchannel2\r\n", $result);
    }
}
