<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Support;

use Aws\DynamoDb\DynamoDbClient;

/**
 * Reads the server directly, past Laravel, to see what really landed there.
 */
trait ReadsDynoxide
{
    protected function rawClient(): DynamoDbClient
    {
        return new DynamoDbClient($this->dynoxideServer()->clientConfig());
    }

    /**
     * @return list<array<string, array<string, mixed>>>
     */
    protected function itemsIn(string $table): array
    {
        $items = [];
        $start = null;
        do {
            $page = $this->rawClient()->scan(array_filter(['TableName' => $table, 'ExclusiveStartKey' => $start]));
            array_push($items, ...($page['Items'] ?? []));
            $start = $page['LastEvaluatedKey'] ?? null;
        } while ($start !== null);

        return $items;
    }

    /**
     * @return list<string>
     */
    protected function tableNames(): array
    {
        return $this->rawClient()->listTables()['TableNames'] ?? [];
    }
}
