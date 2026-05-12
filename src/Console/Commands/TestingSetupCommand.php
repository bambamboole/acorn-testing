<?php

declare(strict_types=1);

namespace Bambamboole\AcornTesting\Console\Commands;

use Bambamboole\AcornTesting\Support\FrankenphpInstaller;
use Illuminate\Console\Command;
use Illuminate\Process\Factory as Process;

class TestingSetupCommand extends Command
{
    protected $signature = 'testing:setup
        {--force : Force-redownload the FrankenPHP binary even if the pinned version is already installed}
        {--skip-npm : Skip the npm install + Playwright/Unlighthouse setup}';

    protected $description = 'Provision the test environment: FrankenPHP binary, .gitignore entries, Playwright, Unlighthouse.';

    private const NPM_DEV_DEPS = [
        // Pest browser tests use Playwright under the hood.
        'playwright',
        // Unlighthouse uses Puppeteer to drive headless Chrome for the audit.
        'puppeteer',
        // Unlighthouse itself — crawls + asserts per-category Lighthouse budgets.
        'unlighthouse-ci',
    ];

    private const GITIGNORE_LINES = [
        '/frankenphp',
        '.unlighthouse/',
    ];

    public function handle(): int
    {
        $root = base_path();

        $this->info('Setting up the acorn-testing environment...');
        $this->newLine();

        if (! $this->installFrankenphp($root)) {
            return self::FAILURE;
        }

        $this->ensureGitignore($root);

        if ($this->option('skip-npm')) {
            $this->line('  Skipping npm + Playwright + Unlighthouse setup (--skip-npm).');
        } else {
            if (! $this->installNpmDeps($root)) {
                return self::FAILURE;
            }

            if (! $this->installPlaywrightChromium($root)) {
                return self::FAILURE;
            }
        }

        $this->publishUnlighthouseConfig($root);

        $this->newLine();
        $this->info('Test environment ready. Run `composer test:browser` to verify.');

        return self::SUCCESS;
    }

    private function installFrankenphp(string $root): bool
    {
        $this->line('• FrankenPHP binary');

        $installer = new FrankenphpInstaller(
            binaryPath: (string) config('acorn-testing.frankenphp_binary', $root . '/frankenphp'),
            force: (bool) $this->option('force'),
            onOutput: fn (string $line) => $this->output->write($line),
        );

        if (! $installer->install()) {
            $this->error('  FrankenPHP install failed.');

            return false;
        }

        return true;
    }

    private function ensureGitignore(string $root): void
    {
        $this->line('• .gitignore entries');

        $path = $root . '/.gitignore';
        $existing = is_file($path) ? (string) file_get_contents($path) : '';
        $lines = $existing === '' ? [] : (preg_split('/\r?\n/', rtrim($existing, "\r\n")) ?: []);
        $missing = array_values(array_diff(self::GITIGNORE_LINES, $lines));

        if ($missing === []) {
            $this->line('  All entries already present.');

            return;
        }

        $append = "\n\n# acorn-testing\n" . implode("\n", $missing) . "\n";
        file_put_contents($path, ($existing === '' ? '' : rtrim($existing) . "\n") . $append);

        foreach ($missing as $line) {
            $this->line('  Added: ' . $line);
        }
    }

    private function installNpmDeps(string $root): bool
    {
        $this->line('• npm dev dependencies (' . implode(', ', self::NPM_DEV_DEPS) . ')');

        $package = $this->readPackageJson($root);
        $declared = array_keys(($package['devDependencies'] ?? []) + ($package['dependencies'] ?? []));
        $missing = array_values(array_diff(self::NPM_DEV_DEPS, $declared));

        if ($missing === []) {
            $this->line('  All packages already declared in package.json.');

            return true;
        }

        $this->line('  Installing: ' . implode(', ', $missing));

        $result = new Process()
            ->path($root)
            ->timeout(600)
            ->run(
                array_merge(['npm', 'install', '--save-dev'], $missing),
                fn (string $type, string $buffer) => $this->output->write($buffer),
            );

        if (! $result->successful()) {
            $this->error('  npm install failed.');

            return false;
        }

        return true;
    }

    private function installPlaywrightChromium(string $root): bool
    {
        $this->line('• Playwright Chromium');

        $result = new Process()
            ->path($root)
            ->timeout(600)
            ->run(
                ['npx', 'playwright', 'install', 'chromium'],
                fn (string $type, string $buffer) => $this->output->write($buffer),
            );

        if (! $result->successful()) {
            $this->error('  Playwright install failed.');

            return false;
        }

        return true;
    }

    private function publishUnlighthouseConfig(string $root): void
    {
        $this->line('• unlighthouse.config.js');

        $target = $root . '/unlighthouse.config.js';

        if (is_file($target)) {
            $this->line('  Already present (leaving untouched).');

            return;
        }

        copy(dirname(__DIR__, 3) . '/stubs/unlighthouse.config.js', $target);
        $this->line('  Published to ' . $target);
    }

    /** @return array<string, mixed> */
    private function readPackageJson(string $root): array
    {
        $path = $root . '/package.json';

        if (! is_file($path)) {
            return [];
        }

        $contents = (string) file_get_contents($path);
        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }
}
