# bambamboole/acorn-testing

FrankenPHP-backed Pest browser testing for WordPress + Bedrock + Acorn projects. Replaces wp-cli's single-threaded `wp server` driver — necessary for flows like WC Blocks Store API checkout where the test fans out into overlapping requests that the built-in PHP dev server can't drive.

## What this package provides

- `Bambamboole\AcornTesting\Testing\FeatureTestCase` — base case for feature tests. Boots WP+Acorn+Eloquent on demand, manages a baseline SQL dump, exposes a `seed()` helper.
- `Bambamboole\AcornTesting\Testing\BrowserTestCase` — extends the feature base; spawns FrankenPHP and wires it into pest-plugin-browser.
- `Bambamboole\AcornTesting\Testing\FrankenPhpDriver` — the actual `pest-plugin-browser` `HttpServer` driver. Auto-downloads the binary on first use if missing.
- `wp acorn testing:setup [--force] [--skip-npm]` — one-shot provisioning: FrankenPHP binary, `.gitignore`, npm dev-deps (playwright/puppeteer/unlighthouse-ci), Playwright Chromium, and a stubbed `unlighthouse.config.js`. Idempotent.

## Conventions when working in a project that uses this package

- Project tests bind Pest at `tests/Pest.php` directly to the package classes:
  ```php
  uses(Bambamboole\AcornTesting\Testing\FeatureTestCase::class)->in('Feature');
  uses(Bambamboole\AcornTesting\Testing\BrowserTestCase::class)->in('Browser');
  ```
- Project-specific values live in `config/acorn-testing.php` (publish via `wp acorn vendor:publish --tag=acorn-testing-config`). The values you'll most often want to override:
  - `seeders` — list of seeder FQCNs to populate the baseline dump
  - `wp_title` and `admin_email` — passed to `wp core install`
  - `plugins` — `'all'` (default) or an array of slugs
- The FrankenPHP binary lives at `<project>/frankenphp` by default; add `/frankenphp` to `.gitignore`. Override the path via `FRANKENPHP_BINARY` env var or the `frankenphp_binary` config key.
- Force a baseline dump rebuild by deleting the file at `config('acorn-testing.dump_path')` (default `database/dumps/testing.sql`). The next test run rebuilds it.

## Don't

- Don't call `wp server` from the test driver — the whole point of this package is that it can't handle concurrent requests.
- Don't ship the FrankenPHP binary in source control. It's 164MB per platform; CI should cache it via `actions/cache@v4` keyed on `runner.os + pinned version`.
- Don't hardcode the project-specific seeder class name in code that lives in this package — it goes in the consumer's `config/acorn-testing.php`.
