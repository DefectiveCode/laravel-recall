<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Enable Client-Side Caching
    |--------------------------------------------------------------------------
    |
    | When disabled, the package passes through directly to Redis without
    | using the local cache layer.
    |
    */

    'enabled' => env('RECALL_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Redis Cache Store
    |--------------------------------------------------------------------------
    |
    | The Laravel cache store to use for Redis operations. This should be
    | configured in your config/cache.php stores array.
    |
    */

    'redis_store' => env('RECALL_REDIS_STORE', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Cache Prefixes
    |--------------------------------------------------------------------------
    |
    | Only keys matching these prefixes will be cached locally. Leave empty
    | to cache all keys. Example: ['users:', 'settings:', 'config:']
    |
    */

    'cache_prefixes' => [],

    /*
    |--------------------------------------------------------------------------
    | Local Cache
    |--------------------------------------------------------------------------
    |
    | Configuration for the local cache layer.
    |
    */

    'local_cache' => [

        // Driver: "apcu" (all Octane drivers) or "swoole" (Swoole/OpenSwoole only)
        'driver' => env('RECALL_LOCAL_DRIVER', 'apcu'),

        // Prefix for local cache keys to avoid collisions.
        'key_prefix' => env('RECALL_LOCAL_PREFIX', 'recall:'),

        // Default TTL in seconds. Safety net if invalidation messages are missed.
        'default_ttl' => (int) env('RECALL_LOCAL_TTL', 3600),

        // Swoole Table size (must be power of 2). Only used with swoole driver.
        'table_size' => (int) env('RECALL_SWOOLE_TABLE_SIZE', 65536),

        // Max bytes for serialized values in Swoole Table. Only used with swoole driver.
        'value_size' => (int) env('RECALL_SWOOLE_VALUE_SIZE', 8192),

    ],

    /*
    |--------------------------------------------------------------------------
    | Octane Listeners
    |--------------------------------------------------------------------------
    |
    | Configure which Octane lifecycle events are used.
    |
    */

    'listeners' => [

        // Establish Redis connection when worker starts (reduces first request latency).
        'warm' => env('RECALL_LISTEN_WARM', true),

        // Process invalidations on tick events (more responsive, slight overhead).
        'tick' => env('RECALL_LISTEN_TICK', false),

    ],

];
