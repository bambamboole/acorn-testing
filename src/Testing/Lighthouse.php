<?php

declare(strict_types=1);

namespace Bambamboole\AcornTesting\Testing;

use Illuminate\Process\Factory;
use RuntimeException;

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

    /**
     * Audit the FrankenPHP test server. Defaults to `FrankenPhpDriver::active()`
     * — i.e. whichever driver `BrowserTestCase::setUpBeforeClass` started.
     * Calls `bootstrap()` (idempotent) and reads the base URL from the
     * driver, so the audit doesn't need to know about ports or hosts.
     */
    public static function local(?LocalServer $server = null): self
    {
        $server ??= FrankenPhpDriver::active();

        if ($server === null) {
            throw new RuntimeException(
                'Lighthouse::local() requires an active LocalServer. Call it from a '
                . 'Pest browser test that extends Bambamboole\AcornTesting\Testing\BrowserTestCase, '
                . 'or pass a LocalServer explicitly.',
            );
        }

        $server->bootstrap();

        return new self($server->url());
    }

    /**
     * Audit an explicit external URL (staging, production, preview deploy).
     * No local server is started — Unlighthouse only needs network access
     * to the target.
     */
    public static function remote(string $url): self
    {
        return new self($url);
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

    public function run(?Factory $process = null): LighthouseReport
    {
        $process ??= new Factory();

        $callback = $this->streamOutput
            ? static function (string $type, string $buffer): void {
                fwrite($type === 'err' ? STDERR : STDOUT, $buffer);
            }
            : null;

        $root = TestingConfig::projectRoot();

        $result = $process
            ->path($root)
            ->env(['NODE_OPTIONS' => '--use-system-ca'])
            ->timeout($this->timeout)
            ->run($this->command(), $callback);

        return new LighthouseReport($result, $this->parseAudits($root));
    }

    /**
     * Read the per-URL audits Unlighthouse writes to
     * `<root>/.unlighthouse/ci-result.json`. Returns an empty list if the
     * file doesn't exist (e.g. Unlighthouse exited before writing it) or
     * isn't valid JSON.
     *
     * @return list<UrlAudit>
     */
    private function parseAudits(string $root): array
    {
        $path = $root . '/.unlighthouse/ci-result.json';

        if (! is_file($path)) {
            return [];
        }

        $contents = (string) file_get_contents($path);
        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $entry): UrlAudit => UrlAudit::fromArray(is_array($entry) ? $entry : []),
            $decoded,
        ));
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
