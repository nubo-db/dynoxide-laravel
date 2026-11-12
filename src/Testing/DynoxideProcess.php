<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Testing;

use Dynoxide\Dynoxide;
use Dynoxide\Exception\StartException;
use Dynoxide\Laravel\Exception\ConfigException;
use Dynoxide\Laravel\TableDefinitions;
use Dynoxide\Options;
use Dynoxide\Parallel;
use Dynoxide\PHPUnit\Extension;
use Dynoxide\Server;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Env;

/**
 * The server for the whole PHP process: starting it, or finding the one the
 * Dynoxide PHPUnit extension started, and checking every test case asks for
 * the same options.
 *
 * This state can't live in the trait: each class that uses a trait gets its
 * own copy of the trait's static properties, and Pest makes a class for every
 * file, so a trait would start a server per class.
 *
 * @internal The UsesDynoxide trait is the API.
 */
final class DynoxideProcess
{
    private const COULD_NOT_START = "The Dynoxide testing trait couldn't start Dynoxide";

    private static bool $attempted = false;

    private static ?Server $server = null;

    private static ?StartException $failure = null;

    private static bool $fromExtension = false;

    /** @var array{testCase: class-string, options: array<string, mixed>}|null */
    private static ?array $requested = null;

    private static ?TableSet $tables = null;

    private static bool $noticed = false;

    /** Whether the credentials and region have been settled for the process. */
    private static bool $credentialsSettled = false;

    private static int $starts = 0;

    /**
     * The process's server: the one already running, the Dynoxide PHPUnit
     * extension's, or a new one. A start is attempted once; after a failure
     * every call throws the same error.
     *
     * @param class-string $testCase
     * @param array<string, mixed> $options
     * @throws StartException when the server couldn't be started
     * @throws ConfigException when the options differ from those the server was started with
     * @throws \RuntimeException when the server has stopped since
     */
    public static function server(string $testCase, array $options): Server
    {
        if (!self::$attempted) {
            self::$attempted = true;
            self::start($testCase, $options);
        }
        if (self::$failure !== null) {
            throw self::$failure;
        }
        self::checkOptions($testCase, $options);

        /** @var Server $server */
        $server = self::$server;
        if (!$server->isRunning()) {
            // Stopping a server that has exited only reads how it exited.
            $status = $server->stop();

            throw new \RuntimeException(
                'The Dynoxide server the tests use has stopped: '
                . ($status === null ? 'it was killed by a signal.' : "it exited with status {$status}.")
                . " The tests after that can't reach it, so they fail. What it wrote before it stopped may say why.",
            );
        }

        return $server;
    }

    /**
     * Points the app's DynamoDB stores at the server, drops anything boot()
     * resolved with the old settings, and creates the tables the config names.
     */
    public static function prepare(Application $app, bool $resetsTables): void
    {
        /** @var Server $server */
        $server = self::$server;
        /** @var TableSet $tables */
        $tables = self::$tables;
        /** @var Config $config */
        $config = $app['config'];

        if (!self::$fromExtension && !self::$credentialsSettled) {
            self::$credentialsSettled = true;
            self::exportCredentials($server);
        }

        AppStores::pointAt($config, $server);
        AppStores::forgetResolved($app, $config);
        $tables->create(TableDefinitions::fromConfig($config));
        $tables->startTest($resetsTables);

        // PHPUnit fails a test whose separate process writes to stderr.
        if (!self::$noticed && !self::inSeparateProcess() && !AppStores::usesDynamoDb($config)) {
            self::$noticed = true;
            fwrite(defined('STDERR') ? STDERR : fopen('php://stderr', 'w'), AppStores::notice($config));
        }
    }

    /**
     * Deletes every item in the tables the app's config named. Tables a test
     * made for itself aren't touched. A server that has stopped is left
     * alone: the next set-up says it has stopped.
     */
    public static function emptyTables(): void
    {
        if (self::$server?->isRunning()) {
            self::$tables?->empty();
        }
    }

    /**
     * How many servers this process has started. One from the extension
     * isn't counted.
     *
     * @internal for the package's tests
     */
    public static function starts(): int
    {
        return self::$starts;
    }

    /**
     * How many times this process created each table. A table that was
     * already there isn't counted.
     *
     * @internal for the package's tests
     *
     * @return array<string, int>
     */
    public static function tablesCreated(): array
    {
        return self::$tables?->created() ?? [];
    }

    /**
     * @param class-string $testCase
     * @param array<string, mixed> $options
     */
    private static function start(string $testCase, array $options): void
    {
        self::$requested = ['testCase' => $testCase, 'options' => $options];

        try {
            $server = Extension::server();
        } catch (StartException $e) {
            // A server of the trait's own would ignore the extension's schema
            // and database, and undo the unreachable endpoint the extension
            // exported to keep the tests away from AWS.
            self::$failure = new StartException(
                self::COULD_NOT_START . ': the Dynoxide PHPUnit extension in phpunit.xml failed to start it, and the trait '
                . "doesn't start a second server that would ignore the extension's settings. " . $e->getMessage(),
                0,
                $e,
            );

            return;
        }

        if ($server !== null) {
            // The extension has already exported its server's environment.
            self::$fromExtension = true;
        } else {
            // PHPUnit fails a test whose separate process writes to stderr,
            // where the start line goes by default.
            if (self::inSeparateProcess() && !array_key_exists('log', $options)) {
                $options['log'] = static function (string $line): void {
                };
            }
            try {
                $parsed = Options::fromArray($options);
                self::$starts++;
                $server = Dynoxide::start(self::forThisWorker($parsed));
            } catch (\Throwable $e) {
                self::$failure = new StartException(
                    self::COULD_NOT_START . ", so the tests that use it can't run. Nothing falls back to AWS. " . $e->getMessage(),
                    0,
                    $e,
                );

                return;
            }
            self::exportEndpoint($server);
        }

        self::$server = $server;
        self::$tables = new TableSet(TableSet::clientFor($server));
    }

    /**
     * Points the AWS SDKs at the server through the environment before the
     * first app boots, so a stock config reads the endpoint as it does.
     */
    private static function exportEndpoint(Server $server): void
    {
        self::export($server, ['DYNAMODB_ENDPOINT', 'AWS_ENDPOINT_URL_DYNAMODB', 'AWS_IGNORE_CONFIGURED_ENDPOINT_URLS']);
    }

    /**
     * Fills in dummy credentials and a region only where the run has none.
     * Done once, after the first app has booted: by then its .env or
     * .env.testing is in the environment, and Laravel never overwrites a
     * variable that's already there, so doing it earlier would replace the
     * app's own. Credentials from the shell, as aws-vault or an SSO login
     * sets them, are kept too, so clients the tests don't fake can still
     * reach AWS. The DynamoDB stores get the server's credentials from the
     * config prepare() sets either way.
     */
    private static function exportCredentials(Server $server): void
    {
        $names = [];
        if (!self::isSet('AWS_ACCESS_KEY_ID')) {
            $names = ['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY'];
            // A session token belongs to other credentials than these. It's
            // emptied rather than unset: an unset one an env file gave would
            // come back with the next app, and the SDK reads an empty one as
            // none.
            self::release('AWS_SESSION_TOKEN');
            putenv('AWS_SESSION_TOKEN=');
            $_ENV['AWS_SESSION_TOKEN'] = $_SERVER['AWS_SESSION_TOKEN'] = '';
        }
        if (!self::isSet('AWS_REGION') && !self::isSet('AWS_DEFAULT_REGION')) {
            array_push($names, 'AWS_REGION', 'AWS_DEFAULT_REGION');
        }
        self::export($server, $names);
    }

    /** @param list<string> $names variables from the server's environment() */
    private static function export(Server $server, array $names): void
    {
        $environment = $server->environment();
        foreach ($names as $name) {
            self::release($name);
            putenv("{$name}={$environment[$name]}");
            $_ENV[$name] = $_SERVER[$name] = $environment[$name];
        }
    }

    /**
     * Has Laravel's env repository forget that it loaded a variable from an
     * env file. It is shared by every app in the process, and each app's env
     * file load rewrites the variables it loaded before, so an empty
     * `AWS_ACCESS_KEY_ID=` line, as in Laravel's own .env.example, would
     * blank the exported value again in every later test. One it didn't load
     * is left alone.
     */
    private static function release(string $name): void
    {
        Env::getRepository()->clear($name);
    }

    /** Whether a variable has a value, wherever Laravel's env() would find it. */
    private static function isSet(string $name): bool
    {
        foreach ([$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)] as $value) {
            if (is_string($value) && $value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this is the process PHPUnit starts for a test marked
     * #[RunInSeparateProcess]. PHPUnit 11 and 12 define this function in the
     * script that process runs.
     */
    private static function inSeparateProcess(): bool
    {
        return function_exists('__phpunit_run_isolated_test');
    }

    /** A ParaTest worker's server gets a database file of its own. */
    private static function forThisWorker(Options $options): Options
    {
        if ($options->dbPath === null) {
            return $options;
        }

        return new Options(
            binary: $options->binary,
            binaryFromPath: $options->binaryFromPath,
            dbPath: Parallel::workerDatabase($options->dbPath),
            schema: $options->schema,
            startTimeout: $options->startTimeout,
            log: $options->log,
        );
    }

    /**
     * @param class-string $testCase
     * @param array<string, mixed> $options
     */
    private static function checkOptions(string $testCase, array $options): void
    {
        if (self::$fromExtension) {
            if ($options !== []) {
                throw new ConfigException(
                    "{$testCase}::dynoxideOptions() asks for options, but this run's server comes from the Dynoxide PHPUnit "
                    . "extension, so its options belong in phpunit.xml, as the extension's parameters. Remove dynoxideOptions() "
                    . 'from the test case.',
                );
            }

            return;
        }

        /** @var array{testCase: class-string, options: array<string, mixed>} $requested */
        $requested = self::$requested;
        if (self::comparable($options) === self::comparable($requested['options'])) {
            return;
        }

        throw new ConfigException(sprintf(
            "%s::dynoxideOptions() asks for %s, but this process's Dynoxide server was started for %s with %s. A process "
            . 'runs one server, so every test case that uses the trait must ask for the same options. Put '
            . 'dynoxideOptions() on a base test case they share.',
            $testCase,
            self::describe($options),
            $requested['testCase'],
            self::describe($requested['options']),
        ));
    }

    /**
     * The options with the log closure left out, since a test case may make a
     * new one each time it's asked, and numbers as floats, so a timeout of 10
     * and one of 10.0 are the same.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function comparable(array $options): array
    {
        unset($options['log']);
        ksort($options);

        return array_map(static fn (mixed $value): mixed => is_int($value) ? (float) $value : $value, $options);
    }

    /** @param array<string, mixed> $options */
    private static function describe(array $options): string
    {
        $options = self::comparable($options);

        return $options === [] ? 'no options' : (string) json_encode($options, JSON_UNESCAPED_SLASHES);
    }
}
