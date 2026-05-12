<?php

declare(strict_types=1);

/**
 * Defaults for bambamboole/acorn-testing.
 *
 * Publish into your project with:
 *   wp acorn vendor:publish --tag=acorn-testing-config
 *
 * Then override the values that need to differ from the defaults — typically
 * `seeders`, `wp_title`, and `admin_email`. The rest are sensible enough for
 * most Bedrock + Acorn projects.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Pinned FrankenPHP version
    |--------------------------------------------------------------------------
    |
    | Informational. The version actually downloaded is hard-pinned inside
    | Bambamboole\AcornTesting\Support\FrankenphpInstaller::VERSION. Bumping
    | this constant requires a package release.
    |
    */
    'frankenphp_version' => 'v1.11.2',

    /*
    |--------------------------------------------------------------------------
    | FrankenPHP binary path
    |--------------------------------------------------------------------------
    |
    | Where the FrankenPHP binary lives on disk. Defaults to `<project>/frankenphp`.
    | Add this to your project `.gitignore`. The browser test driver
    | auto-downloads the binary on first use if it's missing.
    |
    */
    'frankenphp_binary' => env('FRANKENPHP_BINARY'),

    /*
    |--------------------------------------------------------------------------
    | Bedrock document root
    |--------------------------------------------------------------------------
    |
    | The directory FrankenPHP serves PHP files from. For standard Bedrock
    | projects this is `public`.
    |
    */
    'webroot' => 'public',

    /*
    |--------------------------------------------------------------------------
    | Seeders to run when building the test database dump
    |--------------------------------------------------------------------------
    |
    | Array of seeder class FQCNs. Each will be invoked via
    |   wp acorn db:seed --class=<Class>
    | when (re)building `database/dumps/testing.sql`. Most projects supply a
    | single baseline seeder here.
    |
    */
    'seeders' => [],

    /*
    |--------------------------------------------------------------------------
    | wp core install — site title
    |--------------------------------------------------------------------------
    */
    'wp_title' => 'Test Site',

    /*
    |--------------------------------------------------------------------------
    | wp core install — admin email
    |--------------------------------------------------------------------------
    */
    'admin_email' => 'admin@test.test',

    /*
    |--------------------------------------------------------------------------
    | Plugin activation strategy
    |--------------------------------------------------------------------------
    |
    | One of:
    |   'all'      — runs `wp plugin activate --all`
    |   ['slug',…] — runs `wp plugin activate <slug> <slug>`
    |   []         — skip plugin activation entirely
    |
    */
    'plugins' => 'all',

    /*
    |--------------------------------------------------------------------------
    | Test database dump path
    |--------------------------------------------------------------------------
    |
    | Where the seeded baseline SQL dump is stored. null = use the default
    | `<project>/database/dumps/testing.sql`. Re-importing this dump before
    | every test is what gives feature/browser tests their isolation.
    |
    */
    'dump_path' => null,

    /*
    |--------------------------------------------------------------------------
    | Playwright per-action timeout (milliseconds)
    |--------------------------------------------------------------------------
    |
    | The default 5s pest-plugin-browser timeout isn't enough for a cold-boot
    | first WordPress request. 90s is generous; bump it if your CI is slow.
    |
    */
    'playwright_timeout_ms' => 90_000,
];
