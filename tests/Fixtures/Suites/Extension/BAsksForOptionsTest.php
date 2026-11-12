<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Fixtures\Suites\Extension;

use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureReport;
use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureTestCase;

final class BAsksForOptionsTest extends FixtureTestCase
{
    protected function dynoxideOptions(): array
    {
        return ['startTimeout' => 9.0];
    }

    public function test_asks_for_options_the_extension_owns(): void
    {
        FixtureReport::record('options');
    }
}
