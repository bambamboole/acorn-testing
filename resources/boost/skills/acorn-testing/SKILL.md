---
name: acorn-testing
description: Use when writing or fixing Pest tests in a WordPress + Bedrock + Acorn project that uses bambamboole/acorn-testing for browser testing. Trigger when files in tests/Feature/ or tests/Browser/ are being edited, when a browser test is failing, when the test driver / FrankenPHP binary is involved, or when the user asks how to write tests for an Acorn project with concurrent-request flows (WC checkout, AJAX, REST endpoints).
license: MIT
---

# Pest testing with bambamboole/acorn-testing

This skill covers writing Pest tests in projects that use `bambamboole/acorn-testing` — a FrankenPHP-backed test server that replaces the single-threaded `wp server`.

## Three test suites

- **`unit`** (`tests/Unit/`) — pure PHP, no framework boot. Default PHPUnit `TestCase`. For value objects, helpers, anything that doesn't touch WP/Eloquent/DB.
- **`feature`** (`tests/Feature/`) — extends `Bambamboole\AcornTesting\Testing\FeatureTestCase`. Boots WP+Acorn+Eloquent on first use (once per process). For Eloquent scopes, REST controllers, service providers, anything that needs WP functions or DB access.
- **`browser`** (`tests/Browser/`) — extends `Bambamboole\AcornTesting\Testing\BrowserTestCase`. Inherits the feature boot + spawns FrankenPHP. For end-to-end `visit('/path')` flows.

Bind in `tests/Pest.php`:

```php
uses(Bambamboole\AcornTesting\Testing\FeatureTestCase::class)->in('Feature');
uses(Bambamboole\AcornTesting\Testing\BrowserTestCase::class)->in('Browser');
```

If `tests/Unit/` is empty in `Pest.php`'s `uses()`, those tests use plain PHPUnit — fastest, sub-second per run.

## Choosing the right suite

- Needs `App\Models\Post::published()` or any Eloquent? → **Feature**
- Needs `visit('/foo')` or browser interaction? → **Browser**
- Pure PHP / no DB / no WP? → **Unit**

A "unit" test that imports an Eloquent model fails with "no manager has been set" because the capsule isn't booted there. Move it to `tests/Feature/`.

## Seeding additional data

The baseline dump (built once per machine and re-imported before every test) already contains whatever `config('acorn-testing.seeders')` produces. Call `$this->seed(...)` only for **additional** seeders that build on top of that baseline:

```php
use Database\Seeders\Examples\BlogPostsSeeder;

it('shows the latest posts on the blog', function () {
    $this->seed(BlogPostsSeeder::class);  // adds 15 posts
    visit('/blog/')->assertSee('Page 2');
});
```

Calling the baseline seeder again from `beforeEach` is redundant and slow — the dump already has it.

## Forcing a dump rebuild

Plugin/migration/seeder changes on the project don't auto-trigger a rebuild. Delete the dump file:

```bash
rm database/dumps/testing.sql
```

(Or wherever `config('acorn-testing.dump_path')` points.) The next test run rebuilds it from the current seeder set.

## Writing browser tests

```php
declare(strict_types=1);

it('shows the welcome page', function (): void {
    visit('/welcome/')->assertSee('Welcome');
});

it('navigates to a single post', function (): void {
    visit('/blog/')
        ->click('h2 a')
        ->assertPathIs('/blog/hello-world/')
        ->assertSee('Hello world');
});
```

Smoke multiple paths in one shot and assert no JS errors:

```php
$pages = visit(['/', '/blog/', '/about/']);
$pages->assertNoJavaScriptErrors()->assertNoConsoleLogs();
```

## Running Lighthouse

Use the `Lighthouse` builder from a tagged Pest browser test. It hides the subprocess wiring behind a chainable API:

```php
use Bambamboole\AcornTesting\Testing\Lighthouse;

it('passes Lighthouse budgets', function (): void {
    update_option('blog_public', 1);
    update_option('blogdescription', 'Your tagline.');

    Lighthouse::local()->run()->throw();
})->group('lighthouse');
```

Two entry points: `Lighthouse::local()` bootstraps the FrankenPHP test server (same plumbing `visit()` uses) and auto-resolves the URL; `Lighthouse::remote('https://staging.example.com')` audits any explicit external URL without touching the local server.

Chainable options before `->run()`:
- `budget(int)` — single score floor 1–100 for every category (per-category floors live in `unlighthouse.config.js`)
- `excludedUrls(array)` — paths/regex to skip during crawl
- `mobile()` / `desktop()` — force the viewport
- `samples(int)` — Lighthouse runs to average per URL
- `configPath(string)` — non-default Unlighthouse config
- `timeout(?int)` — subprocess seconds, `null` to disable (default 600)
- `quietly()` — suppress streaming output; still captured on the result

`run()` returns a `LighthouseReport`. It wraps the subprocess (`successful()`, `failed()`, `exitCode()`, `output()`, `errorOutput()`, `throw()`) AND the parsed per-URL audits from `.unlighthouse/ci-result.json`:

```php
$report->audits;                       // list<UrlAudit> — path + score per category
$report->audit('/blog/');              // ?UrlAudit by exact path
$report->below('seo', 0.9);            // list<UrlAudit> where seo < 0.9
```

`UrlAudit` is a readonly value object with: `path`, `score` (average), `performance`, `accessibility`, `bestPractices`, `seo`. Use `below()` for richer-than-"audit-passed" assertions (e.g. tighten Accessibility individually).

Tag the test `lighthouse` and exclude from the fast suite:

```json
"test:browser": "pest --testsuite=browser --exclude-group=lighthouse",
"lighthouse": "pest tests/Browser/LighthouseTest.php"
```

## Setup expectations

- `composer install` pulls the package and its PHP deps (pest, pest-browser-plugin, illuminate/process).
- `wp acorn testing:setup` provisions the full test environment in one shot: downloads FrankenPHP, adds `/frankenphp` + `.unlighthouse/` to `.gitignore`, installs `playwright` + `puppeteer` + `unlighthouse-ci` as npm dev-deps, runs `npx playwright install chromium`, publishes `unlighthouse.config.js`. Idempotent — re-run after pulling a new package version to pick up changes.
- The FrankenPHP binary alone also auto-downloads on the first browser-test run, so `testing:setup` is mostly for the npm / Playwright / Unlighthouse pieces.
- Flags: `--force` re-downloads the FrankenPHP binary; `--skip-npm` skips the Playwright/Unlighthouse install (useful in environments without Node).

## Troubleshooting

- **"FrankenPHP install failed: …"** — the driver tried to download and curl exited non-zero. Check network / GitHub reachability. Try `wp acorn testing:setup --force`.
- **"WP_HOME is not set"** — `.env.testing` is missing or `phpunit.xml`'s `<env force="true">` block isn't passing WP_HOME through.
- **"FrankenPHP exited before becoming ready"** — the test DB isn't installed. Delete the dump file and re-run; the next run rebuilds.
- **"did not start listening on 127.0.0.1:8080 within 30s"** — port 8080 is taken. Free it (`lsof -i :8080`) and re-run.
- **"Could not locate the Bedrock project root"** — the package's project-root finder walked up from its own location and didn't find `bedrock/application.php`. Set `ACORN_TESTING_PROJECT_ROOT` in the test process env to override (rarely needed).

## Common pitfalls

- Calling the baseline seeder in `beforeEach` — redundant, makes tests slow.
- Putting a test in `tests/Unit/` that uses `App\Models\*` — Eloquent isn't booted there.
- Overriding `setUpBeforeClass` in a Feature test and forgetting `parent::setUpBeforeClass()` — the static-guarded Acorn boot won't fire.
- After pulling a branch that changes plugins, migrations, or seeders: `rm` the dump and let it rebuild.
- Skipping `wp acorn testing:setup` on a fresh checkout (it does the one-time Playwright + Unlighthouse provisioning).
- Hardcoding `WordPressBaselineSeeder` or any specific class name — the package's test infrastructure is generic; the seeder list comes from `config('acorn-testing.seeders')`.
