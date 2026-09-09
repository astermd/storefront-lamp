<?php

declare(strict_types=1);

/**
 * Promotes the attempt state one earlier release could not, and gives an order
 * somewhere to say it was an upsell.
 *
 * The promotion is here rather than in the migration that introduced the state
 * because migrations are recorded by filename: every environment that holds
 * rows in the old state had already recorded that file as applied, so the
 * statement inside it could never run. A correction to an applied migration is
 * a statement nobody executes.
 *
 * The direction of the promotion is the one the earlier file already argued
 * for and is restated here because it is the whole reason the statement is
 * worth rescuing. A row in the legacy state cannot say whether its request
 * stopped short of the provider or died talking to it, and the two current
 * states differ in exactly that: `claimed` may be taken over by a retry,
 * `sent` may not. So the unknown case becomes `sent`. Being wrong that way
 * costs a buyer a "please wait" they did not need; being wrong the other way
 * re-posts a payload to a gateway with no idempotency of its own, which
 * creates and charges a second order.
 *
 * `orders.is_upsell` exists because `[16.12]` records every upsell order --
 * placed or declined -- locally, and a reconciliation sweep, an operator and
 * the receipt's own arithmetic all need to tell the one order the buyer
 * consented to on a form from the ones they accepted with a single click.
 * `INTEGER NOT NULL DEFAULT 0` for the same reason `discount_cents` and
 * `sent_to_provider` are: the three engines disagree about booleans and agree
 * about integers, and the default is what keeps every order already in the
 * table -- and every one the unchanged checkout path writes next -- correct
 * without a backfill.
 */
return function (\PDO $pdo, string $driver): void {
    // Idempotent by construction: the legacy value is written by nothing any
    // more, so a second run matches no rows.
    $pdo->prepare('UPDATE checkout_attempts SET state = ? WHERE state = ?')->execute([
        \AsterMD\Storefront\Repository\CheckoutAttemptRepository::STATE_SENT,
        'in_flight',
    ]);

    // Added tolerantly, for the reason the previous migration's column loop
    // gives: no portable "ADD COLUMN IF NOT EXISTS" exists across all three
    // supported engines, and re-running a migration must be safe.
    try {
        $pdo->exec('ALTER TABLE orders ADD COLUMN is_upsell INTEGER NOT NULL DEFAULT 0');
    } catch (\PDOException) {
        // Already present.
    }
};
