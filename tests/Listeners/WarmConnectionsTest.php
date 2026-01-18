<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests\Listeners;

use Mockery;
use PHPUnit\Framework\TestCase;
use DefectiveCode\Recall\RecallManager;
use Laravel\Octane\Events\WorkerStarting;
use DefectiveCode\Recall\Tests\RequiresExtensions;
use DefectiveCode\Recall\Listeners\WarmConnections;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class WarmConnectionsTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RequiresExtensions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireOctane();
    }

    public function testHandleCallsEnableTracking(): void
    {
        $manager = Mockery::mock(RecallManager::class);
        $manager->shouldReceive('enableTracking')->once();

        $listener = new WarmConnections($manager);

        $event = Mockery::mock(WorkerStarting::class);

        $listener->handle($event);
    }
}
