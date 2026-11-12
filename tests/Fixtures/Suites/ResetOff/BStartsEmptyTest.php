<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Fixtures\Suites\ResetOff;

use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureReport;
use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureTestCase;

final class BStartsEmptyTest extends FixtureTestCase
{
    public function test_starts_without_the_earlier_classs_item(): void
    {
        FixtureReport::record('reset', ['cache' => count($this->itemsIn('cache'))]);
    }
}
