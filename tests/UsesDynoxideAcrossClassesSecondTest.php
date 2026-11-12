<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests;

use Dynoxide\Laravel\Testing\DynoxideProcess;
use Dynoxide\Laravel\Testing\UsesDynoxide;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;

/**
 * The other half of UsesDynoxideAcrossClassesTest.
 */
final class UsesDynoxideAcrossClassesSecondTest extends TestCase
{
    use UsesDynoxide;

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'dynamodb');
        $app['config']->set('cache.stores.second', ['driver' => 'dynamodb', 'table' => 'second_class', 'region' => 'us-east-1', 'endpoint' => 'http://127.0.0.1:1']);
    }

    public function test_the_process_has_one_server_and_made_each_table_once(): void
    {
        $this->assertSame(1, DynoxideProcess::starts());
        $this->assertSame($this->dynoxideServer()->endpoint, getenv('AWS_ENDPOINT_URL_DYNAMODB'));

        $created = DynoxideProcess::tablesCreated();
        $this->assertArrayHasKey('cache', $created);
        $this->assertArrayHasKey('second_class', $created);
        $this->assertSame(array_fill_keys(array_keys($created), 1), $created);

        Cache::store('second')->put('class', 'second', 60);
        $this->assertSame('second', Cache::store('second')->get('class'));
    }
}
