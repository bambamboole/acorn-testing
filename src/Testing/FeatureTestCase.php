<?php

declare(strict_types=1);

namespace Bambamboole\AcornTesting\Testing;

use Bambamboole\AcornTesting\Support\PackageManager;
use Bambamboole\AcornTesting\Testing\Concerns\MakesHttpRequests;
use Bambamboole\AcornTesting\Testing\Concerns\ResetsDatabase;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Process\Factory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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
    use MakesHttpRequests;
    use ResetsDatabase;

    /** The trait's request helpers resolve the HTTP kernel through this. */
    protected Container $app;

    private static bool $acornBooted = false;
    private static bool $testDatabaseInstalled = false;
    private static ?Factory $processFactory = null;

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
        $this->app = Container::getInstance();
    }

    protected function tearDown(): void
    {
        $this->resetDatabase();
        parent::tearDown();
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
        } elseif (self::dumpIsStale()) {
            throw new RuntimeException(sprintf(
                "The test database dump is older than the code that builds it.\n"
                . "  dump:    %s\n"
                . "  newer:   %s\n\n"
                . 'Rebuild it with `%s`, or set `watch_paths` to [] in config/acorn-testing.php '
                . 'to opt out of this check.',
                TestingConfig::dumpPath(),
                self::newestWatchedFile() ?? '(unknown)',
                TestingConfig::buildCommand() ?? 'wp acorn db:seed',
            ));
        }

        if (! file_exists(TestingConfig::projectRoot() . '/public/build/manifest.json')) {
            self::runShell(PackageManager::detect(TestingConfig::projectRoot())->runScript('build'));
        }

        // Guarantee the DB matches the dump before WordPress boots in-process.
        // buildTestDatabaseDump() leaves the DB populated, but if the dump file
        // already existed and the DB was dropped/wiped, wp-settings.php would
        // hit wp_not_installed() and silently die().
        self::runWpCli(['db', 'import', TestingConfig::dumpPath()]);

        self::$testDatabaseInstalled = true;
    }

    /**
     * The dump on disk is authoritative and is re-imported before the suite
     * runs, so without this an edited seeder silently tests the previous world.
     */
    private static function dumpIsStale(): bool
    {
        $newest = self::newestWatchedFile();

        return $newest !== null && filemtime($newest) > filemtime(TestingConfig::dumpPath());
    }

    private static function newestWatchedFile(): ?string
    {
        $newest = null;
        $newestTime = 0;

        foreach (TestingConfig::watchPaths() as $pattern) {
            $absolute = str_starts_with($pattern, '/')
                ? $pattern
                : TestingConfig::projectRoot() . '/' . $pattern;

            foreach (glob($absolute, GLOB_BRACE) ?: [] as $path) {
                if (! is_file($path)) {
                    continue;
                }

                $mtime = (int) filemtime($path);
                if ($mtime > $newestTime) {
                    $newestTime = $mtime;
                    $newest = $path;
                }
            }
        }

        return $newest;
    }

    private static function buildTestDatabaseDump(): void
    {
        $dumpPath = TestingConfig::dumpPath();
        @mkdir(dirname($dumpPath), recursive: true);

        $buildCommand = TestingConfig::buildCommand();
        if ($buildCommand !== null) {
            self::runShell(['sh', '-c', $buildCommand]);

            if (! file_exists($dumpPath)) {
                throw new RuntimeException(sprintf(
                    'build_command (`%s`) finished without writing a dump to %s.',
                    $buildCommand,
                    $dumpPath,
                ));
            }

            return;
        }

        // wp db drop fails if the DB doesn't exist; ignore its exit code.
        self::process()
            ->path(TestingConfig::projectRoot())
            ->env(self::testEnv())
            ->timeout(60)
            ->run(['wp', 'db', 'drop', '--yes']);

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
        self::process()
            ->path(TestingConfig::projectRoot())
            ->env(self::testEnv())
            ->timeout(300)
            ->run($cmd)
            ->throw();
    }

    private static function process(): Factory
    {
        return self::$processFactory ??= new Factory();
    }

    /**
     * @return array<string, string>
     */
    private static function testEnv(): array
    {
        return ['WP_ENV' => 'testing'] + array_filter($_ENV, 'is_string');
    }
}
