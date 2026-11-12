<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests;

use Aws\CommandInterface;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\MockHandler;
use Aws\Result;
use Dynoxide\Laravel\TableDefinitions;
use Dynoxide\Laravel\Testing\TableSet;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * What TableSet asks DynamoDB for, against a client whose answers are set
 * here, so no server is involved.
 */
final class TableSetTest extends PHPUnitTestCase
{
    /** @var list<string> */
    private array $sent = [];

    public function test_a_set_up_lists_the_tables_and_creates_only_missing_ones(): void
    {
        $mock = new MockHandler();
        $tables = new TableSet($this->client($mock));

        // The first set-up knows no tables, so there's nothing to list.
        $mock->append(new Result([]));
        $tables->create(self::definitions());

        // The next: the table is listed, on the second page, so it isn't
        // created again.
        $mock->append(new Result(['TableNames' => ['another'], 'LastEvaluatedTableName' => 'another']));
        $mock->append(new Result(['TableNames' => ['cache']]));
        $tables->create(self::definitions());

        $this->assertSame(['CreateTable', 'ListTables', 'ListTables'], $this->sent);
        $this->assertSame(['cache' => 1], $tables->created());
    }

    public function test_a_table_missing_from_the_listing_is_created_again(): void
    {
        $mock = new MockHandler();
        $tables = new TableSet($this->client($mock));
        $mock->append(new Result([]));
        $tables->create(self::definitions());

        // A test deleted it.
        $mock->append(new Result(['TableNames' => []]), new Result([]));
        $tables->create(self::definitions());

        $this->assertSame(['CreateTable', 'ListTables', 'CreateTable'], $this->sent);
        $this->assertSame(['cache' => 2], $tables->created());
    }

    /**
     * Something else may have made it between the listing and the create,
     * and a table this set doesn't know yet has its key checked.
     */
    public function test_a_new_table_that_is_already_there_has_its_key_checked(): void
    {
        $mock = new MockHandler();
        $tables = new TableSet($this->client($mock));
        $mock->append(
            static fn (CommandInterface $command): DynamoDbException => new DynamoDbException('in use', $command, ['code' => 'ResourceInUseException']),
            new Result(['Table' => ['KeySchema' => [['AttributeName' => 'key', 'KeyType' => 'HASH']]]]),
        );

        $tables->create(self::definitions());

        $this->assertSame(['CreateTable', 'DescribeTable'], $this->sent);
        $this->assertSame([], $tables->created());
    }

    private function client(MockHandler $mock): DynamoDbClient
    {
        return new DynamoDbClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'dynoxide', 'secret' => 'dynoxide'],
            'handler' => function (CommandInterface $command, mixed $request) use ($mock) {
                $this->sent[] = $command->getName();

                return $mock($command, $request);
            },
        ]);
    }

    private static function definitions(): TableDefinitions
    {
        return TableDefinitions::fromConfig(new Repository([
            'cache' => ['default' => 'dynamodb', 'stores' => ['dynamodb' => ['driver' => 'dynamodb', 'table' => 'cache']]],
        ]));
    }
}
