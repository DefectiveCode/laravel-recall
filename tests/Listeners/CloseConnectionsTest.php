<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests\Listeners;

use Mockery;
use PHPUnit\Framework\TestCase;
use DefectiveCode\Recall\RecallManager;
use Laravel\Octane\Events\WorkerStopping;
use DefectiveCode\Recall\Tests\RequiresExtensions;
use DefectiveCode\Recall\Listeners\CloseConnections;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class CloseConnectionsTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RequiresExtensions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireOctane();
    }

    public function testHandleCallsDisconnect(): void
    {
        $manager = Mockery::mock(RecallManager::class);
        $manager->shouldReceive('disconnect')->once();

        $listener = new CloseConnections($manager);

        $event = Mockery::mock(WorkerStopping::class);

        $listener->handle($event);
    }
}
