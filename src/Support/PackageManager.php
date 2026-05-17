<?php

declare(strict_types=1);

namespace Bambamboole\AcornTesting\Support;

/**
 * Detects which JavaScript package manager a project uses, based on the
 * lockfile present in its root.
 *
 * Order of precedence (matches what corepack / Node tooling expects):
 *   1. pnpm   — pnpm-lock.yaml
 *   2. yarn   — yarn.lock
 *   3. npm    — package-lock.json or nothing (default)
 *
 * Used by TestingSetupCommand to install dev-dependencies and by
 * FeatureTestCase to invoke `<pm> run build` for the Vite manifest.
 * Without this, the package would always run npm — which creates a stray
 * `package-lock.json` next to an existing `yarn.lock` / `pnpm-lock.yaml`
 * and splits dependency state.
 */
final class PackageManager
{
    private function __construct(
        private readonly string $name,
    ) {}

    public static function detect(string $projectRoot): self
    {
        $projectRoot = rtrim($projectRoot, '/');

        if (is_file($projectRoot . '/pnpm-lock.yaml')) {
            return new self('pnpm');
        }

        if (is_file($projectRoot . '/yarn.lock')) {
            return new self('yarn');
        }

        return new self('npm');
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Command argv to install one or more packages as dev-dependencies.
     *
     * @param  list<string>  $packages
     * @return list<string>
     */
    public function addDev(array $packages): array
    {
        return match ($this->name) {
            'yarn' => array_merge(['yarn', 'add', '--dev'], $packages),
            'pnpm' => array_merge(['pnpm', 'add', '--save-dev'], $packages),
            default => array_merge(['npm', 'install', '--save-dev'], $packages),
        };
    }

    /**
     * Command argv to run a named script defined in package.json.
     *
     * All three managers support `<pm> run <script>` so the switch is just
     * the binary name.
     *
     * @return list<string>
     */
    public function runScript(string $script): array
    {
        return [$this->name, 'run', $script];
    }
}
