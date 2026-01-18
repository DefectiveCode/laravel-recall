# Introduction

**Recall** is a high-performance Redis client-side caching package for Laravel. It leverages Redis 6's
[client-side caching](https://redis.io/docs/latest/develop/reference/client-side-caching/) feature with automatic
invalidation to
dramatically reduce Redis round trips and latency. Built specifically for Laravel Octane environments, it uses APCu or
Swoole Table as a local cache layer that stays synchronized with Redis through invalidation messages.

When you fetch a value from Redis, Recall stores it locally. When that value changes in Redis (from any client), Redis
automatically notifies Recall to invalidate the local copy. This gives you the speed of in-memory caching with the
consistency guarantees of Redis.

## Key Features

- **Automatic Invalidation**: Redis notifies your application when cached keys change, ensuring cache coherence
- **Zero Configuration**: Works out of the box with sensible defaults
- **Octane Integration**: Automatic connection warming, request-based invalidation processing, and graceful shutdown
- **Dual Driver Support**: APCu for all Octane servers, Swoole Table for Swoole/OpenSwoole environments
- **Selective Caching**: Configure which key prefixes to cache locally
- **Race Condition Protection**: Pending request tracking prevents caching stale data during concurrent invalidations

## Example

```php
// Configure recall as your cache driver
// config/cache.php
'stores' => [
    'recall' => [
        'driver' => 'recall',
    ],
],

// Use it like any Laravel cache
use Illuminate\Support\Facades\Cache;

// First call: fetches from Redis, stores locally
$user = Cache::store('recall')->get('user:1');

// Subsequent calls: served from local APCu/Swoole Table (microseconds)
$user = Cache::store('recall')->get('user:1');

// When user:1 is updated anywhere, Redis notifies Recall to invalidate
Cache::store('recall')->put('user:1', $newUserData, 3600);
// Local cache is automatically invalidated on all workers
```

# Installation

Install the package via Composer:

```bash
composer require defectivecode/recall
```

## Requirements

- PHP >= 8.4
- Laravel 11.x or 12.x
- Laravel Octane
- Redis 6.0+ (for client-side caching support)
- ext-apcu OR ext-swoole (at least one required for local cache)

# Usage

## Basic Setup

1. Add the Recall cache store to your `config/cache.php`:

```php
'stores' => [
    // ... other stores

    'recall' => [
        'driver' => 'recall',
    ],
],
```

2. Use the cache store in your application:

```php
use Illuminate\Support\Facades\Cache;

// Store a value (writes to Redis)
Cache::store('recall')->put('key', 'value', 3600);

// Retrieve a value (first call hits Redis, subsequent calls use local cache)
$value = Cache::store('recall')->get('key');

// Delete a value
Cache::store('recall')->forget('key');
```

## How It Works

1. **First Read**: Value is fetched from Redis and stored in local APCu/Swoole Table cache
2. **Subsequent Reads**: Value is served directly from local memory (sub-millisecond)
3. **Write Anywhere**: When any client modifies the key in Redis, Redis sends an invalidation message
4. **Automatic Invalidation**: Recall receives the message and removes the key from local cache
5. **Next Read**: Fresh value is fetched from Redis and cached locally again

This pattern is especially powerful in Laravel Octane environments where workers persist between requests, allowing the
local cache to build up and serve many requests from memory.

## Octane Integration

Recall automatically integrates with Laravel Octane when available:

- **Worker Starting**: Establishes Redis invalidation connection (warm start)
- **Request Received**: Processes any pending invalidation messages
- **Worker Stopping**: Gracefully closes connections

No additional configuration is required. The integration is automatic when Octane is installed.

# Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=recall-config
```

This creates `config/recall.php` with the following options:

## Enable/Disable

```php
'enabled' => env('RECALL_ENABLED', true),
```

When disabled, Recall passes through directly to Redis without using the local cache layer. Useful for debugging or
gradual rollout.

## Redis Store

```php
'redis_store' => env('RECALL_REDIS_STORE', 'redis'),
```

The Laravel cache store to use for Redis operations. This should reference a Redis store configured in your
`config/cache.php`.

## Cache Prefixes

```php
'cache_prefixes' => [],
```

Only cache keys matching these prefixes locally. Leave empty to cache all keys.

```php
// Only cache user and settings keys locally
'cache_prefixes' => ['users:', 'settings:', 'config:'],
```

This is useful when you have high-volume keys that change frequently and shouldn't be cached locally.

## Local Cache Configuration

```php
'local_cache' => [
    // Driver: "apcu" or "swoole"
    'driver' => env('RECALL_LOCAL_DRIVER', 'apcu'),

    // Prefix for local cache keys
    'key_prefix' => env('RECALL_LOCAL_PREFIX', 'recall:'),

    // Default TTL in seconds (safety net if invalidation is missed)
    'default_ttl' => (int) env('RECALL_LOCAL_TTL', 3600),

    // Swoole Table size (power of 2, only for swoole driver)
    'table_size' => (int) env('RECALL_SWOOLE_TABLE_SIZE', 65536),

    // Max bytes per value in Swoole Table (only for swoole driver)
    'value_size' => (int) env('RECALL_SWOOLE_VALUE_SIZE', 8192),
],
```

### APCu Driver (Default)

The APCu driver works with all PHP environments and Octane servers (Swoole, RoadRunner, FrankenPHP). It stores cached
values in shared memory accessible to all PHP processes.

**Requirements:**

- ext-apcu installed and enabled
- `apc.enable_cli=1` in php.ini for CLI usage

### Swoole Table Driver

The Swoole Table driver uses Swoole's shared memory tables, providing consistent access across coroutines within the
same worker. Best for Swoole/OpenSwoole environments.

**Configuration tips:**

- `table_size`: Must be a power of 2 (e.g., 65536, 131072). Determines max number of entries.
- `value_size`: Maximum bytes for serialized values. Larger values are silently truncated.

## Octane Listeners

```php
'listeners' => [
    // Warm connection on worker start (reduces first request latency)
    'warm' => env('RECALL_LISTEN_WARM', true),

    // Process invalidations on tick events (more responsive, slight overhead)
    'tick' => env('RECALL_LISTEN_TICK', false),
],
```

### Warm Connections

When enabled, Recall establishes the Redis invalidation subscription when the Octane worker starts. This eliminates
connection latency on the first request.

### Tick Processing

When enabled, Recall processes invalidation messages on Octane tick events in addition to request events. This provides
more responsive cache invalidation at the cost of slight additional overhead.

# Advanced Usage

## Manual Invalidation Processing

If you need to manually process invalidations (e.g., in a long-running process):

```php
use DefectiveCode\Recall\RecallManager;

$manager = app(RecallManager::class);
$manager->processInvalidations();
```

## Flushing Local Cache

To clear only the local cache without affecting Redis:

```php
use DefectiveCode\Recall\RecallManager;

$manager = app(RecallManager::class);
$manager->flushLocalCache();
```

## Connection Management

```php
use DefectiveCode\Recall\RecallManager;

$manager = app(RecallManager::class);

// Check if invalidation subscription is connected
if ($manager->isConnected()) {
    // ...
}

// Manually disconnect
$manager->disconnect();
```

# Optimization

## Worker Request Limits

Laravel Octane cycles workers after a configurable number of requests to prevent memory leaks. When a worker cycles, its
local cache is cleared. Increasing this limit allows Recall's local cache to persist longer, improving cache hit rates.

In your `config/octane.php`:

```php
// Default is 500 requests before cycling
'max_requests' => 10000,
```

Higher values mean better cache utilization but require confidence that your application doesn't have memory leaks.
Monitor your worker memory usage when adjusting this value.

## Selective Caching with Prefixes

Use `cache_prefixes` to control which keys are cached locally. This is valuable when:

- **High-churn keys**: Some keys change so frequently that local caching provides little benefit
- **Large values**: Reduce memory pressure by only caching smaller, frequently-read keys
- **Sensitive data**: Keep certain data only in Redis for security or compliance reasons

```php
// config/recall.php
'cache_prefixes' => [
    'users:',      // Cache user data locally
    'settings:',   // Cache application settings
    'products:',   // Cache product catalog
],
```

Keys not matching these prefixes will still work but bypass local caching, going directly to Redis.

## Memory Considerations

### APCu Memory

APCu shares memory across all PHP processes. Configure the memory limit in `php.ini`:

```ini
; Default is 32MB, increase for larger cache needs
apc.shm_size = 128M
```

Monitor APCu usage with `apcu_cache_info()`:

```php
$info = apcu_cache_info();
$memory = $info['mem_size']; // Current memory usage in bytes
```

### Swoole Table Sizing

Swoole Tables have fixed capacity configured at creation. Plan for your expected cache size:

```php
'local_cache' => [
    // Maximum entries (must be power of 2)
    'table_size' => 65536,  // 64K entries

    // Maximum serialized value size in bytes
    'value_size' => 8192,   // 8KB per value
],
```

Memory usage: `table_size × (value_size + overhead)`. A table with 65536 entries and 8KB values uses approximately
512MB.

# Common Patterns

## Application Configuration

```php
// Cache feature flags and settings
$features = Cache::store('recall')->remember('config:features', 3600, function () {
    return Feature::all()->pluck('enabled', 'name')->toArray();
});

// When settings change, all workers automatically receive updates
```

## Frequently Accessed Reference Data

```php
// Cache product categories
$categories = Cache::store('recall')->remember('categories:all', 3600, function () {
    return Category::with('children')->whereNull('parent_id')->get();
});

// Cache currency exchange rates
$rates = Cache::store('recall')->remember('rates:exchange', 300, function () {
    return ExchangeRate::all()->pluck('rate', 'currency')->toArray();
});
```

## Cache Tags Alternative

Recall doesn't support cache tags, but you can achieve similar functionality with prefixes:

```php
// Instead of tags, use consistent prefixes
Cache::store('recall')->put("blog:posts:{$id}", $post, 3600);
Cache::store('recall')->put("blog:comments:{$postId}", $comments, 3600);

// Clear all blog-related cache by prefix (requires manual implementation)
// Or rely on automatic invalidation when data changes
```

# Limitations

## Redis Cluster Mode

Recall does not support Redis Cluster mode. The `CLIENT TRACKING` command's `REDIRECT` option requires both the data connection and invalidation subscriber connection to be on the same Redis node. In a cluster, keys are distributed across multiple nodes based on hash slots, making it impossible to receive invalidations for keys stored on different nodes.

For clustered Redis deployments, consider:

- Using a single Redis instance for cached data that benefits from client-side caching
- Using Redis Cluster for other data while keeping frequently-read, stable data on a standalone instance
