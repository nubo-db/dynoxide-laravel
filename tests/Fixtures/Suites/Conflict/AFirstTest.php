<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Fixtures\Suites\Conflict;

use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureReport;
use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureTestCase;

final class AFirstTest extends FixtureTestCase
{
    public function test_starts_the_server(): void
    {
        FixtureReport::record('first');
        $this->assertTrue($this->dynoxideServer()->isRunning());
    }
}
