<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests\Tracking;

use Mockery;
use PHPUnit\Framework\TestCase;
use DefectiveCode\Recall\Tracking\ClientTracker;
use DefectiveCode\Recall\Tracking\PendingRequest;
use DefectiveCode\Recall\Redis\InvalidationSubscriber;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use DefectiveCode\Recall\Contracts\LocalCacheInterface;

class ClientTrackerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private InvalidationSubscriber $subscriber;

    private LocalCacheInterface $localCache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subscriber = Mockery::mock(InvalidationSubscriber::class);
        $this->localCache = Mockery::mock(LocalCacheInterface::class);
    }

    public function testRegisterPendingRequest(): void
    {
        $tracker = new ClientTracker($this->subscriber, $this->localCache);
        $request = $tracker->registerPendingRequest('mykey');

        $this->assertInstanceOf(PendingRequest::class, $request);
        $this->assertEquals('mykey', $request->getKey());
    }

    public function testCompletePendingRequestReturnsTrue(): void
    {
        $tracker = new ClientTracker($this->subscriber, $this->localCache);
        $request = $tracker->registerPendingRequest('mykey');

        $this->assertTrue($tracker->completePendingRequest($request));
    }

    public function testCompletePendingRequestReturnsFalseWhenNotRegistered(): void
    {
        $tracker = new ClientTracker($this->subscriber, $this->localCache);
        $request = new PendingRequest('unknownkey');

        $this->assertFalse($tracker->completePendingRequest($request));
    }

    public function testCompletePendingRequestReturnsFalseWhenTimestampMismatch(): void
    {
        $tracker = new ClientTracker($this->subscriber, $this->localCache);
        $tracker->registerPendingRequest('mykey');

        usleep(1000);
        $differentRequest = new PendingRequest('mykey');

        $this->assertFalse($tracker->completePendingRequest($differentRequest));
    }

    public function testProcessInvalidationsDoesNothingWhenNotConnected(): void
    {
        $this->subscriber->shouldReceive('isConnected')->once()->andReturn(false);
        $this->subscriber->shouldNotReceive('readInvalidations');

        $tracker = new ClientTracker($this->subscriber, $this->localCache);
        $tracker->processInvalidations();
    }

    public function testProcessInvalidationsRemovesKeysFromLocalCache(): void
    {
        $this->subscriber->shouldReceive('isConnected')->once()->andReturn(true);
        $this->subscriber->shouldReceive('readInvalidations')->once()->andReturn(['key1', 'key2']);

        $this->localCache->shouldReceive('forget')->with('key1')->once();
        $this->localCache->shouldReceive('forget')->with('key2')->once();

        $tracker = new ClientTracker($this->subscriber, $this->localCache);
        $tracker->processInvalidations();
    }

    public function testHandleInvalidationFlushAllClearsEntireCache(): void
    {
        $this->subscriber->shouldReceive('isConnected')->once()->andReturn(true);
        $this->subscriber->shouldReceive('readInvalidations')->once()->andReturn(['__flush_all__']);

        $this->localCache->shouldReceive('flush')->once();

        $tracker = new ClientTracker($this->subscriber, $this->localCache);
        $tracker->registerPendingRequest('pending1');
        $tracker->processInvalidations();

        $pendingRequest = new PendingRequest('pending1');
        $this->assertFalse($tracker->completePendingRequest($pendingRequest));
    }

    public function testInvalidationRemovesPendingRequest(): void
    {
        $this->subscriber->shouldReceive('isConnected')->once()->andReturn(true);
        $this->subscriber->shouldReceive('readInvalidations')->once()->andReturn(['mykey']);

        $this->localCache->shouldReceive('forget')->with('mykey')->once();

        $tracker = new ClientTracker($this->subscriber, $this->localCache);
        $request = $tracker->registerPendingRequest('mykey');
        $tracker->processInvalidations();

        $this->assertFalse($tracker->completePendingRequest($request));
    }

    public function testClearPendingRequests(): void
    {
        $tracker = new ClientTracker($this->subscriber, $this->localCache);
        $request = $tracker->registerPendingRequest('mykey');

        $tracker->clearPendingRequests();

        $this->assertFalse($tracker->completePendingRequest($request));
    }
}
