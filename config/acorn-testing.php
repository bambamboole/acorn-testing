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
    | Custom build command
    |--------------------------------------------------------------------------
    |
    | Shell command that builds the test database and writes the dump. Set this
    | when your baseline needs more than `plugins` + `seeders` can express —
    | plugin activation order, migrations, post-migration fixes. When set, it
    | replaces the built-in build entirely.
    |
    |   'build_command' => 'php scripts/setup.php env:seed --test',
    |
    | With this null and both `plugins` and `seeders` empty, building is refused
    | rather than silently producing a bare WordPress install.
    |
    */
    'build_command' => null,

    /*
    |--------------------------------------------------------------------------
    | Staleness watch paths
    |--------------------------------------------------------------------------
    |
    | Globs, relative to the project root, whose newest mtime is compared
    | against the dump. If anything here is newer, the suite fails instead of
    | testing the previously built world — the dump is authoritative, so an
    | edited seeder is otherwise invisible until someone rebuilds by hand.
    |
    | Set to [] to opt out.
    |
    */
    'watch_paths' => [
        'database/seeders/*.php',
        'database/seeders/**/*.php',
    ],

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

    /*
    |--------------------------------------------------------------------------
    | Per-test isolation — protected tables
    |--------------------------------------------------------------------------
    |
    | Unprefixed table names isolation never touches. These keep WordPress
    | "installed" between tests: site options, applied migrations, the taxonomy
    | structure. Everything not listed here or in `scoped_tables` is truncated
    | between tests — but only when it actually holds rows.
    |
    */
    'protected_tables' => [
        'options',
        'migrations',
        'terms',
        'term_taxonomy',
        'termmeta',
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-test isolation — scoped tables
    |--------------------------------------------------------------------------
    |
    | Unprefixed table name => the column holding the owning object id. Rows
    | whose id is in the captured baseline survive; everything a test created is
    | deleted. Use this instead of protecting a table outright whenever a table
    | mixes baseline rows with test-created ones.
    |
    | `term_relationships` is the one people miss: it carries each post's
    | language, translation group, product category and product type, so
    | truncating it strips the baseline of everything but the rows themselves.
    |
    | Capture the baseline as the final step of your build with
    | `wp acorn acorn-testing:capture-baseline`.
    |
    */
    'scoped_tables' => [
        'posts' => 'ID',
        'postmeta' => 'post_id',
        'users' => 'ID',
        'usermeta' => 'user_id',
        'term_relationships' => 'object_id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Baseline option name
    |--------------------------------------------------------------------------
    |
    | WordPress option holding the captured baseline ids. Lives in the options
    | table, which is protected, so it survives every reset.
    |
    */
    'baseline_option' => 'acorn_testing_baseline_ids',
];
