<?php

declare(strict_types=1);

namespace Bambamboole\AcornTesting\Testing;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Factory;

/**
 * Fluent builder for running Unlighthouse-CI from a Pest browser test
 * (or anywhere else). Hides the subprocess wiring and command-line flag
 * construction behind a chainable API.
 *
 * Usage from a test:
 *
 *     Lighthouse::for('http://127.0.0.1:8080')
 *         ->budget(80)
 *         ->excludedUrls(['/wp-admin/', '/wp-login.php'])
 *         ->mobile()
 *         ->samples(3)
 *         ->configPath('./unlighthouse.config.js')
 *         ->run()
 *         ->throw();   // bubble up the budget-failure assertion
 *
 * `run()` returns Illuminate's ProcessResult — call ->successful(),
 * ->throw(), ->output(), ->errorOutput() as you would for any
 * Illuminate Process invocation.
 *
 * Per-category budgets aren't a CLI flag in Unlighthouse — they live in
 * `unlighthouse.config.js`. The `budget()` method here sets the single
 * `--budget` CLI flag (one threshold for every category). Reach for
 * `configPath()` if your project needs different floors per category.
 */
final class Lighthouse
{
    /** @var list<string> */
    private array $excludedUrls = [];

    private ?int $budget = null;

    private ?bool $mobile = null;

    private ?int $samples = null;

    private ?string $configPath = null;

    private ?int $timeout = 600;

    private bool $streamOutput = true;

    private function __construct(private readonly string $site) {}

    public static function for(string $site): self
    {
        return new self($site);
    }

    /**
     * Single Lighthouse score floor (1–100) applied to every category.
     * Per-category budgets go in `unlighthouse.config.js` and are honoured
     * automatically when `configPath()` is set (or when a config file
     * exists at the cwd Unlighthouse discovers).
     */
    public function budget(int $score): self
    {
        $this->budget = $score;

        return $this;
    }

    /**
     * @param  list<string>  $urls  Relative paths (or regex) Unlighthouse
     *                              should skip during the crawl.
     */
    public function excludedUrls(array $urls): self
    {
        $this->excludedUrls = array_values($urls);

        return $this;
    }

    public function mobile(): self
    {
        $this->mobile = true;

        return $this;
    }

    public function desktop(): self
    {
        $this->mobile = false;

        return $this;
    }

    /**
     * Number of Lighthouse runs to average per URL (Unlighthouse default is 1).
     * Higher samples = more stable scores at the cost of total wall-clock time.
     */
    public function samples(int $count): self
    {
        $this->samples = $count;

        return $this;
    }

    public function configPath(string $path): self
    {
        $this->configPath = $path;

        return $this;
    }

    /**
     * Wall-clock timeout for the subprocess in seconds. Pass null to disable.
     */
    public function timeout(?int $seconds): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Suppress subprocess output streaming to STDOUT/STDERR. The output is
     * still captured on the ProcessResult — use `->output()` etc. to read it.
     */
    public function quietly(): self
    {
        $this->streamOutput = false;

        return $this;
    }

    public function run(?Factory $process = null): ProcessResult
    {
        $process ??= new Factory();

        $callback = $this->streamOutput
            ? static function (string $type, string $buffer): void {
                fwrite($type === 'err' ? STDERR : STDOUT, $buffer);
            }
            : null;

        return $process
            ->path(TestingConfig::projectRoot())
            ->env(['NODE_OPTIONS' => '--use-system-ca'])
            ->timeout($this->timeout)
            ->run($this->command(), $callback);
    }

    /**
     * @return list<string>
     *
     * @internal Exposed for testability — the unit test asserts on the
     *           composed command without spawning a real subprocess.
     */
    public function command(): array
    {
        $cmd = ['npx', 'unlighthouse-ci', '--site', $this->site];

        if ($this->budget !== null) {
            $cmd[] = '--budget';
            $cmd[] = (string) $this->budget;
        }

        if ($this->excludedUrls !== []) {
            $cmd[] = '--exclude-urls';
            $cmd[] = implode(',', $this->excludedUrls);
        }

        if ($this->mobile === true) {
            $cmd[] = '--mobile';
        } elseif ($this->mobile === false) {
            $cmd[] = '--desktop';
        }

        if ($this->samples !== null) {
            $cmd[] = '--samples';
            $cmd[] = (string) $this->samples;
        }

        if ($this->configPath !== null) {
            $cmd[] = '--config-file';
            $cmd[] = $this->configPath;
        }

        return $cmd;
    }
}
