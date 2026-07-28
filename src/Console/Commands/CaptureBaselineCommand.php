<?php

declare(strict_types=1);

namespace Bambamboole\AcornTesting\Console\Commands;

use Bambamboole\AcornTesting\Testing\TestingConfig;
use Illuminate\Console\Command;

/**
 * Records which rows belong to the seeded baseline so per-test isolation keeps
 * them and deletes everything a test created.
 *
 * Run last when building the test database — the snapshot has to cover the
 * finished world. The ids live in a WordPress option because the options table
 * is protected from isolation, so the list survives the whole run.
 */
class CaptureBaselineCommand extends Command
{
    protected $signature = 'acorn-testing:capture-baseline';

    protected $description = 'Snapshot the seeded post and user ids that per-test isolation must preserve.';

    public function handle(): int
    {
        global $wpdb;

        $posts = array_map('intval', (array) $wpdb->get_col("SELECT ID FROM {$wpdb->posts}"));
        $users = array_map('intval', (array) $wpdb->get_col("SELECT ID FROM {$wpdb->users}"));

        // autoload=false — read only during tests, and it grows with the baseline.
        \update_option(TestingConfig::baselineOption(), ['posts' => $posts, 'users' => $users], false);

        $this->info(sprintf(
            '[acorn-testing] baseline captured: %d post(s), %d user(s)',
            count($posts),
            count($users),
        ));

        return self::SUCCESS;
    }
}
