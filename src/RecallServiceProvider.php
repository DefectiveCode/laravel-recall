<?php

declare(strict_types=1);

namespace DefectiveCode\Recall;

use Illuminate\Cache\Repository;
use Illuminate\Cache\CacheManager;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use Illuminate\Queue\Events\JobProcessing;
use Laravel\Octane\Events\RequestReceived;
use Illuminate\Contracts\Foundation\Application;
use DefectiveCode\Recall\Listeners\WarmConnections;
use DefectiveCode\Recall\Listeners\CloseConnections;
use DefectiveCode\Recall\Listeners\ProcessInvalidations;
use DefectiveCode\Recall\Listeners\ProcessQueueInvalidations;
use Illuminate\Queue\Events\WorkerStopping as QueueWorkerStopping;

class RecallServiceProvider extends ServiceProvider
{
    public const DATA_TABLE = 'recall';

    public const EPOCH_TABLE = 'recall_epochs';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/recall.php', 'recall');

        $this->app->singleton(RecallManager::class, function (Application $app) {
            return new RecallManager($app);
        });

        $this->app->alias(RecallManager::class, 'recall');

        $this->registerOctaneTables();
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/recall.php' => config_path('recall.php'),
        ], 'recall-config');

        $this->registerCacheDriver();
        $this->registerOctaneListeners();
        $this->registerQueueListeners();
    }

    protected function registerOctaneTables(): void
    {
        $config = $this->app['config'];

        if (
            ! $config->get('recall.local_cache.shared', false)
            || $config->get('recall.local_cache.driver') !== 'swoole'
        ) {
            return;
        }

        $dataName = self::DATA_TABLE.':'.$config->get('recall.local_cache.table_size', 65536);
        $epochName = self::EPOCH_TABLE.':'.((int) $config->get('recall.local_cache.epoch_stripes', 1024) * 2);

        $tables = $config->get('octane.tables', []);

        foreach ($tables as $name => $columns) {
            if (str_starts_with((string) $name, self::DATA_TABLE.':') || str_starts_with((string) $name, self::EPOCH_TABLE.':')) {
                unset($tables[$name]);
            }
        }

        $tables[$dataName] = [
            'value' => 'string:'.$config->get('recall.local_cache.value_size', 8192),
            'expires_at' => 'int',
        ];
        $tables[$epochName] = ['epoch' => 'int'];

        $config->set('octane.tables', $tables);
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
        if (! class_exists(RequestReceived::class) || ! ($this->app['config']['recall.enabled'] ?? true)) {
            return;
        }

        $this->app->make(RecallManager::class);

        $events = $this->app['events'];

        if ($this->app['config']['recall.listeners.warm'] ?? true) {
            $events->listen(WorkerStarting::class, WarmConnections::class);
        }

        $events->listen(RequestReceived::class, ProcessInvalidations::class);

        if ($this->app['config']['recall.listeners.tick'] ?? false) {
            $events->listen(TickReceived::class, ProcessInvalidations::class);
        }

        $events->listen(WorkerStopping::class, CloseConnections::class);
    }

    protected function registerQueueListeners(): void
    {
        if (! ($this->app['config']['recall.enabled'] ?? true) || ! ($this->app['config']['recall.listeners.queue'] ?? true)) {
            return;
        }

        $this->app->make(RecallManager::class);

        $events = $this->app['events'];

        $events->listen(Looping::class, ProcessQueueInvalidations::class);
        $events->listen(JobProcessing::class, ProcessQueueInvalidations::class);
        $events->listen(QueueWorkerStopping::class, CloseConnections::class);
    }
}
