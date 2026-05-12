<?php

declare(strict_types=1);

namespace Bambamboole\AcornTesting;

use Bambamboole\AcornTesting\Console\Commands\FrankenphpInstallCommand;
use Illuminate\Support\ServiceProvider;

class AcornTestingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/acorn-testing.php', 'acorn-testing');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([FrankenphpInstallCommand::class]);

        $this->publishes(
            [__DIR__ . '/../config/acorn-testing.php' => $this->app->configPath('acorn-testing.php')],
            'acorn-testing-config',
        );
    }
}
