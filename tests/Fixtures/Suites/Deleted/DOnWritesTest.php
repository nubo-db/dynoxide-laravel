<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Fixtures\Suites\Deleted;

use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureReport;
use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureTestCase;
use Illuminate\Support\Facades\Cache;

final class DOnWritesTest extends FixtureTestCase
{
    protected function dynoxideResetsTables(): bool
    {
        return true;
    }

    public function test_writes_to_it(): void
    {
        Cache::put('written', 'yes', 60);

        FixtureReport::record('DOnWrites', ['tables' => $this->tableNames(), 'value' => Cache::get('written')]);
        $this->assertSame('yes', Cache::get('written'));
    }
}
