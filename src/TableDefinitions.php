<?php

declare(strict_types=1);

namespace Dynoxide\Laravel;

use Dynoxide\Laravel\Exception\ConfigException;
use Illuminate\Contracts\Config\Repository;

/**
 * The DynamoDB tables an app's config needs: one for each `dynamodb` cache
 * store, and one for a `dynamodb` failed-job store. Sessions on DynamoDB go
 * through a cache store, so they need no table of their own.
 *
 * A `dynamodb` store with no table is skipped unless the app uses it (as
 * the default store, the rate limiter's, the session store, or behind a
 * failover store that is one of those), so a store whose table only
 * production names doesn't stop the tests.
 */
final class TableDefinitions
{
    /**
     * @param array<string, array{source: string, keySchema: list<array{AttributeName: string, KeyType: string}>}> $tables
     *        keyed by table name, in the order the config names them
     */
    private function __construct(private readonly array $tables)
    {
    }

    public static function fromConfig(Repository $config): self
    {
        $tables = [];
        $inUse = CacheStoresInUse::names($config);

        foreach ((array) $config->get('cache.stores', []) as $name => $store) {
            if (!is_array($store) || ($store['driver'] ?? null) !== 'dynamodb') {
                continue;
            }
            if (in_array($store['table'] ?? null, [null, ''], true) && !in_array((string) $name, $inUse, true)) {
                continue;
            }

            $path = "cache.stores.{$name}";
            $key = $store['attributes']['key'] ?? 'key';
            if (!is_string($key) || $key === '') {
                throw new ConfigException("{$path}.attributes.key must be a non-empty string.");
            }

            self::add($tables, self::tableName($store, $path), $path, [
                ['AttributeName' => $key, 'KeyType' => 'HASH'],
            ]);
        }

        $failed = $config->get('queue.failed');
        if (is_array($failed) && ($failed['driver'] ?? null) === 'dynamodb') {
            self::add($tables, self::tableName($failed, 'queue.failed'), 'queue.failed', [
                ['AttributeName' => 'application', 'KeyType' => 'HASH'],
                ['AttributeName' => 'uuid', 'KeyType' => 'RANGE'],
            ]);
        }

        return new self($tables);
    }

    public function isEmpty(): bool
    {
        return $this->tables === [];
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->tables);
    }

    /**
     * Arguments for the AWS SDK's DynamoDbClient::createTable(), one per table.
     *
     * @return list<array<string, mixed>>
     */
    public function toCreateTableInputs(): array
    {
        $inputs = [];

        foreach ($this->tables as $name => $table) {
            $inputs[] = [
                'TableName' => $name,
                'KeySchema' => $table['keySchema'],
                'AttributeDefinitions' => self::attributeDefinitions($table['keySchema']),
                'BillingMode' => 'PAY_PER_REQUEST',
            ];
        }

        return $inputs;
    }

    /**
     * The tables as DescribeTable responses, the shape `dynoxide --schema`
     * reads. Encode it with json_encode() and write it to a file.
     *
     * @return list<array{Table: array<string, mixed>}>
     */
    public function toSchemaJson(): array
    {
        $schema = [];

        foreach ($this->tables as $name => $table) {
            $schema[] = ['Table' => [
                'TableName' => $name,
                'KeySchema' => $table['keySchema'],
                'AttributeDefinitions' => self::attributeDefinitions($table['keySchema']),
                'BillingModeSummary' => ['BillingMode' => 'PAY_PER_REQUEST'],
            ]];
        }

        return $schema;
    }

    /**
     * Laravel reads `table` with no default, so a missing one is reported
     * rather than guessed.
     *
     * @param array<array-key, mixed> $config
     */
    private static function tableName(array $config, string $path): string
    {
        $table = $config['table'] ?? null;
        if (!is_string($table) || $table === '') {
            throw new ConfigException("{$path}.table must name the DynamoDB table, but it is " . ($table === null ? 'not set' : 'empty or not a string') . '.');
        }

        return $table;
    }

    /**
     * @param array<string, array{source: string, keySchema: list<array{AttributeName: string, KeyType: string}>}> $tables
     * @param list<array{AttributeName: string, KeyType: string}> $keySchema
     */
    private static function add(array &$tables, string $name, string $source, array $keySchema): void
    {
        $existing = $tables[$name] ?? null;

        if ($existing === null) {
            $tables[$name] = ['source' => $source, 'keySchema' => $keySchema];

            return;
        }

        if ($existing['keySchema'] !== $keySchema) {
            throw new ConfigException(sprintf(
                '%s and %s both use the DynamoDB table "%s" but with different keys (%s and %s). Give one of them its own table.',
                $existing['source'],
                $source,
                $name,
                self::describeKey($existing['keySchema']),
                self::describeKey($keySchema),
            ));
        }
    }

    /**
     * Every key attribute is a string: Laravel writes cache keys, application
     * names and job UUIDs as `S`.
     *
     * @param list<array{AttributeName: string, KeyType: string}> $keySchema
     * @return list<array{AttributeName: string, AttributeType: string}>
     */
    private static function attributeDefinitions(array $keySchema): array
    {
        return array_map(
            static fn (array $key): array => ['AttributeName' => $key['AttributeName'], 'AttributeType' => 'S'],
            $keySchema,
        );
    }

    /**
     * @param list<array{AttributeName: string, KeyType: string}> $keySchema
     */
    private static function describeKey(array $keySchema): string
    {
        return implode(', ', array_map(
            static fn (array $key): string => "{$key['AttributeName']} {$key['KeyType']}",
            $keySchema,
        ));
    }
}
