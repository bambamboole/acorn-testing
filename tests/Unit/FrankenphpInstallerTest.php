<?php

declare(strict_types=1);

use Bambamboole\AcornTesting\Support\FrankenphpInstaller;
use Illuminate\Process\Factory;

beforeEach(function (): void {
    $this->binaryPath = sys_get_temp_dir() . '/frankenphp-test-' . bin2hex(random_bytes(6));
    $this->output = '';
    $this->captureOutput = function (string $line): void {
        $this->output .= $line;
    };
});

afterEach(function (): void {
    if (is_file($this->binaryPath)) {
        unlink($this->binaryPath);
    }
});

it('reports "already installed" when the binary at the pinned version is already on disk', function (): void {
    // Synthesize a fake binary at the expected version. The version-check
    // subprocess is faked to report the pinned version string.
    file_put_contents($this->binaryPath, '#!/bin/sh');
    chmod($this->binaryPath, 0o755);

    $factory = new Factory();
    $factory->fake([
        '*' => $factory->result(output: 'FrankenPHP ' . FrankenphpInstaller::VERSION . ' PHP 8.4.18'),
    ]);

    $installer = new FrankenphpInstaller(
        binaryPath: $this->binaryPath,
        onOutput: $this->captureOutput,
        process: $factory,
    );

    expect($installer->install())->toBeTrue();
    expect($this->output)->toContain('already installed');
});

it('attempts a download when the binary is missing', function (): void {
    // Don't create $binaryPath — installer must fall through to the download path.
    $factory = new Factory();

    // First call: curl download — succeeds and creates the file.
    // Second call: version check — must succeed for install() to return true.
    $factory->fake(function ($process) use ($factory) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        if (str_contains($command, 'curl')) {
            file_put_contents($this->binaryPath, '#!/bin/sh');

            return $factory->result(output: '');
        }

        return $factory->result(output: 'FrankenPHP ' . FrankenphpInstaller::VERSION . ' PHP 8.4.18');
    });

    $installer = new FrankenphpInstaller(
        binaryPath: $this->binaryPath,
        onOutput: $this->captureOutput,
        process: $factory,
    );

    expect($installer->install())->toBeTrue();
    expect($this->output)->toContain('Downloading frankenphp-');
});

it('forces a redownload when --force is set even if the binary already exists at the right version', function (): void {
    file_put_contents($this->binaryPath, '#!/bin/sh');
    chmod($this->binaryPath, 0o755);

    $factory = new Factory();
    $sawDownload = false;
    $factory->fake(function ($process) use ($factory, &$sawDownload) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        if (str_contains($command, 'curl')) {
            $sawDownload = true;

            return $factory->result(output: '');
        }

        return $factory->result(output: 'FrankenPHP ' . FrankenphpInstaller::VERSION . ' PHP 8.4.18');
    });

    $installer = new FrankenphpInstaller(
        binaryPath: $this->binaryPath,
        force: true,
        onOutput: $this->captureOutput,
        process: $factory,
    );

    expect($installer->install())->toBeTrue();
    expect($sawDownload)->toBeTrue();
});

it('returns false and emits an error when the download fails', function (): void {
    $factory = new Factory();
    $factory->fake([
        '*' => $factory->result(exitCode: 22, errorOutput: 'curl: (22) HTTP/1.1 404'),
    ]);

    $installer = new FrankenphpInstaller(
        binaryPath: $this->binaryPath,
        onOutput: $this->captureOutput,
        process: $factory,
    );

    expect($installer->install())->toBeFalse();
    expect($this->output)->toContain('Download failed');
});

it('returns false when the downloaded binary fails its version check', function (): void {
    $factory = new Factory();
    $factory->fake(function ($process) use ($factory) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        if (str_contains($command, 'curl')) {
            file_put_contents($this->binaryPath, '#!/bin/sh');

            return $factory->result(output: '');
        }

        // Version-check subprocess fails — installer should detect and report.
        return $factory->result(exitCode: 1, errorOutput: 'exec format error');
    });

    $installer = new FrankenphpInstaller(
        binaryPath: $this->binaryPath,
        onOutput: $this->captureOutput,
        process: $factory,
    );

    expect($installer->install())->toBeFalse();
    expect($this->output)->toContain('failed to report its version');
});
