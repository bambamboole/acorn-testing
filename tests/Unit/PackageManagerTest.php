<?php

declare(strict_types=1);

use Bambamboole\AcornTesting\Support\PackageManager;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir() . '/acorn-testing-pm-' . uniqid();
    mkdir($this->root, recursive: true);
});

afterEach(function (): void {
    if (is_dir($this->root)) {
        $files = glob($this->root . '/*') ?: [];
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($this->root);
    }
});

it('detects pnpm when pnpm-lock.yaml is present', function (): void {
    file_put_contents($this->root . '/pnpm-lock.yaml', '');

    expect(PackageManager::detect($this->root)->name())->toBe('pnpm');
});

it('detects yarn when yarn.lock is present', function (): void {
    file_put_contents($this->root . '/yarn.lock', '');

    expect(PackageManager::detect($this->root)->name())->toBe('yarn');
});

it('falls back to npm when no lockfile is present', function (): void {
    expect(PackageManager::detect($this->root)->name())->toBe('npm');
});

it('falls back to npm when only package-lock.json is present', function (): void {
    file_put_contents($this->root . '/package-lock.json', '{}');

    expect(PackageManager::detect($this->root)->name())->toBe('npm');
});

it('prefers pnpm over yarn when both lockfiles exist', function (): void {
    file_put_contents($this->root . '/pnpm-lock.yaml', '');
    file_put_contents($this->root . '/yarn.lock', '');

    expect(PackageManager::detect($this->root)->name())->toBe('pnpm');
});

it('emits npm install --save-dev argv for npm', function (): void {
    $pm = PackageManager::detect($this->root);

    expect($pm->addDev(['playwright', 'puppeteer']))
        ->toBe(['npm', 'install', '--save-dev', 'playwright', 'puppeteer']);
});

it('emits yarn add --dev argv for yarn', function (): void {
    file_put_contents($this->root . '/yarn.lock', '');

    $pm = PackageManager::detect($this->root);

    expect($pm->addDev(['playwright', 'puppeteer']))
        ->toBe(['yarn', 'add', '--dev', 'playwright', 'puppeteer']);
});

it('emits pnpm add --save-dev argv for pnpm', function (): void {
    file_put_contents($this->root . '/pnpm-lock.yaml', '');

    $pm = PackageManager::detect($this->root);

    expect($pm->addDev(['playwright', 'puppeteer']))
        ->toBe(['pnpm', 'add', '--save-dev', 'playwright', 'puppeteer']);
});

it('emits <pm> run <script> argv for runScript', function (): void {
    file_put_contents($this->root . '/yarn.lock', '');

    expect(PackageManager::detect($this->root)->runScript('build'))
        ->toBe(['yarn', 'run', 'build']);
});

it('tolerates trailing slash on projectRoot', function (): void {
    file_put_contents($this->root . '/yarn.lock', '');

    expect(PackageManager::detect($this->root . '/')->name())->toBe('yarn');
});
