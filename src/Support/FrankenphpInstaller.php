<?php

declare(strict_types=1);

namespace Bambamboole\AcornTesting\Support;

use Illuminate\Process\Factory;
use RuntimeException;

/**
 * Downloads the pinned FrankenPHP binary used by browser tests.
 *
 * Uses Illuminate\Process\Factory directly (no facade), so it works in
 * contexts where Laravel/Acorn isn't booted yet — notably the
 * pre-Acorn-boot phase of FeatureTestCase::ensureTestDatabaseInstalled,
 * and the FrankenPhpDriver's auto-install path on the first browser
 * test of a fresh checkout.
 *
 * v1.11.2 bundles PHP 8.4.18 — the last 1.x release before v1.11.3
 * switched to PHP 8.5. Bump VERSION when a newer 8.4-line release ships.
 */
final class FrankenphpInstaller
{
    public const string VERSION = 'v1.11.2';

    private readonly Factory $process;

    public function __construct(
        private readonly string $binaryPath,
        private readonly bool $force = false,
        /** @var (callable(string): void)|null */
        private $onOutput = null,
        ?Factory $process = null,
    ) {
        $this->process = $process ?? new Factory();
    }

    public function install(): bool
    {
        if (! $this->force && $this->isCorrectVersionInstalled()) {
            $this->emit(
                sprintf('FrankenPHP %s is already installed at %s.', self::VERSION, $this->binaryPath) . PHP_EOL,
            );

            return true;
        }

        try {
            $asset = $this->assetName();
        } catch (RuntimeException $e) {
            $this->emit($e->getMessage() . PHP_EOL);

            return false;
        }

        $url = sprintf('https://github.com/php/frankenphp/releases/download/%s/%s', self::VERSION, $asset);

        $this->emit(sprintf('Downloading %s → %s', $asset, $this->binaryPath) . PHP_EOL);

        $result = $this->process
            ->timeout(300)
            ->run(
                ['curl', '-fSL', '--progress-bar', $url, '-o', $this->binaryPath],
                function (string $type, string $buffer): void {
                    $this->emit($buffer);
                },
            );

        if (! $result->successful()) {
            $this->emit('Download failed: ' . trim($result->errorOutput()) . PHP_EOL);
            @unlink($this->binaryPath);

            return false;
        }

        if (! chmod($this->binaryPath, 0o755)) {
            $this->emit(sprintf('Could not set executable permission on %s.', $this->binaryPath) . PHP_EOL);

            return false;
        }

        $version = $this->binaryVersionLine();

        if ($version === null) {
            $this->emit('Downloaded binary failed to report its version. The file may be corrupted.' . PHP_EOL);

            return false;
        }

        $this->emit(trim($version) . PHP_EOL);

        return true;
    }

    private function emit(string $line): void
    {
        if ($this->onOutput !== null) {
            ($this->onOutput)($line);
        }
    }

    private function isCorrectVersionInstalled(): bool
    {
        $version = $this->binaryVersionLine();

        return $version !== null && str_contains($version, self::VERSION);
    }

    private function binaryVersionLine(): ?string
    {
        if (! is_file($this->binaryPath) || ! is_executable($this->binaryPath)) {
            return null;
        }

        $result = $this->process->run([$this->binaryPath, 'version']);

        if (! $result->successful()) {
            return null;
        }

        return $result->output();
    }

    /**
     * Resolve the release asset name for the current OS + architecture.
     *
     * Matches the naming convention used by FrankenPHP releases on GitHub:
     *   frankenphp-mac-{arm64,x86_64}
     *   frankenphp-linux-{aarch64,x86_64}[-gnu]
     */
    private function assetName(): string
    {
        $arch = strtolower(php_uname('m'));

        return match (PHP_OS_FAMILY) {
            'Darwin' => match ($arch) {
                'arm64', 'aarch64' => 'frankenphp-mac-arm64',
                'x86_64', 'amd64' => 'frankenphp-mac-x86_64',
                default => throw new RuntimeException(sprintf('Unsupported macOS architecture: %s', $arch)),
            },
            'Linux' => sprintf(
                'frankenphp-linux-%s%s',
                match ($arch) {
                    'aarch64', 'arm64' => 'aarch64',
                    'x86_64', 'amd64' => 'x86_64',
                    default => throw new RuntimeException(sprintf('Unsupported Linux architecture: %s', $arch)),
                },
                $this->detectGnuSuffix(),
            ),
            default => throw new RuntimeException(sprintf(
                'Unsupported OS family: %s. FrankenPHP install supports macOS and Linux only — Windows users should run tests via WSL.',
                PHP_OS_FAMILY,
            )),
        };
    }

    /**
     * Linux releases ship two variants: musl (default) and glibc (`-gnu`).
     * `getconf GNU_LIBC_VERSION` succeeds on glibc systems and fails on musl.
     */
    private function detectGnuSuffix(): string
    {
        return $this->process->run(['getconf', 'GNU_LIBC_VERSION'])->successful() ? '-gnu' : '';
    }
}
