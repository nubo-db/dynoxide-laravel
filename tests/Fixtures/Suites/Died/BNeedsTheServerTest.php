<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Fixtures\Suites\Died;

use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureReport;
use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureTestCase;

final class BNeedsTheServerTest extends FixtureTestCase
{
    public function test_needs_the_server(): void
    {
        FixtureReport::record('after');
        $this->assertTrue($this->dynoxideServer()->isRunning());
    }
}
