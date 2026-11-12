<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Fixtures\Suites\SameOptions;

use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureReport;
use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureTestCase;

/**
 * Asks for a new log closure each time, and a whole number of seconds.
 */
final class AFirstTest extends FixtureTestCase
{
    protected function dynoxideOptions(): array
    {
        return ['startTimeout' => 10, 'log' => static function (string $line): void {}];
    }

    public function test_starts_the_server(): void
    {
        FixtureReport::record('first');
        $this->assertTrue($this->dynoxideServer()->isRunning());
    }

    public function test_and_asks_again(): void
    {
        FixtureReport::record('again');
        $this->assertTrue($this->dynoxideServer()->isRunning());
    }
}
