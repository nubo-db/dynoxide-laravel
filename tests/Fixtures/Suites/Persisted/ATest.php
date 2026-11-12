<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests\Fixtures\Suites\Persisted;

use Dynoxide\Laravel\Testing\DynoxideProcess;
use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureReport;
use Dynoxide\Laravel\Tests\Fixtures\Suites\FixtureTestCase;

final class ATest extends FixtureTestCase
{
    public function test_the_first_test_sees_the_tables(): void
    {
        FixtureReport::record('first', [
            'cache' => count($this->itemsIn('cache')),
            'kept' => count($this->itemsIn('not_the_apps')),
            'created' => DynoxideProcess::tablesCreated(),
        ]);
    }
}
