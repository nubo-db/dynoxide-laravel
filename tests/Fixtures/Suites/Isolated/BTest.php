<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Fixtures\Suites\Isolated;

use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureReport;
use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureTestCase;

final class BTest extends FixtureTestCase
{
    public function test_runs_in_the_main_process(): void
    {
        FixtureReport::record('main', ['endpoint' => $this->dynoxideServer()->endpoint]);
        $this->assertTrue($this->dynoxideServer()->isRunning());
    }
}
