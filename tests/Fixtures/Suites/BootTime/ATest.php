<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Fixtures\Suites\BootTime;

use Dynoxide\Laravel\DynoxideServiceProvider;
use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureReport;
use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureTestCase;

final class ATest extends FixtureTestCase
{
    protected function getPackageProviders($app): array
    {
        return [DynoxideServiceProvider::class, ReadsTheCacheInBoot::class];
    }

    public function test_first(): void
    {
        FixtureReport::record('first', ['boot' => ReadsTheCacheInBoot::$outcome]);
    }

    public function test_second(): void
    {
        FixtureReport::record('second', ['boot' => ReadsTheCacheInBoot::$outcome]);
    }
}
