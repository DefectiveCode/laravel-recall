<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests;

use Laravel\Octane\Events\WorkerStarting;

trait RequiresExtensions
{
    protected function requireApcu(): void
    {
        if (! extension_loaded('apcu') || ! apcu_enabled()) {
            $this->fail('APCu extension is required but not available. Please install and enable APCu.');
        }
    }

    protected function requireSwoole(): void
    {
        if (! extension_loaded('swoole') && ! extension_loaded('openswoole')) {
            $this->fail('Swoole or OpenSwoole extension is required but not available. Please install and enable Swoole or OpenSwoole.');
        }
    }

    protected function requireOctane(): void
    {
        if (! class_exists(WorkerStarting::class)) {
            $this->fail('Laravel Octane is required but not installed. Please install Laravel Octane.');
        }
    }
}
