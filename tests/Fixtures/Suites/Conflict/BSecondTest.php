<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Fixtures\Suites\Conflict;

use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureReport;
use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureTestCase;

final class BSecondTest extends FixtureTestCase
{
    protected function dynoxideOptions(): array
    {
        return ['startTimeout' => 9.0];
    }

    public function test_asks_for_other_options(): void
    {
        FixtureReport::record('second');
    }
}
