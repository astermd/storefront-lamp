<?php

declare(strict_types=1);

/**
 * `orders.user_agent`: the browser the buyer placed this order from.
 *
 * The EMR's treatment-sync endpoint requires a `User-Agent` header and the SDK
 * refuses an empty one, so every caller has to have an answer. Two of the three
 * have no request of their own — the receipt batch and the reconciliation
 * sweep, which runs from `bin/console` — and the only moment the buyer's own
 * agent is ever in hand is the request that placed the order. Kept here, a
 * later sync attributes the import to the buyer's device rather than to this
 * server.
 *
 * **Nullable, with no default.** Unlike 0006's `settlement`, there is no
 * recorded truth to backfill: a row written before this column existed was
 * written without a user agent, and null says exactly that. A default would
 * make every historical order claim an agent it never had, and the sync's own
 * fallback — the storefront's identifier — would then never be reached for the
 * rows that need it.
 *
 * **No index.** Nothing queries by user agent; it is read one row at a time, by
 * the provider reference that is already indexed.
 */
return function (\PDO $pdo, string $driver): void {
    // Added tolerantly, for the reason 0002, 0003 and 0006 give: there is no
    // portable "ADD COLUMN IF NOT EXISTS" across the three supported engines,
    // and re-running a migration must be safe.
    try {
        $pdo->exec('ALTER TABLE orders ADD COLUMN user_agent VARCHAR(512) NULL');
    } catch (\PDOException) {
        // Already present.
    }
};
