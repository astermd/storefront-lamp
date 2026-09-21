<?php

declare(strict_types=1);

/**
 * `orders.settlement`: whether the money on this row moved, or is only held.
 *
 * A column rather than a new value in `orders.status`, because the two answer
 * different questions and conflating them would lose one of them. `status`
 * holds the placement's own state — `placed`, `declined`, `pending_action` —
 * and an authorized order is genuinely *placed*: the order exists at the
 * provider, the buyer has a receipt, the EMR was told. What is outstanding is
 * the debit. Spending a fourth `status` value on it would make every existing
 * query that reads `status = 'placed'` silently stop counting authorized
 * orders, including the two reconciliation sweeps.
 *
 * **`DEFAULT 'capture'`, and the default is the load-bearing part.** Every row
 * written before this migration was charged in full at checkout — that was the
 * only behaviour the storefront had — so backfilling them as `capture` is not a
 * guess, it is the recorded truth restated. A nullable column would leave a
 * reconciler unable to tell "charged, before settlement existed" from "we do
 * not know", and only one of those is true of the existing rows.
 *
 * **No index.** The query this column exists for — find the authorized orders
 * still awaiting capture — is not on a schedule and is not this storefront's
 * to run: the event that decides when an authorization settles lives outside
 * it, and `bin/console payment:capture` is handed the reference rather than
 * searching for one. An index for a query nobody makes is a write cost on
 * every order for nothing. If a sweep is ever added here, it should bring its
 * own index and a docblock saying what reads it, the way 0004 and 0005 do.
 */
return function (\PDO $pdo, string $driver): void {
    // Added tolerantly, for the reason 0002 and 0003 give: there is no portable
    // "ADD COLUMN IF NOT EXISTS" across the three supported engines, and
    // re-running a migration must be safe.
    try {
        $pdo->exec("ALTER TABLE orders ADD COLUMN settlement VARCHAR(16) NOT NULL DEFAULT 'capture'");
    } catch (\PDOException) {
        // Already present.
    }
};
