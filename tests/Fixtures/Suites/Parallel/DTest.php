<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Fixtures\Suites\Parallel;

use Dynoxide\Laravel\Testing\DynoxideProcess;
use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureReport;
use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureTestCase;
use Illuminate\Support\Facades\Cache;

final class DTest extends FixtureTestCase
{
    public function test_reports_its_server(): void
    {
        Cache::put('worker', 'D', 60);

        FixtureReport::record('d', [
            'endpoint' => $this->dynoxideServer()->endpoint,
            'starts' => DynoxideProcess::starts(),
            'created' => DynoxideProcess::tablesCreated(),
            'cache' => count($this->itemsIn('cache')),
        ]);
    }
}
