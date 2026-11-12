<?php

declare(strict_types=1);

namespace Dynoxide\Laravel;

use Dynoxide\Laravel\Console\ServeCommand;
use Illuminate\Support\ServiceProvider;

final class DynoxideServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/dynoxide.php', 'dynoxide');
    }

    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/dynoxide.php' => $this->app->configPath('dynoxide.php'),
        ], 'dynoxide-config');

        $this->commands([ServeCommand::class]);
    }
}
