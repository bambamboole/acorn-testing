# acorn-testing

A complete browser-testing toolkit for WordPress + [Bedrock](https://roots.io/bedrock/) + [Acorn](https://roots.io/acorn/) projects: FrankenPHP-backed Pest browser tests, plus Playwright and Unlighthouse wired up with one command.

[![Latest Stable Version](https://poser.pugx.org/bambamboole/acorn-testing/v)](https://packagist.org/packages/bambamboole/acorn-testing)
[![License](https://poser.pugx.org/bambamboole/acorn-testing/license)](LICENSE.md)

Replaces the test driver that wraps wp-cli's single-threaded `wp server` with a [FrankenPHP](https://frankenphp.dev/) (Caddy + libphp) subprocess. Required for any flow that fans out into concurrent requests — the canonical case is the WooCommerce Blocks Store API checkout, but anything with overlapping AJAX / redirects / fragment loads hits the same limitation.

## What you get

- **`Bambamboole\AcornTesting\Testing\FeatureTestCase`** — Pest base case that boots WordPress + Acorn + Eloquent once per process and re-imports a seeded baseline dump before each test.
- **`Bambamboole\AcornTesting\Testing\BrowserTestCase`** — extends the feature base; spawns FrankenPHP and wires it into [`pest-plugin-browser`](https://pestphp.com/docs/browser-testing).
- **`Bambamboole\AcornTesting\Testing\FrankenPhpDriver`** — the actual `pest-plugin-browser` `HttpServer` implementation. Auto-downloads the binary on first use if missing.
- **`wp acorn testing:setup`** — one-shot provisioning: downloads FrankenPHP, updates `.gitignore`, installs Playwright + Unlighthouse + Puppeteer as npm dev-deps, runs `playwright install chromium`, publishes `unlighthouse.config.js`. Idempotent — safe to re-run.
- **`unlighthouse.config.js` stub** — pragmatic per-category Lighthouse budgets (Performance 80, Accessibility 95, Best Practices 90, SEO 95) and a 20-route crawl cap.
- **`.ai/` assets** — a Boost-discoverable guideline + skill that tell `wp acorn boost:update` about the package's testing conventions.

## Requirements

- PHP 8.4+
- WordPress 6.x with Bedrock layout (`public/` web root)
- Acorn 6.1+
- Pest 4+
- Node.js 22+ (for Playwright / Unlighthouse)

## Install

```bash
composer require --dev bambamboole/acorn-testing
wp acorn testing:setup
wp acorn vendor:publish --tag=acorn-testing-config
```

`testing:setup` provisions everything needed to run browser + Lighthouse tests:

1. Downloads the pinned FrankenPHP binary into `./frankenphp`.
2. Appends `/frankenphp` and `.unlighthouse/` to `.gitignore` (if missing).
3. Adds `playwright`, `puppeteer`, and `unlighthouse-ci` to `package.json` dev-deps (if missing).
4. Runs `npx playwright install chromium` so headless Chrome is on disk.
5. Publishes `unlighthouse.config.js` to the project root (if not present).

Then bind your Pest suites in `tests/Pest.php`:

```php
uses(Bambamboole\AcornTesting\Testing\FeatureTestCase::class)->in('Feature');
uses(Bambamboole\AcornTesting\Testing\BrowserTestCase::class)->in('Browser');
```

And fill in project values in the published `config/acorn-testing.php` — typically `seeders`, `wp_title`, `admin_email`:

```php
return [
    'seeders' => [Database\Seeders\WordPressBaselineSeeder::class],
    'wp_title' => 'My Project Tests',
    'admin_email' => 'admin@myproject.test',
];
```

That's it. `composer test:browser` (or however your project runs Pest) will spawn FrankenPHP, drive your tests, and tear it down.

## Running the Lighthouse audit

Wire up a single browser test that uses the `Lighthouse` builder — for example `tests/Browser/LighthouseTest.php`:

```php
<?php

declare(strict_types=1);

use Bambamboole\AcornTesting\Testing\Lighthouse;

it('passes Lighthouse budgets for all crawled URLs', function (): void {
    update_option('blog_public', 1);
    update_option('blogdescription', 'My project tagline.');

    Lighthouse::local()->run()->throw();
})->group('lighthouse');
```

`Lighthouse::local()` bootstraps the FrankenPHP test server (idempotent — the `BrowserTestCase` parent has typically already started it) and reads the URL from `FrankenPhpDriver::active()`. No `visit()` warm-up needed. The DB has already been re-imported from the seeded dump by `FeatureTestCase::setUp()` before your test method runs, so any `update_option()` calls you make right before `Lighthouse::local()` will be visible to FrankenPHP via the shared MySQL connection.

> **`Lighthouse::local()` must be called from a `BrowserTestCase`-extending test.** It reads the active `FrankenPhpDriver` that `BrowserTestCase::setUpBeforeClass` registered. From outside that context (a plain Feature/Unit test, a CLI script), call `Lighthouse::remote('http://...')` with an explicit URL instead, or pass a `LocalServer` into `Lighthouse::local($server)` yourself.

For an explicit external target, use `Lighthouse::remote('https://staging.example.com')` — no local server is started; Unlighthouse only needs network access to the URL.

Both entry points return a chainable builder. Available options (all chained, then `->run()`):

| Method | What it does |
| --- | --- |
| `budget(int $score)` | Single Lighthouse score floor (1–100) for every category via `--budget`. Per-category floors go in `unlighthouse.config.js`. |
| `excludedUrls(array $urls)` | Paths (or regex) Unlighthouse should skip. Joined into `--exclude-urls`. |
| `mobile()` / `desktop()` | Force the viewport. Default = whatever Unlighthouse picks. |
| `samples(int $count)` | Number of Lighthouse runs to average per URL. Higher = more stable, slower. |
| `configPath(string $path)` | Point at a non-default `unlighthouse.config.{js,ts,mjs}`. |
| `timeout(?int $seconds)` | Subprocess wall-clock timeout. Default 600s; pass `null` to disable. |
| `quietly()` | Suppress streaming output to STDOUT/STDERR. Output stays captured on the report. |

### `LighthouseReport` — structured return

`->run()` returns a `LighthouseReport` that wraps both the subprocess result and the per-URL audits parsed from Unlighthouse's `.unlighthouse/ci-result.json`:

```php
$report = Lighthouse::local()->run();

// Pass/fail signal — same shape as Illuminate's ProcessResult
$report->successful();
$report->failed();
$report->exitCode();
$report->output();
$report->errorOutput();
$report->throw();   // RuntimeException on !successful, returns $report otherwise

// Parsed audits (list<UrlAudit>) — one entry per URL Unlighthouse crawled
foreach ($report->audits as $audit) {
    echo $audit->path                  // '/blog/hello-world/'
       . ' perf=' . $audit->performance // 0.97
       . ' a11y=' . $audit->accessibility
       . ' bp='   . $audit->bestPractices
       . ' seo='  . $audit->seo
       . ' avg='  . $audit->score
       . "\n";
}

// Lookup helpers
$report->audit('/');                   // ?UrlAudit by exact path
$report->below('seo', 0.9);            // list<UrlAudit> with seo < 0.9
$report->below('performance', 0.8);    // same shape, perf < 0.8
```

The `below(string $category, float $floor)` helper is convenient for assertions richer than "audit passed": e.g. fail the test if any URL's accessibility drops below 1.0 even when the overall budget is 0.95.

Tag it `lighthouse` and exclude it from the regular suite so iteration stays fast:

```json
"test:browser": "pest --testsuite=browser --exclude-group=lighthouse",
"lighthouse": "pest tests/Browser/LighthouseTest.php"
```

`composer lighthouse` runs only the audit (~60s). Adjust budgets in `unlighthouse.config.js`.

## Auditing a deployed environment (staging / production)

`Lighthouse::remote($url)` is the equivalent for a deployed site. Same builder, same report, no local server boot — Unlighthouse just needs network access:

```php
use Bambamboole\AcornTesting\Testing\Lighthouse;

Lighthouse::remote('https://staging.example.com')
    ->budget(85)
    ->run()
    ->throw();
```

For one-off audits outside the Pest harness, the same flag set is available on the raw `unlighthouse-ci` CLI:

```bash
# One-off (uses the local unlighthouse.config.js budgets)
npx unlighthouse-ci --site https://staging.example.com

# Or via env var so the config file's `process.env.UNLIGHTHOUSE_SITE` picks it up
UNLIGHTHOUSE_SITE=https://staging.example.com npx unlighthouse-ci
```

Wire it as a composer/npm script if you do this often:

```json
"lighthouse:staging": "UNLIGHTHOUSE_SITE=https://staging.example.com npx unlighthouse-ci",
"lighthouse:production": "UNLIGHTHOUSE_SITE=https://example.com npx unlighthouse-ci"
```

Practical notes for non-local audits:
- **Basic auth** (common on staging): `npx unlighthouse-ci --site https://staging.example.com --auth user:pass`.
- **Cookies / headers** (logged-in audits, feature flags): `--cookies "key=value;key2=value2"` and `--extra-headers "X-Feature=on,X-Other=bar"`.
- **Sitemap fast-path**: if your site exposes one, `--sitemaps /sitemap.xml` skips link-crawl and audits exactly what's listed.
- **CI against staging on every deploy**: same pattern, just point at the staging URL in your post-deploy workflow. No FrankenPHP, no test DB — Unlighthouse only needs network access to the deployed URL.
- **Budgets are shared**: the same `unlighthouse.config.js` `ci.budget` block applies to whichever site you point at. A regression on staging fails the same way a regression on the local audit does.

## CI integration

Cache the binary across runs. Example for GitHub Actions:

```yaml
- name: FrankenPHP cache
  uses: actions/cache@v4
  with:
    path: frankenphp
    key: frankenphp-${{ runner.os }}-v1.11.2
```

No extra install step is needed in CI — the driver auto-downloads the binary on the first browser test if the cache missed. For the Lighthouse audit, also cache `~/.cache/ms-playwright`.

## Configuration reference

`config/acorn-testing.php` keys (all optional):

| Key | Default | Description |
| --- | --- | --- |
| `frankenphp_binary` | `<project>/frankenphp` | Path to the binary. Override via `FRANKENPHP_BINARY` env var if needed. |
| `webroot` | `'public'` | Bedrock document root, passed to `frankenphp php-server --root`. |
| `seeders` | `[]` | Seeder FQCNs run when building `testing.sql`. |
| `wp_title` | `'Test Site'` | Passed to `wp core install --title`. |
| `admin_email` | `'admin@test.test'` | Passed to `wp core install --admin_email`. |
| `plugins` | `'all'` | `'all'`, an array of slugs, or `[]` to skip plugin activation. |
| `dump_path` | `<project>/database/dumps/testing.sql` | Where the seeded baseline dump is stored. |
| `playwright_timeout_ms` | `90_000` | Playwright per-action timeout. |

## Why FrankenPHP?

`wp server` wraps PHP's built-in `php -S` dev server, which is **single-threaded**. It serves one request at a time. WC Blocks checkout (and similar flows) make overlapping calls in close succession — POST to `/wp-json/wc/store/v1/checkout`, redirect to order-received, fragment AJAX — and the second request blocks the first. Locally on a fast CPU it usually races through; on a slow CI runner it deadlocks.

FrankenPHP runs Caddy with libphp embedded, multithreaded by default. The same test that's flaky on `wp server` runs reliably on FrankenPHP, including in CI.

## License

MIT. See [LICENSE.md](LICENSE.md).
