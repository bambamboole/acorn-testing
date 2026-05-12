<?php

declare(strict_types=1);

namespace Bambamboole\AcornTesting\Testing;

use Bambamboole\AcornTesting\Support\FrankenphpInstaller;
use Pest\Browser\Contracts\HttpServer;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * pest-plugin-browser HTTP server driver that runs FrankenPHP in a background
 * subprocess.
 *
 * Replaces wp-cli's `wp server` driver. `wp server` wraps PHP's single-threaded
 * built-in dev server, which can't reliably drive flows that fan out into
 * overlapping requests (the canonical case being WC Blocks Store API checkout).
 *
 * FrankenPHP (Caddy + libphp) handles concurrent requests natively, with the
 * added benefit of running as a single process — no `lsof`/`posix_kill` dance
 * required to clean up an orphaned grandchild.
 *
 * The binary lives at the configured `frankenphp_binary` path (defaults to
 * `<project root>/frankenphp`, gitignored). If it's missing when a browser
 * test runs — e.g. fresh clone, `git clean`, or CI cache miss — the driver
 * downloads it on the spot via Bambamboole\AcornTesting\Support\FrankenphpInstaller.
 * `wp acorn frankenphp:install [--force]` is the manual override.
 */
final class FrankenPhpDriver implements HttpServer
{
    private ?Process $process = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $projectRoot,
        private readonly string $webroot = 'public',
        private readonly string $binaryPath = '',
    ) {}

    public function start(): void
    {
        if ($this->isRunning()) {
            return;
        }

        $binary = $this->binaryPath !== '' ? $this->binaryPath : $this->projectRoot . '/frankenphp';

        if (! is_file($binary) || ! is_executable($binary)) {
            $this->installBinary($binary);
        }

        // Symfony Process merges this env on top of the parent process env
        // (Process::getDefaultEnv() reads via getenv(), so phpunit.xml's
        // <env force="true"> entries — DB_NAME, WP_HOME, WP_SITEURL — flow
        // through automatically). Only WP_ENV needs an explicit override
        // since it triggers the right Bedrock environment.
        $this->process = new Process(
            [$binary, 'php-server', '--listen', $this->host . ':' . $this->port, '--root', $this->webroot],
            $this->projectRoot,
            ['WP_ENV' => 'testing'],
        );
        $this->process->setTimeout(null);
        $this->process->disableOutput();
        $this->process->start();

        $deadline = microtime(true) + 30.0;
        while (microtime(true) < $deadline) {
            $sock = @fsockopen($this->host, $this->port, $errno, $errstr, 0.5);
            if ($sock !== false) {
                fclose($sock);

                return;
            }

            if (! $this->process->isRunning()) {
                throw new RuntimeException(sprintf(
                    'FrankenPHP exited before becoming ready (exit %d).',
                    (int) $this->process->getExitCode(),
                ));
            }

            usleep(200_000);
        }

        $this->stop();

        throw new RuntimeException(sprintf(
            'FrankenPHP did not start listening on %s:%d within 30s.',
            $this->host,
            $this->port,
        ));
    }

    public function stop(): void
    {
        if ($this->process !== null) {
            $this->process->stop(1);
            $this->process = null;
        }
    }

    public function bootstrap(): void
    {
        $this->start();
    }

    public function flush(): void
    {
        // Nothing to flush — each request hits the subprocess directly.
    }

    public function rewrite(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $base = sprintf('http://%s:%d', $this->host, $this->port);

        return $base . (str_starts_with($url, '/') ? $url : '/' . $url);
    }

    public function lastThrowable(): ?Throwable
    {
        return null;
    }

    public function throwLastThrowableIfNeeded(): void
    {
        // Errors surface through the FrankenPHP subprocess, not in-process.
    }

    private function isRunning(): bool
    {
        return $this->process !== null && $this->process->isRunning();
    }

    private function installBinary(string $binary): void
    {
        fwrite(STDERR, "Installing FrankenPHP (one-time setup, ~30s on a typical connection)...\n");

        $log = '';
        $installer = new FrankenphpInstaller(
            binaryPath: $binary,
            onOutput: static function (string $line) use (&$log): void {
                $log .= $line;
            },
        );

        if (! $installer->install()) {
            throw new RuntimeException("FrankenPHP install failed:\n" . $log);
        }
    }
}
