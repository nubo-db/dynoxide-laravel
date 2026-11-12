<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests;

use Aws\DynamoDb\DynamoDbClient;
use Dynoxide\Dynoxide;
use Dynoxide\Laravel\Exception\ConfigException;
use Dynoxide\Laravel\TableDefinitions;
use Dynoxide\Server;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;

final class TableDefinitionsTest extends TestCase
{
    public function test_laravel_default_config_with_the_dynamodb_cache_gives_one_table(): void
    {
        $this->app['config']->set('cache.default', 'dynamodb');

        $tables = TableDefinitions::fromConfig($this->app['config']);

        $this->assertSame([self::hashTable('cache', 'key')], $tables->toCreateTableInputs());
    }

    public function test_a_custom_table_and_key_attribute_are_used(): void
    {
        $tables = TableDefinitions::fromConfig(self::config([
            'cache' => ['stores' => [
                'dynamodb' => ['driver' => 'dynamodb', 'table' => 'app_cache', 'attributes' => ['key' => 'id']],
            ]],
        ]));

        $this->assertSame([self::hashTable('app_cache', 'id')], $tables->toCreateTableInputs());
    }

    public function test_stores_on_different_tables_give_a_table_each(): void
    {
        $tables = TableDefinitions::fromConfig(self::config([
            'cache' => ['stores' => [
                'first' => ['driver' => 'dynamodb', 'table' => 'cache_one'],
                'file' => ['driver' => 'file', 'path' => '/tmp'],
                'second' => ['driver' => 'dynamodb', 'table' => 'cache_two'],
            ]],
        ]));

        $this->assertSame(
            [self::hashTable('cache_one', 'key'), self::hashTable('cache_two', 'key')],
            $tables->toCreateTableInputs(),
        );
    }

    public function test_stores_on_the_same_table_with_the_same_key_give_one_table(): void
    {
        $tables = TableDefinitions::fromConfig(self::config([
            'cache' => ['stores' => [
                'first' => ['driver' => 'dynamodb', 'table' => 'shared'],
                'second' => ['driver' => 'dynamodb', 'table' => 'shared', 'attributes' => ['key' => 'key']],
            ]],
        ]));

        $this->assertSame([self::hashTable('shared', 'key')], $tables->toCreateTableInputs());
    }

    public function test_stores_on_the_same_table_with_different_keys_name_both_stores(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/cache\.stores\.first.*cache\.stores\.second/s');

        TableDefinitions::fromConfig(self::config([
            'cache' => ['stores' => [
                'first' => ['driver' => 'dynamodb', 'table' => 'shared'],
                'second' => ['driver' => 'dynamodb', 'table' => 'shared', 'attributes' => ['key' => 'id']],
            ]],
        ]));
    }

    public function test_a_cache_store_and_the_failed_job_store_on_one_table_conflict(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/cache\.stores\.dynamodb.*queue\.failed/s');

        TableDefinitions::fromConfig(self::config([
            'cache' => ['stores' => ['dynamodb' => ['driver' => 'dynamodb', 'table' => 'shared']]],
            'queue' => ['failed' => ['driver' => 'dynamodb', 'table' => 'shared']],
        ]));
    }

    public function test_a_cache_store_in_use_without_a_table_names_its_config_key(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('cache.stores.dynamodb.table');

        TableDefinitions::fromConfig(self::config([
            'cache' => ['default' => 'dynamodb', 'stores' => ['dynamodb' => ['driver' => 'dynamodb']]],
        ]));
    }

    /**
     * A store whose table comes from an environment variable only
     * production sets, say, while the tests use another store.
     */
    public function test_a_store_nothing_uses_is_skipped_when_it_has_no_table(): void
    {
        $tables = TableDefinitions::fromConfig(self::config([
            'cache' => ['default' => 'array', 'stores' => [
                'array' => ['driver' => 'array'],
                'dynamodb' => ['driver' => 'dynamodb', 'table' => 'cache'],
                'unset' => ['driver' => 'dynamodb', 'table' => null],
                'missing' => ['driver' => 'dynamodb'],
                'empty' => ['driver' => 'dynamodb', 'table' => ''],
            ]],
        ]));

        $this->assertSame(['cache'], $tables->names());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function usesOfAStoreWithNoTable(): iterable
    {
        $stores = [
            'array' => ['driver' => 'array'],
            'notable' => ['driver' => 'dynamodb', 'table' => null],
            'failover' => ['driver' => 'failover', 'stores' => ['notable', 'array']],
        ];

        yield 'the default store' => [['cache' => ['default' => 'notable', 'stores' => $stores]]];
        yield 'the rate limiter\'s store' => [['cache' => ['default' => 'array', 'limiter' => 'notable', 'stores' => $stores]]];
        yield 'an empty limiter, so the default store' => [['cache' => ['default' => 'notable', 'limiter' => '', 'stores' => $stores]]];
        yield 'the session store' => [['cache' => ['default' => 'array', 'stores' => $stores], 'session' => ['driver' => 'dynamodb', 'store' => 'notable']]];
        yield 'a failover default' => [['cache' => ['default' => 'failover', 'stores' => $stores]]];
        yield 'a failover limiter' => [['cache' => ['default' => 'array', 'limiter' => 'failover', 'stores' => $stores]]];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('usesOfAStoreWithNoTable')]
    public function test_a_store_in_use_with_no_table_is_an_error(array $config): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('cache.stores.notable.table must name the DynamoDB table, but it is not set.');

        TableDefinitions::fromConfig(self::config($config));
    }

    public function test_sessions_on_dynamodb_with_no_store_named_use_the_dynamodb_store(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('cache.stores.dynamodb.table');

        TableDefinitions::fromConfig(self::config([
            'cache' => ['default' => 'array', 'stores' => ['dynamodb' => ['driver' => 'dynamodb']]],
            'session' => ['driver' => 'dynamodb'],
        ]));
    }

    /** @return iterable<string, array{mixed}> */
    public static function badKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'a number' => [123];
        yield 'a list' => [['id']];
    }

    #[DataProvider('badKeys')]
    public function test_a_key_attribute_that_is_not_a_non_empty_string_names_its_config_key(mixed $key): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('cache.stores.dynamodb.attributes.key must be a non-empty string.');

        TableDefinitions::fromConfig(self::config([
            'cache' => ['stores' => ['dynamodb' => ['driver' => 'dynamodb', 'table' => 'cache', 'attributes' => ['key' => $key]]]],
        ]));
    }

    public function test_a_dynamodb_failed_job_store_adds_its_table(): void
    {
        $tables = TableDefinitions::fromConfig(self::config([
            'queue' => ['failed' => ['driver' => 'dynamodb', 'table' => 'jobs_failed']],
        ]));

        $this->assertSame([[
            'TableName' => 'jobs_failed',
            'KeySchema' => [
                ['AttributeName' => 'application', 'KeyType' => 'HASH'],
                ['AttributeName' => 'uuid', 'KeyType' => 'RANGE'],
            ],
            'AttributeDefinitions' => [
                ['AttributeName' => 'application', 'AttributeType' => 'S'],
                ['AttributeName' => 'uuid', 'AttributeType' => 'S'],
            ],
            'BillingMode' => 'PAY_PER_REQUEST',
        ]], $tables->toCreateTableInputs());
    }

    public function test_a_dynamodb_failed_job_store_without_a_table_names_its_config_key(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('queue.failed.table');

        TableDefinitions::fromConfig(self::config([
            'queue' => ['failed' => ['driver' => 'dynamodb']],
        ]));
    }

    public function test_another_failed_job_driver_adds_nothing(): void
    {
        $tables = TableDefinitions::fromConfig(self::config([
            'queue' => ['failed' => ['driver' => 'database-uuids', 'table' => 'failed_jobs']],
        ]));

        $this->assertTrue($tables->isEmpty());
        $this->assertSame([], $tables->toCreateTableInputs());
        $this->assertSame([], $tables->toSchemaJson());
    }

    public function test_the_stock_dynamodb_store_gives_its_table_even_when_another_store_is_the_default(): void
    {
        $this->assertSame('array', $this->app['config']->get('cache.default'));

        $tables = TableDefinitions::fromConfig($this->app['config']);

        $this->assertSame(['cache'], $tables->names());
    }

    public function test_a_config_without_dynamodb_gives_no_tables(): void
    {
        $this->app['config']->set('cache.stores', array_filter(
            $this->app['config']->get('cache.stores'),
            static fn (array $store): bool => $store['driver'] !== 'dynamodb',
        ));

        $tables = TableDefinitions::fromConfig($this->app['config']);

        $this->assertTrue($tables->isEmpty());
        $this->assertSame([], $tables->toCreateTableInputs());
    }

    public function test_the_schema_json_wraps_each_table_like_describe_table(): void
    {
        $tables = TableDefinitions::fromConfig(self::config([
            'cache' => ['stores' => ['dynamodb' => ['driver' => 'dynamodb', 'table' => 'cache']]],
        ]));

        $this->assertSame([[
            'Table' => [
                'TableName' => 'cache',
                'KeySchema' => [['AttributeName' => 'key', 'KeyType' => 'HASH']],
                'AttributeDefinitions' => [['AttributeName' => 'key', 'AttributeType' => 'S']],
                'BillingModeSummary' => ['BillingMode' => 'PAY_PER_REQUEST'],
            ],
        ]], $tables->toSchemaJson());
    }

    public function test_both_outputs_create_the_same_tables_in_dynoxide(): void
    {
        $tables = TableDefinitions::fromConfig(self::config([
            'cache' => ['stores' => [
                'dynamodb' => ['driver' => 'dynamodb', 'table' => 'cache'],
                'sessions' => ['driver' => 'dynamodb', 'table' => 'sessions', 'attributes' => ['key' => 'id']],
            ]],
            'queue' => ['failed' => ['driver' => 'dynamodb', 'table' => 'failed_jobs']],
        ]));

        $schema = tempnam(sys_get_temp_dir(), 'dynoxide-schema-');
        file_put_contents($schema, json_encode($tables->toSchemaJson(), JSON_THROW_ON_ERROR));

        $fromSchema = null;
        $fromCreateTable = null;

        try {
            $fromSchema = self::startDynoxide(['schema' => $schema]);
            $fromCreateTable = self::startDynoxide();

            $created = new DynamoDbClient($fromCreateTable->clientConfig());
            foreach ($tables->toCreateTableInputs() as $input) {
                $created->createTable($input);
            }

            $fromFile = self::describeAll(new DynamoDbClient($fromSchema->clientConfig()), $tables->names());

            $this->assertSame(['cache', 'failed_jobs', 'sessions'], $fromFile['names']);
            $this->assertSame('PAY_PER_REQUEST', $fromFile['sessions']['BillingMode']);
            $this->assertSame(
                [['AttributeName' => 'application', 'KeyType' => 'HASH'], ['AttributeName' => 'uuid', 'KeyType' => 'RANGE']],
                $fromFile['failed_jobs']['KeySchema'],
            );
            $this->assertSame(self::describeAll($created, $tables->names()), $fromFile);
        } finally {
            $fromSchema?->stop();
            $fromCreateTable?->stop();
            @unlink($schema);
        }
    }

    /**
     * @param array<string, mixed> $items
     */
    private static function config(array $items): Repository
    {
        return new Repository($items);
    }

    /**
     * @return array<string, mixed>
     */
    private static function hashTable(string $name, string $key): array
    {
        return [
            'TableName' => $name,
            'KeySchema' => [['AttributeName' => $key, 'KeyType' => 'HASH']],
            'AttributeDefinitions' => [['AttributeName' => $key, 'AttributeType' => 'S']],
            'BillingMode' => 'PAY_PER_REQUEST',
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function startDynoxide(array $options = []): Server
    {
        return Dynoxide::start(['binary' => getenv('DYNOXIDE_BINARY') ?: null, 'log' => static function (): void {}] + $options);
    }

    /**
     * The parts of DescribeTable the config decides, for each table, plus
     * the table names the server lists.
     *
     * @param list<string> $names
     * @return array<string, mixed>
     */
    private static function describeAll(DynamoDbClient $client, array $names): array
    {
        $described = ['names' => $client->listTables()['TableNames']];

        foreach ($names as $name) {
            $table = $client->describeTable(['TableName' => $name])['Table'];
            $described[$name] = [
                'KeySchema' => $table['KeySchema'],
                'AttributeDefinitions' => $table['AttributeDefinitions'],
                'BillingMode' => $table['BillingModeSummary']['BillingMode'] ?? null,
            ];
        }

        return $described;
    }
}
