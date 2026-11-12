<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Testing;

use Dynoxide\Server;
use PHPUnit\Framework\Attributes\Before;

/**
 * Runs a test case's DynamoDB cache stores, locks, rate limiter, sessions and
 * failed jobs against Dynoxide.
 *
 * One server serves the whole PHP process, started by the first test that
 * needs it, or the one the Dynoxide PHPUnit extension started. Each test gets
 * the app's DynamoDB stores pointed at it, with the tables the app's config
 * names, and those tables are emptied after the test. The trait never changes
 * which drivers the app uses: phpunit.xml chooses those.
 *
 *     abstract class TestCase extends BaseTestCase
 *     {
 *         use UsesDynoxide;
 *     }
 */
trait UsesDynoxide
{
    /**
     * Starts the server, or finds the one already running, before the app is
     * created, so the app reads its endpoint and credentials from the
     * environment as it boots.
     */
    #[Before]
    protected function startDynoxideBeforeTheApplication(): void
    {
        DynoxideProcess::server(static::class, $this->dynoxideOptions());
    }

    /**
     * Laravel calls this once the app has been created, before each test.
     */
    protected function setUpUsesDynoxide(): void
    {
        DynoxideProcess::server(static::class, $this->dynoxideOptions());
        DynoxideProcess::prepare($this->app, $this->dynoxideResetsTables());
    }

    /**
     * Laravel calls this before the app is destroyed, after each test.
     */
    protected function tearDownUsesDynoxide(): void
    {
        if ($this->dynoxideResetsTables()) {
            DynoxideProcess::emptyTables();
        }
    }

    /**
     * Options for starting the server, as Dynoxide::start() takes them, such
     * as ['dbPath' => ...]. Under ParaTest each worker's dbPath gets the
     * worker's number appended. The process has one server, so every test
     * case in it must ask for the same options, and none may ask for any
     * when the Dynoxide PHPUnit extension runs the server.
     *
     * @return array<string, mixed>
     */
    protected function dynoxideOptions(): array
    {
        return [];
    }

    /**
     * Whether the tables are emptied after each test. Return false to keep
     * items from one test to the next.
     */
    protected function dynoxideResetsTables(): bool
    {
        return true;
    }

    /**
     * The running server, for a client of the test's own:
     * new DynamoDbClient($this->dynoxideServer()->clientConfig()).
     */
    protected function dynoxideServer(): Server
    {
        return DynoxideProcess::server(static::class, $this->dynoxideOptions());
    }
}
