<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Listeners;

use Throwable;
use Illuminate\Queue\Events\Looping;
use DefectiveCode\Recall\RecallManager;
use Illuminate\Queue\Events\JobProcessing;

class ProcessQueueInvalidations
{
    protected bool $reported = false;

    public function __construct(
        protected RecallManager $manager,
    ) {}

    public function handle(Looping|JobProcessing $event): void
    {
        if ($event instanceof Looping) {
            $this->manager->activateForQueue();
        }

        if (! $this->manager->isActive()) {
            return;
        }

        try {
            $this->manager->processInvalidations();
            $this->reported = false;
        } catch (Throwable $e) {
            if (! $this->reported) {
                report($e);
            }

            $this->reported = true;

            $this->manager->deactivate();
        }
    }
}
