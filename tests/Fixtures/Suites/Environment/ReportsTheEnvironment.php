<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Fixtures\Suites\Environment;

use Aws\DynamoDb\DynamoDbClient;
use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureReport;
use Illuminate\Support\Facades\Cache;

/**
 * Records the AWS variables the app sees, what the S3 disk's config read
 * from them, and whether a DynamoDB client the app builds from the
 * environment itself reaches Dynoxide.
 */
trait ReportsTheEnvironment
{
    private const NAMES = [
        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY',
        'AWS_SESSION_TOKEN',
        'AWS_REGION',
        'AWS_DEFAULT_REGION',
        'DYNAMODB_ENDPOINT',
        'AWS_ENDPOINT_URL_DYNAMODB',
        'AWS_IGNORE_CONFIGURED_ENDPOINT_URLS',
    ];

    private function reportTheEnvironment(string $label): void
    {
        Cache::put('through', 'dynoxide', 60);

        try {
            $client = new DynamoDbClient(['version' => 'latest', 'region' => env('AWS_DEFAULT_REGION'), 'endpoint' => env('DYNAMODB_ENDPOINT')]);
            $ownClient = in_array('cache', $client->listTables()['TableNames'] ?? [], true) ? 'reached Dynoxide' : 'no cache table';
        } catch (\Throwable $e) {
            $ownClient = $e::class;
        }

        FixtureReport::record($label, [
            'env' => array_combine(self::NAMES, array_map(static fn (string $name): string|false => getenv($name), self::NAMES)),
            's3' => [config('filesystems.disks.s3.key'), config('filesystems.disks.s3.region')],
            'endpoint' => $this->dynoxideServer()->endpoint,
            'cache' => count($this->itemsIn('cache')),
            'ownClient' => $ownClient,
        ]);
        $this->assertSame('dynoxide', Cache::get('through'));
    }
}
