<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Fixtures\Suites\BootTime;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

/**
 * Reads the cache in boot(), as some apps do to load settings, and notes
 * what happened rather than failing, so the test can report it.
 */
final class ReadsTheCacheInBoot extends ServiceProvider
{
    public static string $outcome = '';

    public function boot(): void
    {
        try {
            self::$outcome = 'read ' . var_export(Cache::get('settings'), true);
        } catch (\Throwable $e) {
            self::$outcome = $e::class . ': ' . $e->getMessage();
        }
    }
}
