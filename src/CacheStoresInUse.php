<?php

declare(strict_types=1);

namespace Dynoxide\Laravel;

use Illuminate\Contracts\Config\Repository;

/**
 * The cache stores an app's config puts to use: the default store, the rate
 * limiter's, the session store when sessions live in a cache store, and the
 * stores any failover store among those falls back on.
 *
 * @internal
 */
final class CacheStoresInUse
{
    /** Session drivers that keep sessions in a cache store. */
    public const CACHE_BASED_SESSION_DRIVERS = ['apc', 'dynamodb', 'memcached'];

    /**
     * @return list<string>
     */
    public static function names(Repository $config): array
    {
        $default = $config->get('cache.default');
        $pending = [$default, $config->get('cache.limiter') ?: $default];
        $sessionDriver = $config->get('session.driver');
        if (in_array($sessionDriver, self::CACHE_BASED_SESSION_DRIVERS, true)) {
            $pending[] = $config->get('session.store') ?: $sessionDriver;
        }

        $stores = (array) $config->get('cache.stores', []);
        $names = [];
        while ($pending !== []) {
            $name = array_shift($pending);
            if (!is_string($name) || $name === '' || in_array($name, $names, true)) {
                continue;
            }
            $names[] = $name;

            $store = $stores[$name] ?? null;
            if (is_array($store) && ($store['driver'] ?? null) === 'failover') {
                array_push($pending, ...array_values((array) ($store['stores'] ?? [])));
            }
        }

        return $names;
    }
}
