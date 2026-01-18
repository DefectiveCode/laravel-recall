<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests\Tracking;

use PHPUnit\Framework\TestCase;
use DefectiveCode\Recall\Tracking\PendingRequest;

class PendingRequestTest extends TestCase
{
    public function testStoresKey(): void
    {
        $request = new PendingRequest('mykey');

        $this->assertEquals('mykey', $request->getKey());
    }

    public function testStoresTimestamp(): void
    {
        $before = microtime(true);
        $request = new PendingRequest('mykey');
        $after = microtime(true);

        $this->assertGreaterThanOrEqual($before, $request->getTimestamp());
        $this->assertLessThanOrEqual($after, $request->getTimestamp());
    }
}
