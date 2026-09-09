<?php

declare(strict_types=1);

/**
 * The indexes the post-purchase sweeps read on, and nothing else.
 *
 * No columns are added here. `orders.verification_status` and
 * `orders.verification_at` have been in the schema since 0001 and are written
 * by nothing, so `[22.20]`'s record of an identity outcome has somewhere to go
 * already. What is missing is not storage but the ability to find rows without
 * reading the whole table.
 *
 * Each of the post-purchase sweeps asks one of four questions on a schedule, and
 * each one is a full scan today:
 *
 *  - which orders did the EMR never learn about (`[21.8]`)? That is
 *    `treatment_reference IS NULL`, which three docblocks already name as the
 *    reconciliation predicate. The index is deliberately on the column rather
 *    than partial: SQLite and MySQL disagree about partial-index syntax, and
 *    the null side is the side being searched, which both engines index.
 *  - which orders fall in the window a reverse sweep is comparing against
 *    (`[21.9a]`)? That is `placed_at`, added in 0002 and never indexed.
 *  - which journeys have stopped moving (`[21.10]`)? That is
 *    `sessions.updated_at`, which is a genuine "last moved" timestamp rather
 *    than "last seen" because JourneyStore::flush() is fingerprint-gated and
 *    writes nothing when state has not changed.
 *  - what has aged out and can be deleted? `checkout_attempts.created_at`, and
 *    `rate_limits.window_start` -- note that one is a VARCHAR ISO-8601 string
 *    and the other an integer, because the two tables were written for
 *    different jobs. The sweep has to compare them differently, and an index
 *    that pretended they were alike would be worse than none.
 *
 * The timestamp columns are VARCHAR(32) holding gmdate('c'), which sorts
 * lexicographically in the same order it sorts chronologically. That is what
 * makes a string comparison a valid range query here, and it is only true
 * because every writer uses the same fixed-width UTC format.
 */
return function (\PDO $pdo, string $driver): void {
    // MySQL earlier than 8.0.29 has no CREATE INDEX IF NOT EXISTS, and 0001
    // already carries that caveat. Each statement is wrapped for the same
    // reason the column additions in 0002 and 0003 are: re-running a migration
    // must be safe, and there is no portable guard.
    $indexes = [
        'CREATE INDEX IF NOT EXISTS idx_orders_treatment_reference ON orders (treatment_reference)',
        'CREATE INDEX IF NOT EXISTS idx_orders_placed_at ON orders (placed_at)',
        'CREATE INDEX IF NOT EXISTS idx_sessions_updated_at ON sessions (updated_at)',
        'CREATE INDEX IF NOT EXISTS idx_checkout_attempts_created_at ON checkout_attempts (created_at)',
        'CREATE INDEX IF NOT EXISTS idx_rate_limits_window_start ON rate_limits (window_start)',
    ];

    foreach ($indexes as $statement) {
        try {
            $pdo->exec($statement);
        } catch (\PDOException) {
            // Already present, or an engine that cannot say "if not exists"
            // and has it. Either way the index the sweep needs is there.
        }
    }
};
