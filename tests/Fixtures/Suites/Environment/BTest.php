<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Fixtures\Suites\Environment;

use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureTestCase;

final class BTest extends FixtureTestCase
{
    use ReportsTheEnvironment;

    public function test_reports_the_environment_the_app_sees(): void
    {
        $this->reportTheEnvironment('b');
    }
}
