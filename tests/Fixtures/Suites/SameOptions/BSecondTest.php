<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Fixtures\Suites\SameOptions;

use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureReport;
use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureTestCase;

/**
 * The same options as AFirstTest, written differently: the keys the other
 * way round, a closure of its own and the seconds as a float.
 */
final class BSecondTest extends FixtureTestCase
{
    protected function dynoxideOptions(): array
    {
        return ['log' => static function (string $line): void {}, 'startTimeout' => 10.0];
    }

    public function test_shares_the_server(): void
    {
        FixtureReport::record('second');
        $this->assertTrue($this->dynoxideServer()->isRunning());
    }
}
