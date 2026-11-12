<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Fixtures\Suites\ResetOff;

use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureReport;
use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureTestCase;
use Illuminate\Support\Facades\Cache;

final class AKeepsItemsTest extends FixtureTestCase
{
    protected function dynoxideResetsTables(): bool
    {
        return false;
    }

    public function test_leaves_an_item_behind(): void
    {
        Cache::put('left', 'behind', 60);

        FixtureReport::record('kept', ['cache' => count($this->itemsIn('cache'))]);
    }
}
