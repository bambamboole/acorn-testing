# acorn-testing

FrankenPHP-backed Pest browser testing for WordPress + [Bedrock](https://roots.io/bedrock/) + [Acorn](https://roots.io/acorn/) projects.

[![Latest Stable Version](https://poser.pugx.org/bambamboole/acorn-testing/v)](https://packagist.org/packages/bambamboole/acorn-testing)
[![License](https://poser.pugx.org/bambamboole/acorn-testing/license)](LICENSE.md)

Replaces the test driver that wraps wp-cli's single-threaded `wp server` with a [FrankenPHP](https://frankenphp.dev/) (Caddy + libphp) subprocess. This is required for any flow that fans out into concurrent requests — the canonical case is the WooCommerce Blocks Store API checkout, but anything with overlapping AJAX / redirects / fragment loads hits the same limitation.

## What you get

- **`Bambamboole\AcornTesting\Testing\FeatureTestCase`** — Pest base case that boots WordPress + Acorn + Eloquent once per process and re-imports a seeded baseline dump before each test.
- **`Bambamboole\AcornTesting\Testing\BrowserTestCase`** — extends the feature base; spawns FrankenPHP and wires it into [`pest-plugin-browser`](https://pestphp.com/docs/browser-testing).
- **`Bambamboole\AcornTesting\Testing\FrankenPhpDriver`** — the actual `pest-plugin-browser` `HttpServer` implementation. Auto-downloads the binary on first use if missing.
- **`wp acorn frankenphp:install [--force]`** — ergonomic CLI alias for pre-warming or forcing a re-download of the binary.
- **`.ai/` assets** — a Boost-discoverable guideline + skill that tell `wp acorn boost:update` about the package's testing conventions.

## Requirements

- PHP 8.4+
- WordPress 6.x with Bedrock layout (`public/` web root)
- Acorn 6.1+
- Pest 4+

## Install

```bash
composer require --dev bambamboole/acorn-testing
wp acorn vendor:publish --tag=acorn-testing-config
```

Then in `tests/Pest.php`:

```php
uses(Bambamboole\AcornTesting\Testing\FeatureTestCase::class)->in('Feature');
uses(Bambamboole\AcornTesting\Testing\BrowserTestCase::class)->in('Browser');
```

In `config/acorn-testing.php` (just published), fill in the project-specific values — typically `seeders`, `wp_title`, and `admin_email`:

```php
return [
    'seeders' => [Database\Seeders\WordPressBaselineSeeder::class],
    'wp_title' => 'My Project Tests',
    'admin_email' => 'admin@myproject.test',
];
```

Add the FrankenPHP binary to your `.gitignore`:

```
/frankenphp
```

That's it. `composer test:browser` (or however your project runs Pest) will spawn FrankenPHP, drive your tests, and tear it down. The first run downloads the binary (~165MB, ~30s on a typical connection).

## CI integration

Cache the binary across runs. Example for GitHub Actions:

```yaml
- name: FrankenPHP cache
  uses: actions/cache@v4
  with:
    path: frankenphp
    key: frankenphp-${{ runner.os }}-v1.11.2
```

No extra install step is needed — the driver downloads the binary on the first browser test if the cache missed.

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
