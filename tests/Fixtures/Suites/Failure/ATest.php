<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Fixtures\Suites\Failure;

use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureReport;
use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureTestCase;

final class ATest extends FixtureTestCase
{
    public function test_never_runs(): void
    {
        FixtureReport::record('a');
        $this->fail('the set-up should have failed');
    }
}
