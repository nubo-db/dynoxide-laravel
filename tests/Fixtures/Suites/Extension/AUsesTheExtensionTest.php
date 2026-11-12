<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Fixtures\Suites\Extension;

use Dynoxide\Laravel\Testing\DynoxideProcess;
use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureReport;
use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureTestCase;
use Dynoxide\PHPUnit\Extension;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Depends;

/**
 * The extension's schema file has already made `cache` and `from_schema`.
 */
final class AUsesTheExtensionTest extends FixtureTestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.stores.schema', ['driver' => 'dynamodb', 'table' => 'from_schema', 'region' => 'us-east-1']);
    }

    public function test_puts_values_through_the_extensions_server(): void
    {
        Cache::put('one', 'value', 60);
        Cache::store('schema')->put('two', 'value', 60);

        FixtureReport::record('put', [
            'endpoint' => $this->dynoxideServer()->endpoint,
            'extension' => Extension::server()?->endpoint,
            'starts' => DynoxideProcess::starts(),
            'created' => DynoxideProcess::tablesCreated(),
            'cache' => count($this->itemsIn('cache')),
            'from_schema' => count($this->itemsIn('from_schema')),
        ]);
    }

    #[Depends('test_puts_values_through_the_extensions_server')]
    public function test_finds_them_gone(): void
    {
        FixtureReport::record('gone', [
            'cache' => count($this->itemsIn('cache')),
            'from_schema' => count($this->itemsIn('from_schema')),
        ]);
    }
}
