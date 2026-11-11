<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Listeners;

use Illuminate\Queue\Events\Looping;
use DefectiveCode\Recall\RecallManager;
use Illuminate\Queue\Events\JobProcessing;

class ProcessQueueInvalidations
{
    public function __construct(
        protected RecallManager $manager,
    ) {}

    public function handle(Looping|JobProcessing $event): void
    {
        if ($event instanceof Looping) {
            $this->manager->activateForQueue();
        }

        if ($this->manager->isActive()) {
            $this->manager->processInvalidations();
        }
    }
}
