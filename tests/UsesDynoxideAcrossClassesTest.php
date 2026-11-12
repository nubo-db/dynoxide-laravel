<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests;

use Dynoxide\Laravel\Testing\DynoxideProcess;
use Dynoxide\Laravel\Testing\UsesDynoxide;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;

/**
 * This class and UsesDynoxideAcrossClassesSecondTest each use the trait
 * directly, and each needs the stock `cache` table plus one of its own.
 * PHPUnit runs one test class per file, so they're in two files. Whichever
 * runs first, by the time either runs the process has started one server and
 * created every table once, however many classes asked for it.
 */
final class UsesDynoxideAcrossClassesTest extends TestCase
{
    use UsesDynoxide;

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'dynamodb');
        $app['config']->set('cache.stores.first', ['driver' => 'dynamodb', 'table' => 'first_class', 'region' => 'us-east-1', 'endpoint' => 'http://127.0.0.1:1']);
    }

    public function test_the_process_has_one_server_and_made_each_table_once(): void
    {
        $this->assertSame(1, DynoxideProcess::starts());
        $this->assertSame($this->dynoxideServer()->endpoint, getenv('AWS_ENDPOINT_URL_DYNAMODB'));

        $created = DynoxideProcess::tablesCreated();
        $this->assertArrayHasKey('cache', $created);
        $this->assertArrayHasKey('first_class', $created);
        $this->assertSame(array_fill_keys(array_keys($created), 1), $created);

        Cache::store('first')->put('class', 'first', 60);
        $this->assertSame('first', Cache::store('first')->get('class'));
    }
}
