<?php

declare(strict_types=1);

use Bambamboole\AcornTesting\Testing\Lighthouse;
use Bambamboole\AcornTesting\Testing\TestingConfig;
use Illuminate\Process\Factory;

beforeEach(function (): void {
    TestingConfig::reset();

    $this->tmpRoot = sys_get_temp_dir() . '/acorn-testing-lighthouse-' . bin2hex(random_bytes(6));
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

it('builds the minimal command with just a site URL', function (): void {
    expect(Lighthouse::for('http://127.0.0.1:8080')->command())->toBe([
        'npx', 'unlighthouse-ci', '--site', 'http://127.0.0.1:8080',
    ]);
});

it('appends --budget when budget() is called', function (): void {
    $cmd = Lighthouse::for('http://127.0.0.1:8080')->budget(85)->command();

    expect($cmd)->toContain('--budget')
        ->and($cmd)->toContain('85');
});

it('joins excluded URLs into a single comma-separated arg', function (): void {
    $cmd = Lighthouse::for('http://127.0.0.1:8080')
        ->excludedUrls(['/wp-admin/', '/wp-login.php', '/cart/'])
        ->command();

    $i = array_search('--exclude-urls', $cmd, true);
    expect($i)->not->toBeFalse()
        ->and($cmd[$i + 1])->toBe('/wp-admin/,/wp-login.php,/cart/');
});

it('omits --exclude-urls when the list is empty', function (): void {
    expect(Lighthouse::for('http://127.0.0.1:8080')->excludedUrls([])->command())
        ->not->toContain('--exclude-urls');
});

it('appends --mobile when mobile() is called', function (): void {
    expect(Lighthouse::for('http://127.0.0.1:8080')->mobile()->command())
        ->toContain('--mobile');
});

it('appends --desktop when desktop() is called', function (): void {
    expect(Lighthouse::for('http://127.0.0.1:8080')->desktop()->command())
        ->toContain('--desktop');
});

it('omits viewport flags when neither mobile() nor desktop() is called', function (): void {
    $cmd = Lighthouse::for('http://127.0.0.1:8080')->command();

    expect($cmd)->not->toContain('--mobile')
        ->and($cmd)->not->toContain('--desktop');
});

it('appends --samples N', function (): void {
    $cmd = Lighthouse::for('http://127.0.0.1:8080')->samples(3)->command();

    expect($cmd)->toContain('--samples')
        ->and($cmd)->toContain('3');
});

it('appends --config-file when configPath() is called', function (): void {
    $cmd = Lighthouse::for('http://127.0.0.1:8080')
        ->configPath('./unlighthouse.config.js')
        ->command();

    $i = array_search('--config-file', $cmd, true);
    expect($i)->not->toBeFalse()
        ->and($cmd[$i + 1])->toBe('./unlighthouse.config.js');
});

it('composes all options in a single command in the expected order', function (): void {
    $cmd = Lighthouse::for('https://staging.example.com')
        ->budget(85)
        ->excludedUrls(['/admin/'])
        ->mobile()
        ->samples(3)
        ->configPath('./custom-lh.config.js')
        ->command();

    expect($cmd)->toBe([
        'npx', 'unlighthouse-ci',
        '--site', 'https://staging.example.com',
        '--budget', '85',
        '--exclude-urls', '/admin/',
        '--mobile',
        '--samples', '3',
        '--config-file', './custom-lh.config.js',
    ]);
});

it('run() shells out via the injected Process factory and returns the result', function (): void {
    $factory = new Factory();
    $factory->fake([
        '*' => $factory->result(output: 'audit passed', exitCode: 0),
    ]);

    $result = Lighthouse::for('http://127.0.0.1:8080')->budget(80)->quietly()->run($factory);

    expect($result->successful())->toBeTrue()
        ->and(trim($result->output()))->toBe('audit passed');
});

it('run() surfaces a non-zero exit so callers can use ->throw() or ->successful()', function (): void {
    $factory = new Factory();
    $factory->fake([
        '*' => $factory->result(output: '', errorOutput: '/x had invalid score 0.5', exitCode: 1),
    ]);

    $result = Lighthouse::for('http://127.0.0.1:8080')->quietly()->run($factory);

    expect($result->successful())->toBeFalse()
        ->and($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('invalid score');
});
