<?php

declare(strict_types=1);

namespace Bambamboole\AcornTesting\Testing;

use Bambamboole\AcornTesting\Support\FrankenphpInstaller;
use Illuminate\Process\Factory;
use Illuminate\Process\InvokedProcess;
use Pest\Browser\Contracts\HttpServer;
use RuntimeException;
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
 * `wp acorn testing:setup [--force]` is the manual override that also
 * provisions Playwright + Unlighthouse alongside the binary.
 */
final class FrankenPhpDriver implements HttpServer, LocalServer
{
    private static ?self $active = null;

    private ?InvokedProcess $invoked = null;

    private readonly Factory $process;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $projectRoot,
        private readonly string $webroot = 'public',
        private readonly string $binaryPath = '',
        ?Factory $process = null,
    ) {
        $this->process = $process ?? new Factory();
    }

    /**
     * The most recently started driver instance, set in `start()` and cleared
     * in `stop()`. Lighthouse::local() reads this so it doesn't have to go
     * through pest-plugin-browser's @internal ServerManager.
     */
    public static function active(): ?self
    {
        return self::$active;
    }

    public function url(): string
    {
        return sprintf('http://%s:%d', $this->host, $this->port);
    }

    public function start(): void
    {
        if ($this->isRunning()) {
            self::$active = $this;

            return;
        }

        $binary = $this->binaryPath !== '' ? $this->binaryPath : $this->projectRoot . '/frankenphp';

        if (! is_file($binary) || ! is_executable($binary)) {
            $this->installBinary($binary);
        }

        // path() sets cwd; env() adds WP_ENV on top of the parent process env;
        // forever() removes the timeout; quietly() disables in-memory output
        // capture (server runs indefinitely, no point accumulating its log).
        $this->invoked = $this->process
            ->path($this->projectRoot)
            ->env(['WP_ENV' => 'testing'])
            ->forever()
            ->quietly()
            ->start([
                $binary,
                'php-server',
                '--listen',
                $this->host . ':' . $this->port,
                '--root',
                $this->webroot,
            ]);

        $deadline = microtime(true) + 30.0;
        while (microtime(true) < $deadline) {
            $sock = @fsockopen($this->host, $this->port, $errno, $errstr, 0.5);
            if ($sock !== false) {
                fclose($sock);
                self::$active = $this;

                return;
            }

            if (! $this->invoked->running()) {
                throw new RuntimeException(sprintf(
                    'FrankenPHP exited before becoming ready (exit %d).',
                    (int) ($this->invoked->predictedExitCode() ?? -1),
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
        if ($this->invoked === null) {
            return;
        }

        $this->invoked->signal(SIGTERM);

        // Give FrankenPHP up to 1s to shut down cleanly, then SIGKILL.
        // Without the wait, the next test invocation can race the OS releasing
        // the port (TIME_WAIT) and fail to bind.
        $deadline = microtime(true) + 1.0;
        while (microtime(true) < $deadline && $this->invoked->running()) {
            usleep(50_000);
        }

        if ($this->invoked->running()) {
            $this->invoked->signal(SIGKILL);
        }

        $this->invoked = null;

        if (self::$active === $this) {
            self::$active = null;
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
        return $this->invoked !== null && $this->invoked->running();
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
            process: $this->process,
        );

        if (! $installer->install()) {
            throw new RuntimeException("FrankenPHP install failed:\n" . $log);
        }
    }
}
