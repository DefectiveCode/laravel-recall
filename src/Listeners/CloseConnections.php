<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Listeners;

use DefectiveCode\Recall\RecallManager;
use Laravel\Octane\Events\WorkerStopping;

class CloseConnections
{
    public function __construct(
        protected RecallManager $manager,
    ) {}

    public function handle(WorkerStopping $event): void
    {
        $this->manager->disconnect();
    }
}
