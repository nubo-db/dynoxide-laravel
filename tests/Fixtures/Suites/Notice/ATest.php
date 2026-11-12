<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Fixtures\Suites\Notice;

use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureReport;
use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureTestCase;

final class ATest extends FixtureTestCase
{
    public function test_runs_with_the_drivers_phpunit_xml_chose(): void
    {
        FixtureReport::record('a', ['cache.default' => config('cache.default')]);
        $this->assertTrue($this->dynoxideServer()->isRunning());
    }
}
