<?php

declare(strict_types=1);

namespace Bambamboole\AcornTesting\Console\Commands;

use Bambamboole\AcornTesting\Support\FrankenphpInstaller;
use Illuminate\Console\Command;

class FrankenphpInstallCommand extends Command
{
    protected $signature = 'frankenphp:install {--force : Re-download even if the pinned version is already installed}';

    protected $description = 'Download the FrankenPHP binary used by browser tests into the project root.';

    public function handle(): int
    {
        $installer = new FrankenphpInstaller(
            binaryPath: (string) config('acorn-testing.frankenphp_binary', base_path('frankenphp')),
            force: (bool) $this->option('force'),
            onOutput: fn (string $line) => $this->output->write($line),
        );

        return $installer->install() ? self::SUCCESS : self::FAILURE;
    }
}
