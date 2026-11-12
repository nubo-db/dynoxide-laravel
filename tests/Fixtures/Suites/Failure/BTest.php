<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Fixtures\Suites\Failure;

use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureReport;
use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureTestCase;

final class BTest extends FixtureTestCase
{
    public function test_never_runs(): void
    {
        FixtureReport::record('b');
        $this->fail('the set-up should have failed');
    }
}
