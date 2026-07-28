<?php

declare(strict_types=1);

use Bambamboole\AcornTesting\Testing\TestingConfig;

beforeEach(function (): void {
    TestingConfig::reset();

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

it('ships isolation defaults that keep WordPress installed between tests', function (): void {
    expect(TestingConfig::protectedTables())->toContain('options', 'migrations', 'terms');
    expect(TestingConfig::baselineOption())->toBe('acorn_testing_baseline_ids');
});

it('scopes the tables that mix baseline rows with test-created ones', function (): void {
    $scoped = TestingConfig::scopedTables();

    expect($scoped)->toHaveKeys(['posts', 'postmeta', 'users', 'usermeta', 'term_relationships']);
    expect($scoped['posts'])->toBe('ID');
    expect($scoped['usermeta'])->toBe('user_id');

    // The one people miss: it carries each post's language, translation group
    // and product type, so truncating it guts the baseline.
    expect($scoped['term_relationships'])->toBe('object_id');
});

it('does not protect users, because tests create them and they must not accumulate', function (): void {
    expect(TestingConfig::protectedTables())->not->toContain('users');
    expect(TestingConfig::protectedTables())->not->toContain('usermeta');
});

it('has no build command by default', function (): void {
    expect(TestingConfig::buildCommand())->toBeNull();
});

it('reads a build command from the consumer config', function (): void {
    file_put_contents(
        $this->tmpRoot . '/config/acorn-testing.php',
        '<?php return ["build_command" => "php scripts/app.php env:seed --test"];',
    );

    expect(TestingConfig::buildCommand())->toBe('php scripts/app.php env:seed --test');
});

it('treats an empty build command as unset', function (): void {
    file_put_contents(
        $this->tmpRoot . '/config/acorn-testing.php',
        '<?php return ["build_command" => ""];',
    );

    expect(TestingConfig::buildCommand())->toBeNull();
});

it('watches the seeders directory for staleness by default', function (): void {
    expect(TestingConfig::watchPaths())->toContain('database/seeders/*.php');
});

it('allows opting out of the staleness check', function (): void {
    file_put_contents(
        $this->tmpRoot . '/config/acorn-testing.php',
        '<?php return ["watch_paths" => []];',
    );

    expect(TestingConfig::watchPaths())->toBe([]);
});

it('lets a consumer replace the isolation tables entirely', function (): void {
    file_put_contents(
        $this->tmpRoot . '/config/acorn-testing.php',
        '<?php return ["protected_tables" => ["options"], "scoped_tables" => ["posts" => "ID"]];',
    );

    expect(TestingConfig::protectedTables())->toBe(['options']);
    expect(TestingConfig::scopedTables())->toBe(['posts' => 'ID']);
});
