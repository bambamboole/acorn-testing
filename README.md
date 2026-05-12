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

Wire up a single browser test that shells out to Unlighthouse — for example `tests/Browser/LighthouseTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Process\Factory;

it('passes Lighthouse budgets for all crawled URLs', function (): void {
    update_option('blog_public', 1);
    update_option('blogdescription', 'My project tagline.');

    visit('/');

    $result = new Factory()
        ->path(dirname(__DIR__, 2))
        ->env(['NODE_OPTIONS' => '--use-system-ca'])
        ->timeout(600)
        ->run(
            ['npx', 'unlighthouse-ci', '--site', 'http://127.0.0.1:8080'],
            fn (string $type, string $buffer) => fwrite($type === 'err' ? STDERR : STDOUT, $buffer),
        );

    expect($result->successful())->toBeTrue('Unlighthouse exited non-zero — a URL fell below budget.');
})->group('lighthouse');
```

Tag it `lighthouse` and exclude it from the regular suite so iteration stays fast:

```json
"test:browser": "pest --testsuite=browser --exclude-group=lighthouse",
"lighthouse": "pest tests/Browser/LighthouseTest.php"
```

`composer lighthouse` runs only the audit (~60s). Adjust budgets in `unlighthouse.config.js`.

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
