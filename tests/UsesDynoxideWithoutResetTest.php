<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests;

use Dynoxide\Laravel\Testing\UsesDynoxide;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Depends;

final class UsesDynoxideWithoutResetTest extends TestCase
{
    use UsesDynoxide;

    protected function dynoxideResetsTables(): bool
    {
        return false;
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'dynamodb');
    }

    public function test_a_value_is_put_in_one_test(): void
    {
        Cache::put('kept', 'value', 60);

        $this->assertSame('value', Cache::get('kept'));
    }

    #[Depends('test_a_value_is_put_in_one_test')]
    public function test_and_is_still_there_in_the_next(): void
    {
        $this->assertSame('value', Cache::get('kept'));
    }
}
