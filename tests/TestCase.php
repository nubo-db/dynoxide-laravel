<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests;

use Dynoxide\Laravel\DynoxideServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Testbench ignores package discovery, so the provider is listed here.
     *
     * @param Application $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [DynoxideServiceProvider::class];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        // Start from a config that names no DynamoDB tables, so each test
        // opts in to the stores it needs.
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.failed.driver', 'null');
    }
}
