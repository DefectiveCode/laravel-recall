<?php

declare(strict_types=1);

namespace DefectiveCode\Recall;

use Illuminate\Cache\Repository;
use Illuminate\Cache\CacheManager;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use Laravel\Octane\Events\RequestReceived;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use DefectiveCode\Recall\Listeners\WarmConnections;
use DefectiveCode\Recall\Listeners\CloseConnections;
use DefectiveCode\Recall\Listeners\ProcessInvalidations;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

class RecallServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/recall.php', 'recall');

        $this->app->singleton(RecallManager::class, function (Application $app) {
            return new RecallManager($app);
        });

        $this->app->alias(RecallManager::class, 'recall');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/recall.php' => config_path('recall.php'),
        ], 'recall-config');

        $this->registerCacheDriver();
        $this->registerOctaneListeners();
    }

    protected function registerCacheDriver(): void
    {
        $this->app->resolving('cache', function (CacheManager $cacheManager): void {
            $cacheManager->extend('recall', function (Application $app) {
                /** @var RecallManager $manager */
                $manager = $app->make(RecallManager::class);

                return new Repository($manager->store());
            });
        });
    }

    protected function registerOctaneListeners(): void
    {
        $config = $this->app->make(ConfigRepository::class);

        if (! class_exists(RequestReceived::class) || ! ($config->get('recall.enabled') ?? true)) {
            return;
        }

        $this->app->make(RecallManager::class);

        $events = $this->app->make(Dispatcher::class);

        if ($config->get('recall.listeners.warm') ?? true) {
            $events->listen(WorkerStarting::class, WarmConnections::class);
        }

        $events->listen(RequestReceived::class, ProcessInvalidations::class);

        if ($config->get('recall.listeners.tick') ?? false) {
            $events->listen(TickReceived::class, ProcessInvalidations::class);
        }

        $events->listen(WorkerStopping::class, CloseConnections::class);
    }
}
