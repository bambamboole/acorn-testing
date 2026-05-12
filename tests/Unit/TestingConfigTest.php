<?php

declare(strict_types=1);

use Bambamboole\AcornTesting\Testing\TestingConfig;

beforeEach(function (): void {
    TestingConfig::reset();

    // Each test runs against a synthetic project root in a tmp dir. The
    // ACORN_TESTING_PROJECT_ROOT override is what allows the package to be
    // tested without a real Bedrock project present.
    $this->tmpRoot = sys_get_temp_dir() . '/acorn-testing-' . bin2hex(random_bytes(6));
    mkdir($this->tmpRoot . '/config', 0o755, true);
    mkdir($this->tmpRoot . '/bedrock', 0o755, true);
    touch($this->tmpRoot . '/bedrock/application.php');

    putenv('ACORN_TESTING_PROJECT_ROOT=' . $this->tmpRoot);
});

afterEach(function (): void {
    TestingConfig::reset();
    putenv('ACORN_TESTING_PROJECT_ROOT');

    if (is_dir($this->tmpRoot)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tmpRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($this->tmpRoot);
    }
});

it('returns the project root from the ACORN_TESTING_PROJECT_ROOT override', function (): void {
    expect(TestingConfig::projectRoot())->toBe($this->tmpRoot);
});

it('returns package defaults when the consumer ships no config file', function (): void {
    expect(TestingConfig::get('wp_title'))->toBe('Test Site');
    expect(TestingConfig::get('admin_email'))->toBe('admin@test.test');
    expect(TestingConfig::get('plugins'))->toBe('all');
    expect(TestingConfig::get('webroot'))->toBe('public');
    expect(TestingConfig::get('playwright_timeout_ms'))->toBe(90_000);
});

it('returns an empty seeders array by default', function (): void {
    expect(TestingConfig::get('seeders'))->toBe([]);
});

it('merges the consumer config file over package defaults', function (): void {
    file_put_contents(
        $this->tmpRoot . '/config/acorn-testing.php',
        '<?php return ["wp_title" => "Custom", "seeders" => ["App\\\\Seeders\\\\Foo"]];',
    );

    expect(TestingConfig::get('wp_title'))->toBe('Custom');
    expect(TestingConfig::get('seeders'))->toBe(['App\\Seeders\\Foo']);
});

it('leaves untouched defaults intact when the consumer config only overrides some keys', function (): void {
    file_put_contents(
        $this->tmpRoot . '/config/acorn-testing.php',
        '<?php return ["wp_title" => "Custom"];',
    );

    expect(TestingConfig::get('wp_title'))->toBe('Custom');
    expect(TestingConfig::get('admin_email'))->toBe('admin@test.test');
});

it('returns the supplied fallback for unknown keys', function (): void {
    expect(TestingConfig::get('nonexistent_key', 'fallback'))->toBe('fallback');
});

it('computes dumpPath() from the project root when not configured', function (): void {
    expect(TestingConfig::dumpPath())->toBe($this->tmpRoot . '/database/dumps/testing.sql');
});

it('honors a configured dump_path verbatim', function (): void {
    file_put_contents(
        $this->tmpRoot . '/config/acorn-testing.php',
        '<?php return ["dump_path" => "/explicit/path/test.sql"];',
    );

    expect(TestingConfig::dumpPath())->toBe('/explicit/path/test.sql');
});

it('treats a null dump_path config value as "use the default"', function (): void {
    file_put_contents(
        $this->tmpRoot . '/config/acorn-testing.php',
        '<?php return ["dump_path" => null];',
    );

    expect(TestingConfig::dumpPath())->toBe($this->tmpRoot . '/database/dumps/testing.sql');
});

it('reset() clears cached values so a subsequent call re-reads the project config', function (): void {
    expect(TestingConfig::get('wp_title'))->toBe('Test Site');

    file_put_contents(
        $this->tmpRoot . '/config/acorn-testing.php',
        '<?php return ["wp_title" => "After Reset"];',
    );

    // Without reset(), the cache wins.
    expect(TestingConfig::get('wp_title'))->toBe('Test Site');

    TestingConfig::reset();
    expect(TestingConfig::get('wp_title'))->toBe('After Reset');
});
