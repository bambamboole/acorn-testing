<?php

declare(strict_types=1);

namespace Bambamboole\AcornTesting\Testing;

use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Base test case for Feature tests in WordPress + Bedrock + Acorn projects.
 *
 * Bootstraps WordPress + Acorn + Eloquent on demand (once per process) and
 * exposes a `seed()` helper for running additional seeders on top of the
 * baseline dump.
 *
 * Configuration lives in `config/acorn-testing.php` in the consuming project
 * (publishable from this package via `wp acorn vendor:publish`). Values are
 * read by TestingConfig, which loads the file directly so it works before
 * Acorn boots.
 */
class FeatureTestCase extends TestCase
{
    private static bool $acornBooted = false;
    private static bool $testDatabaseInstalled = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::ensureTestDatabaseInstalled();
        self::ensureAcornBooted();
    }

    /**
     * Run a database seeder against the test database.
     *
     * @param  class-string  $seederClass
     */
    protected function seed(string $seederClass): void
    {
        new $seederClass()->run();
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::runWpCli(['db', 'import', TestingConfig::dumpPath()]);
        wp_cache_flush();
    }

    private static function ensureAcornBooted(): void
    {
        if (self::$acornBooted) {
            return;
        }

        putenv('WP_ENV=testing');
        $_ENV['WP_ENV'] = 'testing';

        $bedrockApplication = TestingConfig::projectRoot() . '/bedrock/application.php';

        if (! file_exists($bedrockApplication)) {
            throw new RuntimeException(sprintf(
                'Could not find Bedrock entry point at %s. acorn-testing expects a Bedrock-shaped project.',
                $bedrockApplication,
            ));
        }

        require_once $bedrockApplication;
        require_once ABSPATH . 'wp-includes/plugin.php';

        add_action(
            'after_setup_theme',
            static function (): void {
                app(Kernel::class)->bootstrap();
            },
            1,
        );

        require_once ABSPATH . 'wp-settings.php';

        self::$acornBooted = true;
    }

    private static function ensureTestDatabaseInstalled(): void
    {
        if (self::$testDatabaseInstalled) {
            return;
        }

        if (! file_exists(TestingConfig::dumpPath())) {
            self::buildTestDatabaseDump();
        }

        if (! file_exists(TestingConfig::projectRoot() . '/public/build/manifest.json')) {
            self::runShell(['npm', 'run', 'build']);
        }

        // Guarantee the DB matches the dump before WordPress boots in-process.
        // buildTestDatabaseDump() leaves the DB populated, but if the dump file
        // already existed and the DB was dropped/wiped, wp-settings.php would
        // hit wp_not_installed() and silently die().
        self::runWpCli(['db', 'import', TestingConfig::dumpPath()]);

        self::$testDatabaseInstalled = true;
    }

    private static function buildTestDatabaseDump(): void
    {
        $dumpPath = TestingConfig::dumpPath();
        @mkdir(dirname($dumpPath), recursive: true);

        // wp db drop fails if the DB doesn't exist; ignore its exit code.
        $drop = new Process(
            ['wp', 'db', 'drop', '--yes'],
            cwd: TestingConfig::projectRoot(),
            env: self::testEnv(),
            timeout: 60,
        );
        $drop->run();

        self::runWpCli(['db', 'create']);
        self::runWpCli([
            'core',
            'install',
            '--url=' . ($_ENV['WP_HOME'] ?? 'http://127.0.0.1:8080'),
            '--title=' . TestingConfig::get('wp_title', 'Test Site'),
            '--admin_user=admin',
            '--admin_password=admin',
            '--admin_email=' . TestingConfig::get('admin_email', 'admin@test.test'),
            '--skip-email',
        ]);

        $plugins = TestingConfig::get('plugins', 'all');
        if ($plugins === 'all') {
            self::runWpCli(['plugin', 'activate', '--all']);
        } elseif (is_array($plugins) && $plugins !== []) {
            self::runWpCli(array_merge(['plugin', 'activate'], $plugins));
        }

        self::runWpCli(['acorn', 'migrate', '--force']);

        foreach (TestingConfig::get('seeders', []) as $seederClass) {
            self::runWpCli(['acorn', 'db:seed', '--class=' . $seederClass]);
        }

        // Capture CPT rewrite rules into the dump. `wp acorn db:seed` doesn't
        // fire `init` the same way, so flush via `wp rewrite` to ensure
        // custom permalinks resolve.
        self::runWpCli(['rewrite', 'flush', '--hard']);
        self::runWpCli(['db', 'export', $dumpPath]);
    }

    /**
     * @param  list<string>  $args  wp-cli arguments after the `wp` binary.
     */
    private static function runWpCli(array $args): void
    {
        self::runShell(array_merge(['wp'], $args));
    }

    /**
     * @param  list<string>  $cmd
     */
    private static function runShell(array $cmd): void
    {
        $process = new Process($cmd, cwd: TestingConfig::projectRoot(), env: self::testEnv(), timeout: 300);
        $process->mustRun();
    }

    /**
     * @return array<string, string>
     */
    private static function testEnv(): array
    {
        return ['WP_ENV' => 'testing'] + array_filter($_ENV, 'is_string');
    }
}
