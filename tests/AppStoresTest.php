<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests;

use Dynoxide\Laravel\Exception\ConfigException;
use Dynoxide\Laravel\Testing\AppStores;
use Illuminate\Cache\RateLimiter;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class AppStoresTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function configs(): iterable
    {
        $stores = [
            'dynamodb' => ['driver' => 'dynamodb', 'table' => 'cache'],
            'array' => ['driver' => 'array'],
            'file' => ['driver' => 'file', 'path' => '/tmp'],
            'over_dynamodb' => ['driver' => 'failover', 'stores' => ['dynamodb', 'array']],
            'over_others' => ['driver' => 'failover', 'stores' => ['array', 'file']],
        ];

        yield 'nothing' => [['cache' => ['default' => 'array', 'stores' => $stores]], false];
        yield 'the default store' => [['cache' => ['default' => 'dynamodb', 'stores' => $stores]], true];
        yield 'a failover default over DynamoDB' => [['cache' => ['default' => 'over_dynamodb', 'stores' => $stores]], true];
        yield 'a failover default over other stores' => [['cache' => ['default' => 'over_others', 'stores' => $stores]], false];
        yield 'a failover limiter over DynamoDB' => [['cache' => ['default' => 'array', 'limiter' => 'over_dynamodb', 'stores' => $stores]], true];
        yield 'sessions' => [['cache' => ['default' => 'array', 'stores' => $stores], 'session' => ['driver' => 'dynamodb']], true];
        yield 'failed jobs' => [['cache' => ['default' => 'array', 'stores' => $stores], 'queue' => ['failed' => ['driver' => 'dynamodb']]], true];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('configs')]
    public function test_whether_the_app_uses_dynamodb(array $config, bool $uses): void
    {
        $this->assertSame($uses, AppStores::usesDynamoDb(new Repository($config)));
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function failoverCycles(): iterable
    {
        yield 'a failover store listing itself' => [
            ['default' => 'self', 'stores' => ['self' => ['driver' => 'failover', 'stores' => ['self', 'dynamodb']], 'dynamodb' => ['driver' => 'dynamodb']]],
            ['self', 'dynamodb'],
        ];
        yield 'two failover stores listing each other' => [
            ['default' => 'a', 'stores' => [
                'a' => ['driver' => 'failover', 'stores' => ['b']],
                'b' => ['driver' => 'failover', 'stores' => ['a', 'dynamodb']],
                'dynamodb' => ['driver' => 'dynamodb'],
            ]],
            ['a', 'b', 'dynamodb'],
        ];
    }

    /**
     * Laravel would loop on these too, but working out which stores are in
     * use mustn't. Run in a process of its own with a time limit, so a loop
     * fails the test rather than hanging the run.
     *
     * @param array<string, mixed> $cache
     * @param list<string> $expected
     */
    #[DataProvider('failoverCycles')]
    public function test_failover_stores_that_list_each_other_are_followed_once(array $cache, array $expected): void
    {
        $process = new Process([
            PHP_BINARY,
            '-r',
            'require $argv[1]; echo json_encode(Dynoxide\Laravel\CacheStoresInUse::names(new Illuminate\Config\Repository(["cache" => json_decode($argv[2], true)])));',
            dirname(__DIR__) . '/vendor/autoload.php',
            (string) json_encode($cache),
        ]);
        $process->setTimeout(10);

        try {
            $process->mustRun();
        } catch (ProcessTimedOutException) {
            $this->fail('Working out the stores in use was still going after 10 seconds.');
        }

        $this->assertSame($expected, json_decode($process->getOutput(), true));
    }

    /**
     * The rate limiter's store is swapped through its protected property. If
     * a Laravel release renames it, that fails loudly rather than leaving the
     * limiter on the store boot() resolved.
     */
    public function test_a_rate_limiter_without_the_cache_property_is_an_error_naming_the_laravel_version(): void
    {
        $this->app['config']->set('cache.default', 'dynamodb');
        $this->app->instance(RateLimiter::class, new class () {
        });

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Laravel ' . $this->app->version());

        AppStores::forgetResolved($this->app, $this->app['config']);
    }
}
