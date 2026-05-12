<?php

declare(strict_types=1);

use Bambamboole\AcornTesting\Testing\Lighthouse;
use Bambamboole\AcornTesting\Testing\LighthouseReport;
use Bambamboole\AcornTesting\Testing\TestingConfig;
use Bambamboole\AcornTesting\Testing\UrlAudit;
use Illuminate\Process\Factory;

beforeEach(function (): void {
    TestingConfig::reset();

    $this->tmpRoot = sys_get_temp_dir() . '/acorn-testing-lighthouse-' . bin2hex(random_bytes(6));
    mkdir($this->tmpRoot . '/bedrock', 0o755, true);
    touch($this->tmpRoot . '/bedrock/application.php');
    putenv('ACORN_TESTING_PROJECT_ROOT=' . $this->tmpRoot);

    $this->writeCiResult = function (array $entries): void {
        $dir = $this->tmpRoot . '/.unlighthouse';
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        file_put_contents($dir . '/ci-result.json', json_encode($entries));
    };
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

// ---------- command composition ----------

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

// ---------- run() returns a structured report ----------

it('run() returns a LighthouseReport wrapping the process result + parsed audits', function (): void {
    ($this->writeCiResult)([
        ['path' => '/', 'score' => 0.92, 'performance' => 0.95, 'accessibility' => 1.0, 'best-practices' => 0.92, 'seo' => 0.81],
        ['path' => '/blog/', 'score' => 0.88, 'performance' => 0.90, 'accessibility' => 0.96, 'best-practices' => 0.85, 'seo' => 0.81],
    ]);

    $factory = new Factory();
    $factory->fake(['*' => $factory->result(output: 'audit passed', exitCode: 0)]);

    $report = Lighthouse::for('http://127.0.0.1:8080')->quietly()->run($factory);

    expect($report)->toBeInstanceOf(LighthouseReport::class)
        ->and($report->successful())->toBeTrue()
        ->and($report->failed())->toBeFalse()
        ->and($report->exitCode())->toBe(0)
        ->and(trim($report->output()))->toBe('audit passed')
        ->and($report->audits)->toHaveCount(2)
        ->and($report->audits[0])->toBeInstanceOf(UrlAudit::class)
        ->and($report->audits[0]->path)->toBe('/')
        ->and($report->audits[0]->performance)->toBe(0.95)
        ->and($report->audits[0]->bestPractices)->toBe(0.92);
});

it('run() returns an empty audit list when ci-result.json is missing', function (): void {
    // No ci-result.json created.
    $factory = new Factory();
    $factory->fake(['*' => $factory->result(output: '', exitCode: 0)]);

    $report = Lighthouse::for('http://127.0.0.1:8080')->quietly()->run($factory);

    expect($report->audits)->toBe([])
        ->and($report->successful())->toBeTrue();
});

it('run() surfaces a non-zero exit and still parses audits if the file is present', function (): void {
    ($this->writeCiResult)([
        ['path' => '/', 'score' => 0.5, 'performance' => 0.5, 'accessibility' => 0.5, 'best-practices' => 0.5, 'seo' => 0.5],
    ]);

    $factory = new Factory();
    $factory->fake([
        '*' => $factory->result(output: '', errorOutput: '/ has invalid score 0.5', exitCode: 1),
    ]);

    $report = Lighthouse::for('http://127.0.0.1:8080')->quietly()->run($factory);

    expect($report->successful())->toBeFalse()
        ->and($report->failed())->toBeTrue()
        ->and($report->errorOutput())->toContain('invalid score')
        ->and($report->audits)->toHaveCount(1)
        ->and($report->audits[0]->path)->toBe('/');
});

it('report->audit() looks up a URL by exact path', function (): void {
    ($this->writeCiResult)([
        ['path' => '/', 'score' => 0.9, 'performance' => 0.9, 'accessibility' => 0.9, 'best-practices' => 0.9, 'seo' => 0.9],
        ['path' => '/blog/', 'score' => 0.8, 'performance' => 0.8, 'accessibility' => 0.8, 'best-practices' => 0.8, 'seo' => 0.8],
    ]);

    $factory = new Factory();
    $factory->fake(['*' => $factory->result(exitCode: 0)]);

    $report = Lighthouse::for('http://127.0.0.1:8080')->quietly()->run($factory);

    expect($report->audit('/'))->not->toBeNull()
        ->and($report->audit('/blog/')->performance)->toBe(0.8)
        ->and($report->audit('/nonexistent/'))->toBeNull();
});

it('report->below() filters audits by per-category floor', function (): void {
    ($this->writeCiResult)([
        ['path' => '/', 'score' => 0.86, 'performance' => 0.97, 'accessibility' => 0.96, 'best-practices' => 0.92, 'seo' => 0.58],
        ['path' => '/blog/', 'score' => 0.88, 'performance' => 0.97, 'accessibility' => 0.96, 'best-practices' => 0.96, 'seo' => 0.65],
        ['path' => '/about/', 'score' => 0.95, 'performance' => 0.98, 'accessibility' => 1.0, 'best-practices' => 0.96, 'seo' => 0.95],
    ]);

    $factory = new Factory();
    $factory->fake(['*' => $factory->result(exitCode: 0)]);

    $report = Lighthouse::for('http://127.0.0.1:8080')->quietly()->run($factory);

    $belowSeo = $report->below('seo', 0.9);
    expect($belowSeo)->toHaveCount(2)
        ->and(array_map(fn (UrlAudit $a) => $a->path, $belowSeo))->toBe(['/', '/blog/']);

    expect($report->below('performance', 0.9))->toBe([]);
});

it('report->below() throws on an unknown category', function (): void {
    $factory = new Factory();
    $factory->fake(['*' => $factory->result(exitCode: 0)]);

    $report = Lighthouse::for('http://127.0.0.1:8080')->quietly()->run($factory);

    expect(fn () => $report->below('typo', 0.9))->toThrow(InvalidArgumentException::class);
});

it('report->throw() returns the report on success and throws on failure', function (): void {
    $factory = new Factory();
    $factory->fake([
        '*' => $factory->result(exitCode: 0),
    ]);

    $report = Lighthouse::for('http://127.0.0.1:8080')->quietly()->run($factory);

    expect($report->throw())->toBe($report);

    $factory = new Factory();
    $factory->fake([
        '*' => $factory->result(output: '', errorOutput: '/ failed', exitCode: 1),
    ]);

    $failing = Lighthouse::for('http://127.0.0.1:8080')->quietly()->run($factory);

    expect(fn () => $failing->throw())->toThrow(RuntimeException::class, 'Lighthouse audit failed');
});
