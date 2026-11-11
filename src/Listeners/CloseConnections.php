<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Listeners;

use DefectiveCode\Recall\RecallManager;
use Laravel\Octane\Events\WorkerStopping;
use Illuminate\Queue\Events\WorkerStopping as QueueWorkerStopping;

class CloseConnections
{
    public function __construct(
        protected RecallManager $manager,
    ) {}

    public function handle(WorkerStopping|QueueWorkerStopping $event): void
    {
        $this->manager->disconnect();
    }
}
