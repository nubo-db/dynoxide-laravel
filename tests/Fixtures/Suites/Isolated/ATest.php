<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Fixtures\Suites\Isolated;

use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureReport;
use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureTestCase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class ATest extends FixtureTestCase
{
    #[RunInSeparateProcess]
    public function test_runs_in_a_process_of_its_own(): void
    {
        Cache::put('isolated', 'yes', 60);

        FixtureReport::record('isolated', [
            'endpoint' => $this->dynoxideServer()->endpoint,
            'cache' => Cache::get('isolated'),
        ]);
        $this->assertSame('yes', Cache::get('isolated'));
    }
}
