<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests;

use Aws\DynamoDb\DynamoDbClient;
use Dynoxide\Dynoxide;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Runs the suites in tests/Fixtures/Suites under PHPUnit and ParaTest, each in
 * a fresh process, for what only shows at the scale of a process: the first
 * test, a failed start, the core's PHPUnit extension, and parallel workers.
 *
 * The children run with no AWS credentials, profile or endpoint in their
 * environment and the metadata lookup off, as tests/bootstrap.php leaves
 * this process, so nothing they do can reach AWS.
 */
final class UsesDynoxideProcessTest extends PHPUnitTestCase
{
    private const ROOT = __DIR__ . '/..';

    private const SUITES = __DIR__ . '/Fixtures/Suites';

    private const WRAPPED = "The Dynoxide testing trait couldn't start Dynoxide";

    private ?string $dir = null;

    /** The counting binary is a shell script, so this is in the posix group. */
    #[Group('posix')]
    public function test_a_failed_start_is_tried_once_and_fails_every_test_with_the_reason(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('The counting binary is a shell script.');
        }
        $runs = $this->dir() . '/runs.txt';
        $binary = $this->dir() . '/counting-dynoxide';
        file_put_contents($binary, "#!/bin/sh\necho run >> \"\$COUNT_FILE\"\nexit 1\n");
        chmod($binary, 0755);

        [$code, $output, $reports] = $this->runSuite('Failure', env: ['FIXTURE_BINARY' => $binary, 'COUNT_FILE' => $runs]);

        $this->assertNotSame(0, $code);
        $this->assertSame([], $reports, 'no test body ran');
        $this->assertSame(3, substr_count($output, self::WRAPPED), $output);
        $this->assertStringContainsString('counting-dynoxide', $output);
        $this->assertCount(1, file($runs, FILE_SKIP_EMPTY_LINES) ?: [], 'one attempt for three tests');
    }

    public function test_a_binary_that_is_not_there_is_named_in_the_error(): void
    {
        [$code, $output, $reports] = $this->runSuite('Failure', env: ['FIXTURE_BINARY' => $this->dir() . '/no-such-dynoxide']);

        $this->assertNotSame(0, $code);
        $this->assertSame([], $reports);
        $this->assertSame(3, substr_count($output, self::WRAPPED), $output);
        $this->assertStringContainsString('no-such-dynoxide', $output);
        $this->assertStringContainsString('Nothing falls back to AWS', $output);
    }

    public function test_two_classes_asking_for_different_options_get_an_error_naming_both(): void
    {
        [$code, $output, $reports] = $this->runSuite('Conflict');

        $this->assertNotSame(0, $code);
        $this->assertSame(['first'], array_column($reports, 'label'), $output);
        $this->assertStringContainsString('Conflict\AFirstTest', $output);
        $this->assertStringContainsString('Conflict\BSecondTest', $output);
        $this->assertStringContainsString('dynoxideOptions()', $output);
    }

    public function test_the_extensions_server_is_used_and_its_schema_tables_are_emptied(): void
    {
        $schema = $this->dir() . '/schema.json';
        file_put_contents($schema, json_encode(array_map(static fn (string $table): array => ['Table' => [
            'TableName' => $table,
            'KeySchema' => [['AttributeName' => 'key', 'KeyType' => 'HASH']],
            'AttributeDefinitions' => [['AttributeName' => 'key', 'AttributeType' => 'S']],
        ]], ['cache', 'from_schema'])));

        [$code, $output, $reports] = $this->runSuite('Extension', extension: ['schema' => $schema]);

        $this->assertNotSame(0, $code, 'the class asking for options fails');
        $put = $this->report($reports, 'put');
        $this->assertSame($put['extension'], $put['endpoint'], 'the trait uses the extension\'s server');
        $this->assertSame(0, $put['starts'], 'and starts none of its own');
        $this->assertSame([], $put['created'], 'the schema had made both tables');
        $this->assertSame(1, $put['cache']);
        $this->assertSame(1, $put['from_schema']);
        $gone = $this->report($reports, 'gone');
        $this->assertSame(0, $gone['cache']);
        $this->assertSame(0, $gone['from_schema']);
        $this->assertNull($this->report($reports, 'options', required: false));
        $this->assertStringContainsString('Dynoxide PHPUnit extension', $output);
        $this->assertStringContainsString('its options belong in phpunit.xml', $output);
        $this->assertStringContainsString('BAsksForOptionsTest', $output);
        $this->assertSame(1, substr_count($output, ', listening on '), $output);
    }

    public function test_a_failed_extension_fails_the_tests_and_no_second_server_starts(): void
    {
        [$code, $output, $reports] = $this->runSuite('Extension', extension: ['binary' => $this->dir() . '/no-such-dynoxide']);

        $this->assertNotSame(0, $code);
        $this->assertSame([], $reports, $output);
        // The second test of the first class depends on the first, so it's skipped.
        $this->assertSame(2, substr_count($output, 'the Dynoxide PHPUnit extension in phpunit.xml failed to start it'), $output);
        $this->assertStringContainsString('no-such-dynoxide', $output);
        $this->assertStringNotContainsString(', listening on ', $output);
    }

    public function test_a_persisted_database_starts_the_first_test_with_the_apps_tables_empty(): void
    {
        $db = $this->dir() . '/persisted.db';
        $this->persist($db, 'key');

        [$code, $output, $reports] = $this->runSuite('Persisted', env: ['FIXTURE_DB' => $db]);

        $this->assertSame(0, $code, $output);
        $first = $this->report($reports, 'first');
        $this->assertSame(0, $first['cache'], 'the earlier run\'s cache item is gone');
        $this->assertSame(1, $first['kept'], 'a table the app\'s config doesn\'t name is left alone');
        $this->assertSame([], $first['created'], 'the table was already there');
    }

    public function test_a_persisted_table_with_another_key_is_an_error_naming_both_keys(): void
    {
        $db = $this->dir() . '/persisted.db';
        $this->persist($db, 'key');

        [$code, $output, $reports] = $this->runSuite('Persisted', env: ['FIXTURE_DB' => $db, 'FIXTURE_KEY' => 'id']);

        $this->assertNotSame(0, $code);
        $this->assertSame([], $reports);
        $this->assertStringContainsString('"cache"', $output);
        $this->assertStringContainsString('key HASH', $output);
        $this->assertStringContainsString('id HASH', $output);
    }

    public function test_a_test_case_that_resets_starts_clean_after_one_that_does_not(): void
    {
        [$code, $output, $reports] = $this->runSuite('ResetOff');

        $this->assertSame(0, $code, $output);
        $this->assertSame(1, $this->report($reports, 'kept')['cache']);
        $this->assertSame(0, $this->report($reports, 'reset')['cache']);
    }

    public function test_paratest_workers_each_start_their_own_server(): void
    {
        [$code, $output, $reports] = $this->runSuite('Parallel', tool: 'paratest', args: ['--processes=2']);

        $this->assertSame(0, $code, $output);
        $this->assertWorkersHaveTheirOwnServers($reports);
    }

    public function test_paratest_workers_each_get_their_own_database_file(): void
    {
        $db = $this->dir() . '/parallel.db';

        [$code, $output, $reports] = $this->runSuite('Parallel', tool: 'paratest', args: ['--processes=2'], env: ['FIXTURE_DB' => $db]);

        $this->assertSame(0, $code, $output);
        $this->assertWorkersHaveTheirOwnServers($reports);
        foreach (array_unique(array_column($reports, 'token')) as $token) {
            $this->assertFileExists("{$db}-{$token}");
        }
        $this->assertFileDoesNotExist($db, 'no worker uses the file a serial run would');
    }

    public function test_a_stock_phpunit_xml_gets_one_notice_naming_the_lines_to_change(): void
    {
        [$code, $output, $reports] = $this->runSuite('Notice', env: [], php: ['CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array']);

        $this->assertSame(0, $code, $output);
        $this->assertCount(2, $reports);
        $this->assertSame(1, substr_count($output, 'nothing in this app uses DynamoDB'), $output);
        $this->assertStringContainsString('set these in phpunit.xml', $output);
        foreach (['CACHE_STORE', 'SESSION_DRIVER', 'QUEUE_FAILED_DRIVER'] as $named) {
            $this->assertStringContainsString("<env name=\"{$named}\"", $output);
        }
    }

    /** @return iterable<string, array{array<string, string>, array<string, string>}> */
    public static function usesOfDynamoDb(): iterable
    {
        yield 'the default cache store' => [['CACHE_STORE' => 'dynamodb'], []];
        yield 'sessions' => [['CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'dynamodb'], []];
        yield 'failed jobs' => [['CACHE_STORE' => 'array', 'QUEUE_FAILED_DRIVER' => 'dynamodb'], []];
        yield 'the rate limiter' => [['CACHE_STORE' => 'array'], ['FIXTURE_LIMITER' => 'dynamodb']];
    }

    /**
     * @param array<string, string> $php
     * @param array<string, string> $env
     */
    #[DataProvider('usesOfDynamoDb')]
    public function test_an_app_that_uses_dynamodb_gets_no_notice(array $php, array $env): void
    {
        [$code, $output, $reports] = $this->runSuite('Notice', env: $env, php: $php);

        $this->assertSame(0, $code, $output);
        $this->assertCount(2, $reports);
        $this->assertStringNotContainsString('nothing in this app uses DynamoDB', $output);
    }

    /**
     * Credentials and a region set in the shell, as aws-vault or an SSO
     * login does, or in phpunit.xml, are left alone: the DynamoDB stores get
     * theirs from the trait's config.
     */
    public function test_aws_credentials_and_region_from_the_shell_are_kept(): void
    {
        [$code, $output, $reports] = $this->runSuite('Environment', env: [
            'FIXTURE_ENV_DIR' => $this->envDir(['APP_NAME' => 'fixture']),
            'FIXTURE_AWS' => (string) json_encode([
                'AWS_ACCESS_KEY_ID' => 'minioadmin',
                'AWS_SECRET_ACCESS_KEY' => 'miniosecret',
                'AWS_SESSION_TOKEN' => 'a-session-token',
                'AWS_DEFAULT_REGION' => 'eu-west-2',
            ]),
        ]);

        $this->assertSame(0, $code, $output);
        foreach (['a', 'b'] as $label) {
            $report = $this->report($reports, $label);
            $this->assertSame(self::environment($report['endpoint'], [
                'AWS_ACCESS_KEY_ID' => 'minioadmin',
                'AWS_SECRET_ACCESS_KEY' => 'miniosecret',
                'AWS_SESSION_TOKEN' => 'a-session-token',
                'AWS_DEFAULT_REGION' => 'eu-west-2',
            ]), $report['env'], $label);
            $this->assertSame(['minioadmin', 'eu-west-2'], $report['s3'], $label);
            $this->assertSame(1, $report['cache'], "{$label}: the cache still reaches Dynoxide");
        }
    }

    /** @return iterable<string, array{string, array<string, string>, list<string>}> */
    public static function envFiles(): iterable
    {
        foreach (['.env.testing' => ['APP_ENV' => 'testing'], '.env' => []] as $file => $php) {
            yield "{$file}, in the usual order" => [$file, $php, []];
            yield "{$file}, the other way round" => [$file, $php, ['--order-by=reverse']];
        }
    }

    /**
     * Laravel's own place for them, and Sail's MinIO set-up. The app loads
     * its env file only once the trait has started the server, and keeps
     * whatever is already in the environment, so the trait mustn't have put
     * its own there by then.
     *
     * @param array<string, string> $php
     * @param list<string> $args
     */
    #[DataProvider('envFiles')]
    public function test_aws_credentials_and_region_from_the_apps_env_file_are_kept(string $file, array $php, array $args): void
    {
        [$code, $output, $reports] = $this->runSuite('Environment', args: $args, env: [
            'FIXTURE_ENV_DIR' => $this->envDir([
                'AWS_ACCESS_KEY_ID' => 'sail',
                'AWS_SECRET_ACCESS_KEY' => 'password',
                'AWS_DEFAULT_REGION' => 'eu-west-2',
            ], $file),
        ], php: ['CACHE_STORE' => 'dynamodb'] + $php);

        $this->assertSame(0, $code, $output);
        foreach (['a', 'b'] as $label) {
            $report = $this->report($reports, $label);
            $this->assertSame(self::environment($report['endpoint'], [
                'AWS_ACCESS_KEY_ID' => 'sail',
                'AWS_SECRET_ACCESS_KEY' => 'password',
                'AWS_DEFAULT_REGION' => 'eu-west-2',
            ]), $report['env'], $label);
            $this->assertSame(['sail', 'eu-west-2'], $report['s3'], $label);
            $this->assertSame(1, $report['cache'], $label);
        }
    }

    /**
     * With none anywhere, the dummy ones are exported once the first app
     * has loaded its env file, so a client the app builds from the
     * environment in a later test reaches Dynoxide.
     *
     * @param array<string, string> $lines
     */
    #[DataProvider('envFilesWithoutCredentials')]
    public function test_with_no_aws_credentials_the_dummy_ones_are_exported(array $lines): void
    {
        [$code, $output, $reports] = $this->runSuite('Environment', env: [
            'FIXTURE_ENV_DIR' => $this->envDir($lines),
        ]);

        $this->assertSame(0, $code, $output);
        $b = $this->report($reports, 'b');
        $this->assertSame(self::environment($b['endpoint'], [
            'AWS_ACCESS_KEY_ID' => 'dynoxide',
            'AWS_SECRET_ACCESS_KEY' => 'dynoxide',
            // Empty rather than unset, so a later app's env file load leaves
            // it alone. The SDK reads an empty token as none.
            'AWS_SESSION_TOKEN' => '',
            'AWS_REGION' => 'us-east-1',
            'AWS_DEFAULT_REGION' => 'us-east-1',
        ]), $b['env']);
        $this->assertSame(['dynoxide', 'us-east-1'], $b['s3']);
        $this->assertSame('reached Dynoxide', $b['ownClient']);
    }

    /** @return array<string, array{array<string, string>}> */
    public static function envFilesWithoutCredentials(): array
    {
        return [
            'no AWS lines' => [['APP_NAME' => 'fixture']],
            // As Laravel's own .env.example has them. Every app after the
            // first loads them again.
            'empty AWS lines' => [['APP_NAME' => 'fixture', 'AWS_ACCESS_KEY_ID' => '', 'AWS_SECRET_ACCESS_KEY' => '', 'AWS_DEFAULT_REGION' => '']],
            // A token on its own is no use, and belongs to other credentials
            // than the dummy ones.
            'an empty key line and a session token' => [['APP_NAME' => 'fixture', 'AWS_ACCESS_KEY_ID' => '', 'AWS_SECRET_ACCESS_KEY' => '', 'AWS_SESSION_TOKEN' => 'from-env-file']],
        ];
    }

    /**
     * What the Environment suite should see: the endpoint variables, plus
     * the AWS ones given, and nothing else.
     *
     * @param array<string, string> $aws
     * @return array<string, string|false>
     */
    private static function environment(string $endpoint, array $aws): array
    {
        $names = ['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_SESSION_TOKEN', 'AWS_REGION', 'AWS_DEFAULT_REGION'];

        return array_merge(array_fill_keys($names, false), $aws, [
            'DYNAMODB_ENDPOINT' => $endpoint,
            'AWS_ENDPOINT_URL_DYNAMODB' => $endpoint,
            'AWS_IGNORE_CONFIGURED_ENDPOINT_URLS' => 'false',
        ]);
    }

    /**
     * A directory holding one env file with these lines.
     *
     * @param array<string, string> $lines
     */
    private function envDir(array $lines, string $file = '.env'): string
    {
        $dir = $this->dir() . '/env-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("{$dir}/{$file}", implode("\n", array_map(
            static fn (string $name, string $value): string => "{$name}={$value}",
            array_keys($lines),
            $lines,
        )) . "\n");

        return $dir;
    }

    /** @return iterable<string, array{array<string, string>, bool}> */
    public static function isolatedRuns(): iterable
    {
        yield 'an app on DynamoDB' => [['CACHE_STORE' => 'dynamodb'], false];
        yield 'an app not on DynamoDB, which gets the notice' => [['CACHE_STORE' => 'array'], false];
        yield 'with the extension' => [['CACHE_STORE' => 'dynamodb'], true];
    }

    /**
     * PHPUnit fails a test whose separate process writes anything to stderr,
     * so the trait writes nothing there in one.
     *
     * @param array<string, string> $php
     */
    #[DataProvider('isolatedRuns')]
    public function test_a_test_in_a_separate_process_starts_its_own_server_quietly(array $php, bool $extension): void
    {
        [$code, $output, $reports] = $this->runSuite('Isolated', php: $php, extension: $extension ? [] : null);

        $this->assertSame(0, $code, $output);
        $isolated = $this->report($reports, 'isolated');
        $main = $this->report($reports, 'main');
        $this->assertNotSame($main['pid'], $isolated['pid']);
        $this->assertNotSame($main['endpoint'], $isolated['endpoint'], 'the child has a server of its own');
        if ($php['CACHE_STORE'] === 'dynamodb') {
            $this->assertSame('yes', $isolated['cache']);
        }
    }

    public function test_the_same_options_written_differently_share_one_server(): void
    {
        [$code, $output, $reports] = $this->runSuite('SameOptions');

        $this->assertSame(0, $code, $output);
        $this->assertSame(['again', 'first', 'second'], $this->labels($reports));
    }

    public function test_a_server_that_died_fails_the_later_tests_with_its_exit_status(): void
    {
        [$code, $output, $reports] = $this->runSuite('Died');

        $this->assertNotSame(0, $code);
        $this->assertSame(0, $this->report($reports, 'stopped')['status']);
        $this->assertNull($this->report($reports, 'after', required: false), $output);
        $this->assertStringContainsString('The Dynoxide server the tests use has stopped: it exited with status 0.', $output);
    }

    public function test_a_table_a_test_deleted_is_there_again_for_the_next_test(): void
    {
        [$code, $output, $reports] = $this->runSuite('Deleted');

        $this->assertSame(0, $code, $output);
        $this->assertNotContains('cache', $this->report($reports, 'AOffDeletes')['tables']);
        $this->assertNotContains('cache', $this->report($reports, 'COnDeletes')['tables']);
        foreach (['BOffWrites', 'DOnWrites'] as $label) {
            $report = $this->report($reports, $label);
            $this->assertContains('cache', $report['tables'], $label);
            $this->assertSame('yes', $report['value'], $label);
        }
    }

    /**
     * The server starts before the first app does, so a read in boot()
     * reaches Dynoxide rather than AWS. In the first test of a process the
     * tables don't exist yet, so that read fails; from the second test on it
     * works.
     */
    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function bootTimeCredentials(): array
    {
        return [
            // The dummy credentials are settled after the first boot, so a
            // read in it has none to sign with.
            'no credentials' => [[], 'CredentialsException'],
            'credentials set for the run' => [['AWS_ACCESS_KEY_ID' => 'any', 'AWS_SECRET_ACCESS_KEY' => 'any'], 'ResourceNotFoundException'],
        ];
    }

    /**
     * @param array<string, string> $credentials
     */
    #[DataProvider('bootTimeCredentials')]
    public function test_a_cache_read_in_boot_fails_in_the_first_test_only(array $credentials, string $firstFailure): void
    {
        [$code, $output, $reports] = $this->runSuite('BootTime', env: $credentials === [] ? [] : [
            'FIXTURE_AWS' => (string) json_encode($credentials),
        ]);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString($firstFailure, $this->report($reports, 'first')['boot']);
        $this->assertSame('read NULL', $this->report($reports, 'second')['boot']);
    }

    /**
     * @param list<array<string, mixed>> $reports
     */
    private function assertWorkersHaveTheirOwnServers(array $reports): void
    {
        $this->assertSame(['a', 'b', 'c', 'd'], $this->labels($reports));
        $byToken = [];
        foreach ($reports as $report) {
            $this->assertNotSame('', $report['token'], 'ran in a worker');
            $this->assertSame(1, $report['starts']);
            $this->assertSame(1, $report['created']['cache'] ?? null, 'each worker made its own table');
            $this->assertSame(1, $report['cache'], 'and saw only its own test\'s item');
            $byToken[$report['token']][] = $report['endpoint'];
        }
        $this->assertGreaterThanOrEqual(2, count($byToken), 'more than one worker ran tests');
        $endpoints = [];
        foreach ($byToken as $token => $seen) {
            $this->assertCount(1, array_unique($seen), "worker {$token} used one server");
            $endpoints[] = $seen[0];
        }
        $this->assertCount(count($endpoints), array_unique($endpoints), 'no two workers shared one');
    }

    /**
     * A database file with the `cache` table keyed on $key holding one item,
     * and a table the app's config doesn't name, also holding one.
     */
    private function persist(string $db, string $key): void
    {
        $server = Dynoxide::start(['dbPath' => $db, 'log' => static function (): void {}]);
        try {
            $client = new DynamoDbClient($server->clientConfig());
            foreach (['cache' => $key, 'not_the_apps' => 'pk'] as $table => $attribute) {
                $client->createTable([
                    'TableName' => $table,
                    'KeySchema' => [['AttributeName' => $attribute, 'KeyType' => 'HASH']],
                    'AttributeDefinitions' => [['AttributeName' => $attribute, 'AttributeType' => 'S']],
                    'BillingMode' => 'PAY_PER_REQUEST',
                ]);
                $client->putItem(['TableName' => $table, 'Item' => [$attribute => ['S' => 'from-an-earlier-run']]]);
            }
        } finally {
            $server->stop();
        }
    }

    /**
     * Runs one fixture suite in a fresh process.
     *
     * @param array<string, string> $env for the process
     * @param array<string, string> $php `<env>` lines for its phpunit.xml, as a stock Laravel one has
     * @param array<string, string>|null $extension the core extension's parameters, if it's configured
     * @param list<string> $args
     * @return array{int, string, list<array<string, mixed>>}
     */
    private function runSuite(string $suite, string $tool = 'phpunit', array $args = [], array $env = [], array $php = ['CACHE_STORE' => 'dynamodb'], ?array $extension = null): array
    {
        $config = $this->dir() . '/phpunit.xml';
        $parameters = '';
        foreach ($extension ?? [] as $name => $value) {
            $parameters .= '<parameter name="' . $name . '" value="' . htmlspecialchars($value) . '"/>';
        }
        file_put_contents($config, strtr((string) file_get_contents(self::SUITES . '/phpunit.xml.in'), [
            '{{BOOTSTRAP}}' => realpath(self::ROOT . '/tests/bootstrap.php'),
            '{{TESTS}}' => realpath(self::SUITES . "/{$suite}"),
            '{{ENV}}' => implode("\n        ", array_map(
                static fn (string $name, string $value): string => '<env name="' . $name . '" value="' . htmlspecialchars($value) . '" force="true"/>',
                array_keys($php),
                $php,
            )),
            '{{EXTENSIONS}}' => $extension === null ? '' : "<extensions><bootstrap class=\"Dynoxide\\PHPUnit\\Extension\">{$parameters}</bootstrap></extensions>",
        ]));
        $report = $this->dir() . '/report.jsonl';
        file_put_contents($report, '');
        $output = $this->dir() . '/output.txt';
        file_put_contents($output, '');

        // Not getenv(): by now the apps earlier tests created have loaded
        // Testbench's .env into it, and a child would read CACHE_STORE and
        // the rest from there before its own phpunit.xml.
        $childEnv = [];
        foreach (DYNOXIDE_LARAVEL_TEST_ENVIRONMENT as $name => $value) {
            if (!in_array($name, ['PARATEST', 'TEST_TOKEN', 'UNIQUE_TEST_TOKEN'], true) && !str_starts_with($name, 'FIXTURE_')) {
                $childEnv[$name] = $value;
            }
        }
        $childEnv = array_merge($childEnv, ['FIXTURE_REPORT' => $report], $env);

        $process = proc_open(
            [PHP_BINARY, realpath(self::ROOT . "/vendor/bin/{$tool}"), '-c', $config, '--colors=never', ...$args],
            [0 => ['pipe', 'r'], 1 => ['file', $output, 'a'], 2 => ['file', $output, 'a']],
            $pipes,
            realpath(self::ROOT),
            $childEnv,
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $deadline = microtime(true) + 180;
        while (($status = proc_get_status($process))['running'] && microtime(true) < $deadline) {
            usleep(50_000);
        }
        if ($status['running']) {
            proc_terminate($process, 9);
            $this->fail("{$tool} was still running after 180 seconds:\n" . file_get_contents($output));
        }
        proc_close($process);

        $reports = [];
        foreach (file($report, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $reports[] = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
        }

        return [$status['exitcode'], (string) file_get_contents($output), $reports];
    }

    /**
     * @param list<array<string, mixed>> $reports
     * @return array<string, mixed>|null
     */
    private function report(array $reports, string $label, bool $required = true): ?array
    {
        foreach ($reports as $report) {
            if ($report['label'] === $label) {
                return $report;
            }
        }
        if ($required) {
            $this->fail("No report labelled {$label}: " . json_encode($reports));
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $reports
     * @return list<string>
     */
    private function labels(array $reports): array
    {
        $labels = array_column($reports, 'label');
        sort($labels);

        return $labels;
    }

    private function dir(): string
    {
        if ($this->dir === null) {
            $this->dir = sys_get_temp_dir() . '/dynoxide-laravel-test-' . bin2hex(random_bytes(6));
            mkdir($this->dir, 0700);
            $this->dir = realpath($this->dir) ?: $this->dir;
        }

        return $this->dir;
    }

    #[After]
    protected function removeDir(): void
    {
        if ($this->dir === null) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
        $this->dir = null;
    }
}
