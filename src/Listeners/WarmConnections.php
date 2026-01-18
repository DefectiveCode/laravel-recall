<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Listeners;

use DefectiveCode\Recall\RecallManager;
use Laravel\Octane\Events\WorkerStarting;

class WarmConnections
{
    public function __construct(
        protected RecallManager $manager,
    ) {}

    public function handle(WorkerStarting $event): void
    {
        $this->manager->enableTracking();
    }
}
