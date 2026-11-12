<?php

declare(strict_types=1);

namespace Dynoxide\Laravel\Tests;

use Illuminate\Support\Facades\Artisan;

final class ServiceProviderTest extends TestCase
{
    public function test_the_default_port_is_8800(): void
    {
        $this->assertSame(8800, config('dynoxide.port'));
        $this->assertSame('127.0.0.1', config('dynoxide.host'));
        $this->assertNull(config('dynoxide.db_path'));
        $this->assertSame(10, config('dynoxide.start_timeout'));
    }

    public function test_dynoxide_port_changes_the_port(): void
    {
        $this->withEnvironmentVariable('DYNOXIDE_PORT', '9001', function (): void {
            $this->refreshApplication();

            $this->assertSame('9001', config('dynoxide.port'), 'the command checks it, so a typo is an error rather than a different port');
        });
    }

    public function test_the_serve_command_is_registered(): void
    {
        $this->assertArrayHasKey('dynoxide:serve', Artisan::all());
    }

    public function test_the_config_file_can_be_published(): void
    {
        $published = $this->app->configPath('dynoxide.php');
        $this->assertFileDoesNotExist($published);

        try {
            $this->artisan('vendor:publish', ['--tag' => 'dynoxide-config'])->assertSuccessful();

            $this->assertFileEquals(__DIR__ . '/../config/dynoxide.php', $published);
        } finally {
            // The config directory is Testbench's own skeleton, shared by every test.
            @unlink($published);
        }
    }

    private function withEnvironmentVariable(string $name, string $value, callable $callback): void
    {
        putenv("{$name}={$value}");
        $_ENV[$name] = $_SERVER[$name] = $value;

        try {
            $callback();
        } finally {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }
}
