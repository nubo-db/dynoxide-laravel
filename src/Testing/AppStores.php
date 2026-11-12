<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Testing;

use Dynoxide\Laravel\CacheStoresInUse;
use Dynoxide\Laravel\Exception\ConfigException;
use Dynoxide\Server;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Facade;

/**
 * What the trait reads from and changes in an app: its DynamoDB stores, what
 * boot() resolved from them, and whether anything uses them. It keeps no
 * state of its own.
 *
 * @internal The UsesDynoxide trait is the API.
 */
final class AppStores
{
    /**
     * Points every DynamoDB cache store, and a DynamoDB failed-job store, at
     * the server.
     *
     * Laravel's DynamoDB clients pass credentials only when both key and
     * secret are set, and otherwise use the SDK's credential chain, so both
     * are always set here.
     */
    public static function pointAt(Config $config, Server $server): void
    {
        $paths = array_map(static fn (string $name): string => "cache.stores.{$name}", self::dynamoDbStores($config));
        if (self::failedJobsUseDynamoDb($config)) {
            $paths[] = 'queue.failed';
        }

        foreach ($paths as $path) {
            $config->set([
                "{$path}.endpoint" => $server->endpoint,
                "{$path}.key" => $server->accessKeyId,
                "{$path}.secret" => $server->secretAccessKey,
                "{$path}.region" => $server->region,
                "{$path}.token" => null,
            ]);
        }
    }

    /**
     * Drops whatever boot() resolved from the DynamoDB stores with the old
     * settings, so the next use builds it again from the repointed config.
     */
    public static function forgetResolved(Application $app, Config $config): void
    {
        $stores = self::dynamoDbStores($config);

        if ($stores !== []) {
            if ($app->resolved('cache')) {
                $app['cache']->forgetDriver($stores);
            }
            foreach ($stores as $name) {
                $app->forgetInstance("cache.__memoized:{$name}");
            }
            $app->forgetInstance('cache.store');
            $app->forgetInstance('cache.psr6');
        }

        if (self::sessionUsesDynamoDb($config, $stores)) {
            if ($app->resolved('session')) {
                $app['session']->forgetDrivers();
            }
            $app->forgetInstance('session.store');
        }

        if (self::failedJobsUseDynamoDb($config)) {
            $app->forgetInstance('queue.failer');
        }

        // An empty cache.limiter means the default store, as it does to Laravel.
        $limiterStore = $config->get('cache.limiter') ?: $config->get('cache.default');
        if (in_array($limiterStore, $stores, true) && $app->resolved(RateLimiter::class)) {
            self::rebuildRateLimiter($app, $app['cache']->store($limiterStore));
        }
    }

    /**
     * Whether anything the app runs uses a DynamoDB store, directly or behind
     * a failover store. The stock config always defines one, so defining a
     * store isn't enough.
     */
    public static function usesDynamoDb(Config $config): bool
    {
        return array_intersect(CacheStoresInUse::names($config), self::dynamoDbStores($config)) !== []
            || self::failedJobsUseDynamoDb($config);
    }

    /**
     * What to tell someone whose tests use the trait while nothing in the
     * app uses DynamoDB.
     */
    public static function notice(Config $config): string
    {
        $store = self::dynamoDbStores($config)[0] ?? 'dynamodb';
        $current = static fn (string $key): string => var_export($config->get($key), true);

        return implode(PHP_EOL, [
            '',
            "Dynoxide: the tests use the UsesDynoxide trait, but nothing in this app uses DynamoDB, so they don't touch "
            . "Dynoxide: cache.default is {$current('cache.default')}, session.driver is {$current('session.driver')} and "
            . "queue.failed.driver is {$current('queue.failed.driver')}. To run them against Dynoxide, set these in phpunit.xml:",
            '',
            "    <env name=\"CACHE_STORE\" value=\"{$store}\"/>",
            '    <env name="SESSION_DRIVER" value="dynamodb"/>',
            ...($store === 'dynamodb' ? [] : ["    <env name=\"SESSION_STORE\" value=\"{$store}\"/>"]),
            '    <env name="QUEUE_FAILED_DRIVER" value="dynamodb"/>',
            '',
            '',
        ]);
    }

    /**
     * @return list<string> the names of the cache stores whose driver is dynamodb
     */
    public static function dynamoDbStores(Config $config): array
    {
        $stores = [];
        foreach ((array) $config->get('cache.stores', []) as $name => $store) {
            if (is_array($store) && ($store['driver'] ?? null) === 'dynamodb') {
                $stores[] = (string) $name;
            }
        }

        return $stores;
    }

    /**
     * Named limiters registered with RateLimiter::for() in boot() live in the
     * resolved instance, so a fresh one would lose them and every
     * throttle:<name> route would throw. A copy keeps them, and the app's own
     * subclass if it has one, with only the store swapped. The facade keeps
     * the instance it resolved during boot until it's told to forget it.
     */
    private static function rebuildRateLimiter(Application $app, Repository $store): void
    {
        $limiter = clone $app->make(RateLimiter::class);
        if (!property_exists($limiter, 'cache')) {
            throw new ConfigException(sprintf(
                "The Dynoxide testing trait can't point the rate limiter at Dynoxide: %s has no \$cache property in "
                . 'Laravel %s, and the trait swaps the store through it. This release of dynoxide-laravel '
                . "doesn't support that version of Laravel yet.",
                $limiter::class,
                $app->version(),
            ));
        }
        (function (Repository $store): void {
            $this->cache = $store;
        })->call($limiter, $store);

        $app->instance(RateLimiter::class, $limiter);
        Facade::clearResolvedInstance(RateLimiter::class);
    }

    /** @param list<string> $stores */
    private static function sessionUsesDynamoDb(Config $config, array $stores): bool
    {
        $driver = $config->get('session.driver');
        if (!in_array($driver, CacheStoresInUse::CACHE_BASED_SESSION_DRIVERS, true)) {
            return false;
        }

        return in_array($config->get('session.store') ?: $driver, $stores, true);
    }

    private static function failedJobsUseDynamoDb(Config $config): bool
    {
        return $config->get('queue.failed.driver') === 'dynamodb';
    }
}
