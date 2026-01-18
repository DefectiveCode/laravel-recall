<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Tests;

use DefectiveCode\Recall\RecallServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RequiresExtensions;

    protected function getPackageProviders($app): array
    {
        return [
            RecallServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('recall.enabled', true);
    }
}
