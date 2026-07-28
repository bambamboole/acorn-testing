<?php

declare(strict_types=1);

namespace Bambamboole\AcornTesting\Testing\Concerns;

use Bambamboole\AcornTesting\Testing\TestingConfig;

/**
 * Per-test database isolation, configured via `protected_tables` and
 * `scoped_tables`.
 *
 * Replaces re-importing the whole dump before every test, which spends a wp-cli
 * subprocess on each one and scales with the size of the baseline.
 */
trait ResetsDatabase
{
    protected function resetDatabase(): void
    {
        global $wpdb;

        $prefix = $wpdb->prefix;
        $protected = self::prefixed(TestingConfig::protectedTables(), $prefix);
        $scoped = [];
        foreach (TestingConfig::scopedTables() as $table => $column) {
            $scoped[$prefix . $table] = $column;
        }

        $baseline = self::baselineIds();

        $wpdb->query('SET FOREIGN_KEY_CHECKS=0');

        foreach ($scoped as $table => $column) {
            $ids = $baseline[self::baselineKeyFor($table, $prefix)] ?? [];
            $csv = $ids === [] ? '0' : implode(',', array_map('intval', $ids));
            $wpdb->query("DELETE FROM `{$table}` WHERE `{$column}` NOT IN ({$csv})");
        }

        foreach (self::nonEmptyTables($protected, array_keys($scoped)) as $table) {
            $wpdb->query("TRUNCATE TABLE `{$table}`");
        }

        $wpdb->query('SET FOREIGN_KEY_CHECKS=1');

        wp_cache_flush();

        $this->afterDatabaseReset();
    }

    /**
     * Hook for cleanup that must happen while the database is freshly reset —
     * a plugin's in-memory caches now point at rows that no longer exist.
     */
    protected function afterDatabaseReset(): void
    {
        //
    }

    /**
     * A WooCommerce baseline leaves most of ~74 tables empty, and TRUNCATE-ing
     * every one of them costs ~390ms per test against ~8ms for this sweep.
     * Deliberately not cached: a table empty during one test is routinely
     * written by the next.
     *
     * @param  list<string>  $protected
     * @param  list<string>  $scoped
     * @return list<string>
     */
    private static function nonEmptyTables(array $protected, array $scoped): array
    {
        global $wpdb;

        $skip = array_merge($protected, $scoped);
        $candidates = array_values(array_diff((array) $wpdb->get_col('SHOW TABLES'), $skip));

        if ($candidates === []) {
            return [];
        }

        $parts = array_map(
            static fn (string $t): string => sprintf("(SELECT '%s' AS t FROM `%s` LIMIT 1)", $t, $t),
            $candidates,
        );

        return array_map('strval', (array) $wpdb->get_col(implode(' UNION ALL ', $parts)));
    }

    /**
     * @return array<string, list<int>>
     */
    private static function baselineIds(): array
    {
        $stored = \get_option(TestingConfig::baselineOption(), []);

        return is_array($stored) ? $stored : [];
    }

    /** Meta tables share their owner's id list, so the key is the object, not the table. */
    private static function baselineKeyFor(string $table, string $prefix): string
    {
        $bare = str_starts_with($table, $prefix) ? substr($table, strlen($prefix)) : $table;

        return match ($bare) {
            'users', 'usermeta' => 'users',
            default => 'posts',
        };
    }

    /**
     * @param  list<string>  $tables
     * @return list<string>
     */
    private static function prefixed(array $tables, string $prefix): array
    {
        return array_map(static fn (string $t): string => $prefix . $t, $tables);
    }
}
