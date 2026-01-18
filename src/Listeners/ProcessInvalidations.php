<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Listeners;

use DefectiveCode\Recall\RecallManager;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\RequestReceived;

class ProcessInvalidations
{
    public function __construct(
        protected RecallManager $manager,
    ) {}

    public function handle(RequestReceived|TickReceived $event): void
    {
        $this->manager->processInvalidations();
    }
}
