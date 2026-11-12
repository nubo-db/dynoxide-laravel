<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Fixtures\Suites\Deleted;

use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureReport;
use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureTestCase;
use Illuminate\Support\Facades\Cache;

final class BOffWritesTest extends FixtureTestCase
{
    protected function dynoxideResetsTables(): bool
    {
        return false;
    }

    public function test_writes_to_it(): void
    {
        Cache::put('written', 'yes', 60);

        FixtureReport::record('BOffWrites', ['tables' => $this->tableNames(), 'value' => Cache::get('written')]);
        $this->assertSame('yes', Cache::get('written'));
    }
}
