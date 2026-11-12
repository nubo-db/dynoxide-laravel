<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Support;

use Dynoxide\Laravel\Tests\TestCase;
use Illuminate\Foundation\Application;

/**
 * The base for the Pest file, which can't override defineEnvironment()
 * itself.
 */
abstract class PestTestCase extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'dynamodb');
        $app['config']->set('cache.stores.pest', ['driver' => 'dynamodb', 'table' => 'pest_file', 'region' => 'us-east-1', 'endpoint' => 'http://127.0.0.1:1']);
    }
}
