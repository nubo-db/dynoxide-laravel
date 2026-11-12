<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Fixtures\Suites\Deleted;

use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureReport;
use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureTestCase;

final class COnDeletesTest extends FixtureTestCase
{
    protected function dynoxideResetsTables(): bool
    {
        return true;
    }

    public function test_deletes_the_apps_table(): void
    {
        $this->rawClient()->deleteTable(['TableName' => 'cache']);

        FixtureReport::record('COnDeletes', ['tables' => $this->tableNames()]);
        $this->assertNotContains('cache', $this->tableNames());
    }
}
