<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Does in boot() what apps do: registers a named rate limiter and resolves
 * the DynamoDB cache store and what's built on it, all before any test's
 * set-up runs. None of it calls DynamoDB, so it works with the config's
 * unreachable endpoint.
 */
final class BootsLikeAnApp extends ServiceProvider
{
    public static ?Repository $storeResolvedInBoot = null;

    public function boot(): void
    {
        // Not 'api': Testbench registers its own one of those whenever
        // cache.store is resolved.
        RateLimiter::for('uploads', static fn (): Limit => Limit::perMinute(10)->by('the-client'));

        if (config('cache.stores.dynamodb.driver') === 'dynamodb') {
            self::$storeResolvedInBoot = Cache::store('dynamodb');
            Cache::memo('dynamodb');
            $this->app->make('cache.store');
            $this->app->make('cache.psr6');
        }

        if (config('session.driver') === 'dynamodb') {
            $this->app->make('session.store');
        }

        // Laravel's generated failed-job config names no region, and
        // resolving it without one fails, so only a complete one is resolved.
        if (config('queue.failed.driver') === 'dynamodb' && config('queue.failed.region') !== null) {
            $this->app->make('queue.failer');
        }
    }
}
