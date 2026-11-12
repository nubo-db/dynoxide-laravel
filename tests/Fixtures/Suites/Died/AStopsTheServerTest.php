<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Fixtures\Suites\Died;

use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureReport;
use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureTestCase;

/**
 * Stops the server, as a crash would. Its tables aren't reset, so its own
 * tear-down doesn't call the server.
 */
final class AStopsTheServerTest extends FixtureTestCase
{
    protected function dynoxideResetsTables(): bool
    {
        return false;
    }

    public function test_stops_the_server(): void
    {
        $server = $this->dynoxideServer();
        FixtureReport::record('stopped', ['status' => $server->stop()]);
        $this->assertFalse($server->isRunning());
    }
}
