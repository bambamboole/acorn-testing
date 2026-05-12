<?php

declare(strict_types=1);

namespace Bambamboole\AcornTesting\Testing;

use Pest\Browser\Playwright\Playwright;
use Pest\Browser\ServerManager;
use ReflectionProperty;
use RuntimeException;

/**
 * Base class for browser tests.
 *
 * Extends FeatureTestCase so the parent setUpBeforeClass chain boots
 * WP+Acorn+Eloquent (idempotent, once per process) before the FrankenPHP
 * subprocess starts. Browser tests inherit the seed() helper from
 * FeatureTestCase. The first browser test class to run starts the FrankenPHP
 * subprocess and injects it into pest-plugin-browser's ServerManager via
 * reflection; a shutdown hook stops it at PHP exit.
 *
 * Configurable via `config/acorn-testing.php` in the consuming project:
 *   - `webroot` (default: 'public') — the Bedrock document root, passed to
 *     `frankenphp php-server --root`.
 *   - `frankenphp_binary` (default: '<project root>/frankenphp') — path to
 *     the binary. The driver auto-downloads it if missing.
 *   - `playwright_timeout_ms` (default: 90_000) — Playwright's per-action
 *     timeout, generous for cold-boot first-request latency.
 */
class BrowserTestCase extends FeatureTestCase
{
    private static ?FrankenPhpDriver $server = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$server !== null) {
            return;
        }

        $wpHome = (string) ($_ENV['WP_HOME'] ?? '');
        if ($wpHome === '') {
            throw new RuntimeException(
                'WP_HOME is not set. Browser tests require phpunit.xml to set it. If you just pulled a branch with new plugins/migrations, try `rm <dump_path>` and re-run the tests.',
            );
        }

        $parts = parse_url($wpHome);
        if (! isset($parts['host'], $parts['port'])) {
            throw new RuntimeException(sprintf(
                'WP_HOME (%s) must include host and port for browser tests (e.g. http://127.0.0.1:8080).',
                $wpHome,
            ));
        }

        $timeout = (int) TestingConfig::get('playwright_timeout_ms', 90_000);
        Playwright::setTimeout($timeout);

        $binaryPath = (string) TestingConfig::get('frankenphp_binary', '');
        $webroot = (string) TestingConfig::get('webroot', 'public');

        self::$server = new FrankenPhpDriver(
            host: $parts['host'],
            port: (int) $parts['port'],
            projectRoot: TestingConfig::projectRoot(),
            webroot: $webroot,
            binaryPath: $binaryPath,
        );
        self::$server->start();

        // ServerManager's http() lazily initializes via `??=`, so a value set
        // here wins over the default Laravel/Null drivers.
        $manager = ServerManager::instance();
        $http = new ReflectionProperty(ServerManager::class, 'http');
        $http->setValue($manager, self::$server);

        register_shutdown_function(static function (): void {
            self::$server?->stop();
            self::$server = null;
        });
    }
}
