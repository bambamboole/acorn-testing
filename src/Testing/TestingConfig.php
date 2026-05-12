<?php

declare(strict_types=1);

namespace Bambamboole\AcornTesting\Testing;

use Composer\InstalledVersions;
use Illuminate\Config\Repository;
use RuntimeException;

/**
 * Config loader for the narrow phase before Acorn has booted.
 *
 * `Bambamboole\AcornTesting\Testing\FeatureTestCase::setUpBeforeClass` has
 * to know things like the seeder list and admin email before it can call
 * `wp acorn db:seed`, which has to happen before the dump is built, which
 * has to happen before WordPress (and therefore Acorn) can boot. This
 * runs entirely without the Laravel container.
 *
 * Reads:
 *   1. Package defaults from `<package>/config/acorn-testing.php`
 *   2. Consumer overrides from `<project>/config/acorn-testing.php`
 *   (the consumer's file wins, per Laravel's `mergeConfigFrom` semantics)
 *
 * Once Acorn has booted you can use the regular `config('acorn-testing.*')`
 * helper too — both read the same files; the values are identical.
 */
final class TestingConfig
{
    private static ?Repository $config = null;

    private static ?string $projectRoot = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::config()->get($key, $default);
    }

    public static function projectRoot(): string
    {
        if (self::$projectRoot !== null) {
            return self::$projectRoot;
        }

        $override = getenv('ACORN_TESTING_PROJECT_ROOT');
        if (is_string($override) && $override !== '') {
            return self::$projectRoot = rtrim($override, '/');
        }

        $rootPath = InstalledVersions::getRootPackage()['install_path'] ?? null;
        if (! is_string($rootPath) || $rootPath === '') {
            throw new RuntimeException(
                'Could not determine the project root via Composer\\InstalledVersions. '
                . 'Set ACORN_TESTING_PROJECT_ROOT to override.',
            );
        }

        return self::$projectRoot = rtrim((string) realpath($rootPath) ?: $rootPath, '/');
    }

    public static function dumpPath(): string
    {
        $configured = self::get('dump_path');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return self::projectRoot() . '/database/dumps/testing.sql';
    }

    /**
     * Reset cached state. Test-use only.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$config = null;
        self::$projectRoot = null;
    }

    private static function config(): Repository
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $defaults = require dirname(__DIR__, 2) . '/config/acorn-testing.php';

        $projectConfigFile = self::projectRoot() . '/config/acorn-testing.php';
        $project = file_exists($projectConfigFile) ? require $projectConfigFile : [];

        return self::$config = new Repository(array_replace_recursive(
            is_array($defaults) ? $defaults : [],
            is_array($project) ? $project : [],
        ));
    }
}
