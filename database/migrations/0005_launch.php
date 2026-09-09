<?php

declare(strict_types=1);

/**
 * The two indexes the retention job and the reverse sweep read on.
 *
 * No columns are added. Everything recorded here has a home already:
 * the local audit trail is the `events` table from 0001, and retention is a
 * question about `created_at`, which every table carries.
 *
 *  - `orders.provider_reference` is what both reconciliation sweeps match on
 *    — the forward sweep to find the local row for an EMR treatment, the
 *    reverse sweep to ask whether a provider order has a local row at all —
 *    and it has been a full scan since 0001. `[21.9a]`'s sweep does one such
 *    lookup per provider order returned in its window, so the scan is not
 *    once per run but once per order.
 *  - `events.created_at` is what `[30.6]`'s expiry deletes on. The audit
 *    trail is the largest table on a busy deployment by a wide margin — one
 *    row per session created, per intake step, per checkout event — so the
 *    one query that walks it on a schedule should not walk all of it.
 *
 * `sessions.created_at` deliberately gets no index: `db:prune` expires
 * sessions on `updated_at`, which 0004 already indexed, because a journey's
 * age for retention purposes is when it last moved and not when it began.
 *
 * The timestamp columns are VARCHAR(32) holding gmdate('c'), fixed-width UTC,
 * so lexicographic order is chronological order and a string comparison is a
 * valid range query. Every writer uses that format; a writer that did not
 * would silently break the range scans in both jobs.
 */
return function (\PDO $pdo, string $driver): void {
    // MySQL earlier than 8.0.29 has no CREATE INDEX IF NOT EXISTS, and 0001
    // carries that caveat. Each statement is wrapped for the reason 0004
    // wraps its own: re-running a migration must be safe and there is no
    // portable guard.
    $indexes = [
        'CREATE INDEX IF NOT EXISTS idx_orders_provider_reference ON orders (provider_reference)',
        'CREATE INDEX IF NOT EXISTS idx_events_created_at ON events (created_at)',
    ];

    foreach ($indexes as $statement) {
        try {
            $pdo->exec($statement);
        } catch (\PDOException) {
            // Already present, or an engine that cannot say "if not exists"
            // and has it. Either way the index the job needs is there.
        }
    }
};
