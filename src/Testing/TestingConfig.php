<?php

declare(strict_types=1);

namespace Bambamboole\AcornTesting\Testing;

use RuntimeException;

/**
 * Minimal config loader for test-time use, before Acorn boots.
 *
 * Reads:
 *   1. The package's own `config/acorn-testing.php` for defaults.
 *   2. The consumer project's `config/acorn-testing.php` (if published).
 *   3. Two derived values: `dumpPath()` and `projectRoot()` (resolved by
 *      walking up from this file looking for `bedrock/application.php`).
 *
 * Once Acorn has booted you can still use the regular `config('acorn-testing.*')`
 * helper; this class exists for the narrow window before that's available.
 */
final class TestingConfig
{
    /** @var array<string, mixed>|null */
    private static ?array $config = null;

    private static ?string $projectRoot = null;

    /**
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::config()[$key] ?? $default;
    }

    public static function projectRoot(): string
    {
        if (self::$projectRoot !== null) {
            return self::$projectRoot;
        }

        $override = getenv('ACORN_TESTING_PROJECT_ROOT');
        if (is_string($override) && $override !== '' && file_exists($override . '/bedrock/application.php')) {
            return self::$projectRoot = $override;
        }

        $dir = __DIR__;
        while ($dir !== '/' && $dir !== '' && $dir !== '.') {
            if (file_exists($dir . '/bedrock/application.php')) {
                return self::$projectRoot = $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        throw new RuntimeException(
            'Could not locate the Bedrock project root (no bedrock/application.php found walking up from ' . __DIR__ . '). Set ACORN_TESTING_PROJECT_ROOT to override.',
        );
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
     * @return array<string, mixed>
     */
    private static function config(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $defaults = require dirname(__DIR__, 2) . '/config/acorn-testing.php';

        $projectConfig = self::projectRoot() . '/config/acorn-testing.php';
        $project = file_exists($projectConfig) ? require $projectConfig : [];

        if (! is_array($defaults)) {
            $defaults = [];
        }
        if (! is_array($project)) {
            $project = [];
        }

        return self::$config = array_merge($defaults, $project);
    }
}
