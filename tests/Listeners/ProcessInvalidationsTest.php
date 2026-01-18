<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests\Listeners;

use Mockery;
use PHPUnit\Framework\TestCase;
use DefectiveCode\Recall\RecallManager;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\RequestReceived;
use DefectiveCode\Recall\Tests\RequiresExtensions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use DefectiveCode\Recall\Listeners\ProcessInvalidations;

class ProcessInvalidationsTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RequiresExtensions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireOctane();
    }

    public function testHandleCallsProcessInvalidationsOnRequestReceived(): void
    {
        $manager = Mockery::mock(RecallManager::class);
        $manager->shouldReceive('processInvalidations')->once();

        $listener = new ProcessInvalidations($manager);

        $event = Mockery::mock(RequestReceived::class);

        $listener->handle($event);
    }

    public function testHandleCallsProcessInvalidationsOnTickReceived(): void
    {
        $manager = Mockery::mock(RecallManager::class);
        $manager->shouldReceive('processInvalidations')->once();

        $listener = new ProcessInvalidations($manager);

        $event = Mockery::mock(TickReceived::class);

        $listener->handle($event);
    }
}
