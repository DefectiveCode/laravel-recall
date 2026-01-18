<p align="center">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://defectivecode.com/logos/logo-animated-dark.png">
      <img width="450" alt="Defective Code Logo" src="https://defectivecode.com/logos/logo-animated-light.png">
    </picture>
</p>

[English](https://www.defectivecode.com/packages/laravel-recall/en) |
[العربية](https://www.defectivecode.com/packages/laravel-recall/ar) |
[বাংলা](https://www.defectivecode.com/packages/laravel-recall/bn) |
[Bosanski](https://www.defectivecode.com/packages/laravel-recall/bs) |
[Deutsch](https://www.defectivecode.com/packages/laravel-recall/de) |
[Español](https://www.defectivecode.com/packages/laravel-recall/es) |
[Français](https://www.defectivecode.com/packages/laravel-recall/fr) |
[हिन्दी](https://www.defectivecode.com/packages/laravel-recall/hi) |
[Italiano](https://www.defectivecode.com/packages/laravel-recall/it) |
[日本語](https://www.defectivecode.com/packages/laravel-recall/ja) |
[한국어](https://www.defectivecode.com/packages/laravel-recall/ko) |
[मराठी](https://www.defectivecode.com/packages/laravel-recall/mr) |
[Português](https://www.defectivecode.com/packages/laravel-recall/pt) |
[Русский](https://www.defectivecode.com/packages/laravel-recall/ru) |
[Kiswahili](https://www.defectivecode.com/packages/laravel-recall/sw) |
[தமிழ்](https://www.defectivecode.com/packages/laravel-recall/ta) |
[తెలుగు](https://www.defectivecode.com/packages/laravel-recall/te) |
[Türkçe](https://www.defectivecode.com/packages/laravel-recall/tr) |
[اردو](https://www.defectivecode.com/packages/laravel-recall/ur) |
[Tiếng Việt](https://www.defectivecode.com/packages/laravel-recall/vi) |
[中文](https://www.defectivecode.com/packages/laravel-recall/zh)

# Introduction

**Recall** is a high-performance Redis client-side caching package for Laravel. It leverages Redis 6's
[client-side caching](https://redis.io/docs/latest/develop/reference/client-side-caching/) feature with automatic invalidation to
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

# Documentation

You may read the [documentation on our website](https://www.defectivecode.com/packages/laravel-recall/en).

# Support Guidelines

Thanks for choosing our open source package! Please take a moment to check out these support guidelines. They'll help
you get the most out of our project.

## Community Driven Support

Our open-source project is fueled by our awesome community. If you have questions or need assistance, StackOverflow and
other online resources are your best bets.

## Bugs, and Feature Prioritization

The reality of managing an open-source project means we can't address every reported bug or feature request immediately.
We prioritize issues in the following order:

### 1. Bugs Affecting Our Paid Products

Bugs that impact our paid products will always be our top priority. In some cases, we may only address bugs that affect
us directly.

### 2. Community Pull Requests

If you've identified a bug and have a solution, please submit a pull request. After issues affecting our products, we
give the next highest priority to these community-driven fixes. Once reviewed and approved, we'll merge your solution
and credit your contribution.

### 3. Financial Support

For issues outside the mentioned categories, you can opt to fund their resolution. Each open issue is linked to an order
form where you can contribute financially. We prioritize these issues based on the funding amount provided.

### Community Contributions

Open source thrives when its community is active. Even if you're not fixing bugs, consider contributing through code
improvements, documentation updates, tutorials, or by assisting others in community channels. We highly encourage
everyone, as a community, to help support open-source work.

_To reiterate, DefectiveCode will prioritize bugs based on how they impact our paid products, community pull requests,
and the financial support received for issues._

# License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
