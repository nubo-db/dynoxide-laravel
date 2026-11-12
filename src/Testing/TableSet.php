<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Testing;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Dynoxide\Laravel\Exception\ConfigException;
use Dynoxide\Laravel\TableDefinitions;
use Dynoxide\Server;

/**
 * The tables the app's config named on the process's server: creating them,
 * checking their keys, and emptying them between tests.
 *
 * @internal The UsesDynoxide trait is the API.
 */
final class TableSet
{
    /**
     * The tables emptied after each test: those the app's config named, with
     * their key schemas, whether this process created them or found them.
     *
     * @var array<string, list<array{AttributeName: string, KeyType: string}>>
     */
    private array $tables = [];

    /** Whether the tables may hold items no reset has cleared. */
    private bool $mayHoldItems = false;

    /** @var array<string, int> */
    private array $created = [];

    public function __construct(private readonly DynamoDbClient $client)
    {
    }

    /**
     * A client that waits at most two seconds to connect and thirty for an
     * answer, so a server that stops answering fails the test rather than
     * hanging the run.
     */
    public static function clientFor(Server $server): DynamoDbClient
    {
        return new DynamoDbClient($server->clientConfig() + ['http' => ['connect_timeout' => 2, 'timeout' => 30]]);
    }

    /**
     * Creates the tables the definitions name that aren't there, and checks
     * the keys of those that are. One ListTables call shows which tables in
     * the set are still there, so one a test deleted is created again.
     */
    public function create(TableDefinitions $definitions): void
    {
        $listed = $this->tables === [] ? [] : $this->listTables();

        foreach ($definitions->toCreateTableInputs() as $input) {
            /** @var string $table */
            $table = $input['TableName'];
            /** @var list<array{AttributeName: string, KeyType: string}> $keySchema */
            $keySchema = $input['KeySchema'];

            $known = $this->tables[$table] ?? null;
            if ($known !== null && $known !== $keySchema) {
                throw self::keyMismatch($table, $known, $keySchema, 'an earlier test case\'s config named it with that key');
            }
            if ($known !== null && isset($listed[$table])) {
                continue;
            }

            try {
                $this->client->createTable($input);
                $this->created[$table] = ($this->created[$table] ?? 0) + 1;
            } catch (DynamoDbException $e) {
                if ($e->getAwsErrorCode() !== 'ResourceInUseException') {
                    throw $e;
                }
                if ($known !== null) {
                    continue;
                }
                $existing = array_map(
                    static fn (array $key): array => ['AttributeName' => (string) $key['AttributeName'], 'KeyType' => (string) $key['KeyType']],
                    $this->client->describeTable(['TableName' => $table])['Table']['KeySchema'] ?? [],
                );
                if ($existing !== $keySchema) {
                    throw self::keyMismatch($table, $existing, $keySchema, 'it was made by an earlier run in the same database file, or by a schema file');
                }
                $this->mayHoldItems = true;
            }

            $this->tables[$table] = $keySchema;
        }
    }

    /**
     * @return array<string, true> the names of the tables on the server
     */
    private function listTables(): array
    {
        $names = [];
        $input = [];
        do {
            $page = $this->client->listTables($input);
            foreach ($page['TableNames'] ?? [] as $name) {
                $names[(string) $name] = true;
            }
            $input = ['ExclusiveStartTableName' => $page['LastEvaluatedTableName'] ?? null];
        } while ($input['ExclusiveStartTableName'] !== null);

        return $names;
    }

    /**
     * Empties the tables before a test that resets them if anything may be
     * left in them, and remembers that a test that doesn't reset may leave
     * items behind.
     */
    public function startTest(bool $resetsTables): void
    {
        if ($resetsTables && $this->mayHoldItems) {
            $this->empty();
        } elseif (!$resetsTables) {
            $this->mayHoldItems = true;
        }
    }

    /**
     * Deletes every item in the tables the app's config named. Tables a test
     * made for itself aren't touched.
     */
    public function empty(): void
    {
        foreach ($this->tables as $table => $keySchema) {
            try {
                $this->emptyTable($table, $keySchema);
            } catch (DynamoDbException $e) {
                if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                    throw $e;
                }
                // A test deleted it. The next set-up creates it again.
                unset($this->tables[$table]);
            }
        }
        $this->mayHoldItems = false;
    }

    /**
     * How many times each table was created. A table that was already there
     * isn't counted.
     *
     * @return array<string, int>
     */
    public function created(): array
    {
        return $this->created;
    }

    /**
     * @param list<array{AttributeName: string, KeyType: string}> $keySchema
     */
    private function emptyTable(string $table, array $keySchema): void
    {
        // Placeholders, because `key`, Laravel's default, is a reserved word.
        $names = [];
        foreach ($keySchema as $i => $key) {
            $names["#k{$i}"] = $key['AttributeName'];
        }
        $scan = [
            'TableName' => $table,
            'ProjectionExpression' => implode(', ', array_keys($names)),
            'ExpressionAttributeNames' => $names,
        ];

        do {
            $page = $this->client->scan($scan);
            foreach (array_chunk($page['Items'] ?? [], 25) as $items) {
                $requests = array_map(static fn (array $key): array => ['DeleteRequest' => ['Key' => $key]], $items);
                for ($attempt = 0; $requests !== []; $attempt++) {
                    if ($attempt === 10) {
                        throw new \RuntimeException("Dynoxide didn't delete every item from the table \"{$table}\" after 10 attempts.");
                    }
                    $requests = $this->client->batchWriteItem(['RequestItems' => [$table => $requests]])['UnprocessedItems'][$table] ?? [];
                }
            }
            $scan['ExclusiveStartKey'] = $page['LastEvaluatedKey'] ?? null;
        } while ($scan['ExclusiveStartKey'] !== null);
    }

    /**
     * @param list<array{AttributeName: string, KeyType: string}> $existing
     * @param list<array{AttributeName: string, KeyType: string}> $wanted
     */
    private static function keyMismatch(string $table, array $existing, array $wanted, string $why): ConfigException
    {
        $describe = static fn (array $keySchema): string => implode(', ', array_map(
            static fn (array $key): string => "{$key['AttributeName']} {$key['KeyType']}",
            $keySchema,
        ));

        return new ConfigException(sprintf(
            'The DynamoDB table "%s" is keyed on %s, since %s, but the app\'s config now keys it on %s. Delete the '
            . 'database file or change the schema, or give the store a table of its own.',
            $table,
            $describe($existing),
            $why,
            $describe($wanted),
        ));
    }
}
