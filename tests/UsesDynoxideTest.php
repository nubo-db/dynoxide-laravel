<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests;

use Dynoxide\Laravel\Testing\TableSet;
use Dynoxide\Laravel\Testing\UsesDynoxide;
use Dynoxide\Laravel\Tests\Support\BootsLikeAnApp;
use Dynoxide\Laravel\Tests\Support\ReadsDynoxide;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Depends;

/**
 * The app's config here points its DynamoDB store at a port where nothing
 * listens, with credentials of its own, as an app's config might when it
 * reads its endpoint from somewhere other than DYNAMODB_ENDPOINT. Each test
 * passing therefore shows the trait repointed what boot() had resolved.
 */
final class UsesDynoxideTest extends TestCase
{
    use UsesDynoxide;
    use ReadsDynoxide;

    /**
     * Config for single tests, applied after the class's own. Testbench's
     * #[DefineEnvironment] runs before defineEnvironment(), so the class's
     * config would undo it.
     */
    private const ENVIRONMENTS = [
        'test_sessions_round_trip_through_dynoxide' => 'useDynamoDbSessions',
        'test_sessions_land_in_the_table_of_the_store_session_store_names' => 'useDynamoDbSessionsInTheirOwnStore',
        'test_failed_jobs_work_with_no_credentials_in_their_config' => 'useDynamoDbFailedJobs',
        'test_a_failed_job_store_resolved_in_boot_is_replaced' => 'useCompleteDynamoDbFailedJobs',
        'test_a_custom_table_and_key_need_no_setup' => 'useACustomTableAndKey',
        'test_the_default_store_stays_the_one_the_app_chose' => 'useTheArrayStoreByDefault',
        'test_a_rate_limiter_on_a_store_other_than_the_default_counts_in_dynoxide' => 'useADynamoDbLimiterStore',
        'test_a_dynamodb_store_the_app_does_not_use_needs_no_table' => 'defineAnUnusedStoreWithNoTable',
    ];

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), BootsLikeAnApp::class];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'dynamodb');
        $app['config']->set('cache.stores.dynamodb', [
            'driver' => 'dynamodb',
            'key' => 'an-app-key',
            'secret' => 'an-app-secret',
            'token' => 'an-app-session-token',
            'region' => 'eu-west-2',
            'table' => 'cache',
            'endpoint' => 'http://127.0.0.1:1',
        ]);

        $environment = self::ENVIRONMENTS[$this->name()] ?? null;
        if ($environment !== null) {
            $this->{$environment}($app);
        }
    }

    /**
     * @param Router $router
     */
    protected function defineRoutes($router): void
    {
        $router->get('/limited', static fn (): string => 'ok')->middleware('throttle:uploads');
    }

    public function test_the_cache_reaches_dynoxide(): void
    {
        Cache::put('greeting', 'hello', 60);

        $this->assertSame('hello', Cache::get('greeting'));
        $items = $this->itemsIn('cache');
        $this->assertCount(1, $items);
        $this->assertStringEndsWith('greeting', $items[0]['key']['S']);

        Cache::forget('greeting');

        $this->assertNull(Cache::get('greeting'));
        $this->assertSame([], $this->itemsIn('cache'));
    }

    public function test_the_config_names_the_server_and_no_session_token(): void
    {
        $store = config('cache.stores.dynamodb');

        $this->assertSame($this->dynoxideServer()->endpoint, $store['endpoint']);
        $this->assertSame($this->dynoxideServer()->accessKeyId, $store['key']);
        $this->assertSame($this->dynoxideServer()->secretAccessKey, $store['secret']);
        $this->assertSame($this->dynoxideServer()->region, $store['region']);
        $this->assertNull($store['token']);
        $this->assertSame('cache', $store['table'], 'the table is the app\'s own');
    }

    public function test_locks_can_be_acquired_contended_and_released(): void
    {
        $first = Cache::store('dynamodb')->lock('report', 10);
        $second = Cache::store('dynamodb')->lock('report', 10);

        $this->assertTrue($first->get());
        $this->assertFalse($second->get());
        $this->assertTrue($first->release());
        $this->assertTrue($second->get());
        $second->release();
    }

    public function test_a_named_rate_limiter_from_boot_counts_in_dynoxide(): void
    {
        $this->get('/limited')->assertOk()->assertSee('ok');

        $this->assertSame(1, RateLimiter::attempts(md5('uploads' . 'the-client')));
        $this->assertNotSame([], $this->itemsIn('cache'), 'the hit landed in Dynoxide');
    }

    public function test_a_rate_limiter_on_a_store_other_than_the_default_counts_in_dynoxide(): void
    {
        $this->get('/limited')->assertOk();

        $this->assertSame(1, RateLimiter::attempts(md5('uploads' . 'the-client')));
        $this->assertNotSame([], $this->itemsIn('limits'), 'the hit landed in the limiter\'s table');
        $this->assertSame([], $this->itemsIn('cache'));
    }

    public function test_the_psr6_cache_resolved_in_boot_is_replaced_too(): void
    {
        $pool = $this->app['cache.psr6'];
        $pool->save($pool->getItem('psr6')->set('yes'));

        $this->assertCount(1, $this->itemsIn('cache'));
    }

    public function test_a_dynamodb_store_the_app_does_not_use_needs_no_table(): void
    {
        Cache::put('still', 'works', 60);

        $this->assertSame('works', Cache::get('still'));
    }

    /**
     * A test waits at most two seconds to connect and thirty for an answer,
     * so a server that stops answering fails the test rather than hanging
     * the run.
     */
    public function test_the_traits_client_gives_up_on_a_server_that_does_not_answer(): void
    {
        $http = TableSet::clientFor($this->dynoxideServer())->getCommand('ListTables')['@http'];

        $this->assertSame(2, $http['connect_timeout'] ?? null);
        $this->assertSame(30, $http['timeout'] ?? null);
    }

    public function test_a_store_resolved_in_boot_is_replaced_with_one_that_reaches_dynoxide(): void
    {
        $this->assertNotNull(BootsLikeAnApp::$storeResolvedInBoot);
        $this->assertNotSame(BootsLikeAnApp::$storeResolvedInBoot, Cache::store('dynamodb'));

        Cache::store('dynamodb')->put('after-boot', 'yes', 60);

        $this->assertCount(1, $this->itemsIn('cache'));
    }

    public function test_the_memoised_and_default_stores_resolved_in_boot_are_replaced_too(): void
    {
        Cache::memo('dynamodb')->put('memoised', 'yes', 60);
        $this->app['cache.store']->put('default', 'yes', 60);

        $this->assertCount(2, $this->itemsIn('cache'));
    }

    public function test_sessions_round_trip_through_dynoxide(): void
    {
        $session = $this->app['session.store'];
        $session->start();
        $session->put('colour', 'green');
        $session->save();

        $this->app['session']->forgetDrivers();
        $again = $this->app['session']->driver();
        $again->setId($session->getId());
        $again->start();

        $this->assertSame('green', $again->get('colour'));
        $this->assertCount(1, $this->itemsIn('cache'));
    }

    public function test_sessions_land_in_the_table_of_the_store_session_store_names(): void
    {
        $session = $this->app['session']->driver();
        $session->start();
        $session->put('colour', 'blue');
        $session->save();

        $this->assertCount(1, $this->itemsIn('sessions'));
        $this->assertSame([], $this->itemsIn('cache'));
    }

    public function test_failed_jobs_work_with_no_credentials_in_their_config(): void
    {
        $failer = $this->app['queue.failer'];
        $uuid = (string) Str::uuid();

        $this->assertSame($uuid, $failer->log('sync', 'default', json_encode(['uuid' => $uuid]), new \RuntimeException('boom')));
        $this->assertCount(1, $failer->all());
        $this->assertSame($uuid, $failer->find($uuid)->id);
        $this->assertTrue($failer->forget($uuid));
        $this->assertNull($failer->find($uuid));
        $this->assertContains('failed_jobs', $this->tableNames());
    }

    public function test_a_failed_job_store_resolved_in_boot_is_replaced(): void
    {
        $uuid = (string) Str::uuid();
        $this->app['queue.failer']->log('sync', 'default', json_encode(['uuid' => $uuid]), new \RuntimeException('boom'));

        $this->assertCount(1, $this->itemsIn('failed_jobs'));
    }

    public function test_a_custom_table_and_key_need_no_setup(): void
    {
        Cache::store('custom')->put('colour', 'red', 60);

        $this->assertSame('red', Cache::store('custom')->get('colour'));
        $items = $this->itemsIn('app_cache');
        $this->assertCount(1, $items);
        $this->assertArrayHasKey('id', $items[0]);
    }

    public function test_the_default_store_stays_the_one_the_app_chose(): void
    {
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('array', Cache::getDefaultDriver());
        $this->assertSame('dynamodb', config('session.driver'), 'and the session driver too');
    }

    public function test_a_value_is_put_in_one_test(): void
    {
        Cache::put('left-behind', 'value', 60);
        RateLimiter::hit('left-behind-limiter');

        $this->assertSame('value', Cache::get('left-behind'));
        $this->assertSame(1, RateLimiter::attempts('left-behind-limiter'));
    }

    #[Depends('test_a_value_is_put_in_one_test')]
    public function test_and_is_gone_in_the_next(): void
    {
        $this->assertNull(Cache::get('left-behind'));
        $this->assertSame(0, RateLimiter::attempts('left-behind-limiter'));
        $this->assertSame([], $this->itemsIn('cache'));
    }

    public function test_a_table_the_test_creates_itself_is_made_here(): void
    {
        $this->rawClient()->createTable([
            'TableName' => 'made_by_the_test',
            'KeySchema' => [['AttributeName' => 'pk', 'KeyType' => 'HASH']],
            'AttributeDefinitions' => [['AttributeName' => 'pk', 'AttributeType' => 'S']],
            'BillingMode' => 'PAY_PER_REQUEST',
        ]);
        $this->rawClient()->putItem(['TableName' => 'made_by_the_test', 'Item' => ['pk' => ['S' => 'kept']]]);

        $this->assertCount(1, $this->itemsIn('made_by_the_test'));
    }

    #[Depends('test_a_table_the_test_creates_itself_is_made_here')]
    public function test_and_keeps_its_items_after_the_reset(): void
    {
        $this->assertCount(1, $this->itemsIn('made_by_the_test'));

        $this->rawClient()->deleteTable(['TableName' => 'made_by_the_test']);
    }

    /**
     * @param Application $app
     */
    protected function useDynamoDbSessions($app): void
    {
        $app['config']->set('session.driver', 'dynamodb');
    }

    /**
     * @param Application $app
     */
    protected function useDynamoDbSessionsInTheirOwnStore($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'dynamodb');
        $app['config']->set('session.store', 'other');
        $app['config']->set('cache.stores.other', ['driver' => 'dynamodb', 'table' => 'sessions', 'region' => 'eu-west-2', 'endpoint' => 'http://127.0.0.1:1']);
    }

    /**
     * Laravel's generated config: a driver and a table, and nothing to say
     * which credentials, region or endpoint to use.
     *
     * @param Application $app
     */
    protected function useDynamoDbFailedJobs($app): void
    {
        $app['config']->set('queue.failed', ['driver' => 'dynamodb', 'table' => 'failed_jobs']);
    }

    /**
     * @param Application $app
     */
    protected function useCompleteDynamoDbFailedJobs($app): void
    {
        $app['config']->set('queue.failed', [
            'driver' => 'dynamodb',
            'table' => 'failed_jobs',
            'key' => 'an-app-key',
            'secret' => 'an-app-secret',
            'region' => 'eu-west-2',
            'endpoint' => 'http://127.0.0.1:1',
        ]);
    }

    /**
     * @param Application $app
     */
    protected function useACustomTableAndKey($app): void
    {
        $app['config']->set('cache.stores.custom', [
            'driver' => 'dynamodb',
            'table' => 'app_cache',
            'attributes' => ['key' => 'id'],
            'region' => 'eu-west-2',
            'endpoint' => 'http://127.0.0.1:1',
        ]);
    }

    /**
     * @param Application $app
     */
    protected function useADynamoDbLimiterStore($app): void
    {
        $app['config']->set('cache.limiter', 'limiter');
        $app['config']->set('cache.stores.limiter', ['driver' => 'dynamodb', 'table' => 'limits', 'region' => 'eu-west-2', 'endpoint' => 'http://127.0.0.1:1']);
    }

    /**
     * A store whose table comes from an environment variable that only
     * production sets.
     *
     * @param Application $app
     */
    protected function defineAnUnusedStoreWithNoTable($app): void
    {
        $app['config']->set('cache.stores.production_only', ['driver' => 'dynamodb', 'table' => null]);
    }

    /**
     * @param Application $app
     */
    protected function useTheArrayStoreByDefault($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'dynamodb');
    }
}
