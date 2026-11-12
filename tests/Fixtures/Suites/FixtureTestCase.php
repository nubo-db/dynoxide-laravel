<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Fixtures\Suites;

use Dynoxide\Laravel\DynoxideServiceProvider;
use Dynoxide\Laravel\Testing\UsesDynoxide;
use Dynoxide\Laravel\Tests\Support\ReadsDynoxide;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase;

/**
 * The base for the fixture suites. The environment the driving test sets
 * chooses the options: FIXTURE_BINARY and FIXTURE_DB become the binary and
 * dbPath options, FIXTURE_KEY the cache table's key attribute, and
 * FIXTURE_LIMITER the store the rate limiter uses. FIXTURE_ENV_DIR is where
 * the app finds its .env files.
 */
abstract class FixtureTestCase extends TestCase
{
    use UsesDynoxide;
    use ReadsDynoxide;

    /**
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
        $key = getenv('FIXTURE_KEY');
        if ($key !== false && $key !== '') {
            $app['config']->set('cache.stores.dynamodb.attributes.key', $key);
        }
        $limiter = getenv('FIXTURE_LIMITER');
        if ($limiter !== false && $limiter !== '') {
            $app['config']->set('cache.limiter', $limiter);
        }
    }

    /**
     * FIXTURE_ENV_DIR gives the app a directory of its own for its .env
     * files, in place of the skeleton's.
     *
     * @param Application $app
     */
    protected function resolveApplicationEnvironmentVariables($app): void
    {
        $dir = getenv('FIXTURE_ENV_DIR');
        if ($dir !== false && $dir !== '') {
            $app->useEnvironmentPath($dir);
        }

        parent::resolveApplicationEnvironmentVariables($app);
    }

    /**
     * @return array<string, mixed>
     */
    protected function dynoxideOptions(): array
    {
        return array_filter([
            'binary' => getenv('FIXTURE_BINARY') ?: null,
            'dbPath' => getenv('FIXTURE_DB') ?: null,
        ]);
    }
}
