<?php

declare(strict_types=1);

/**
 * Checkout: the placed order and its lines, the consents recorded against it,
 * the idempotency claim that stops a double submit, and the rate-limit
 * counters.
 *
 * `orders.session_uuid` becomes nullable here. An analytics-off deployment --
 * or one whose EMR session could not be minted -- must still be able to take
 * an order, and inventing an identifier to satisfy a NOT NULL constraint is
 * exactly the synthetic identifier the spec forbids.
 *
 * `checkout_attempts.idempotency_key` is UNIQUE, and that uniqueness is the
 * whole mechanism: the claim is a conditional insert, so two concurrent
 * submits race on the index and exactly one wins. The payment provider offers
 * no idempotency of its own -- an identical payload posted twice creates two
 * orders and charges both -- so this index is what stands between a
 * double-click and a double charge.
 *
 * `checkout_attempts.state` carries three values rather than two, and the
 * legacy `in_flight` rows are promoted here. That promotion has only one safe
 * direction: an `in_flight` row cannot say whether its request stopped short
 * of the provider or died talking to it, and the two new states differ in
 * exactly that -- `claimed` may be taken over by a retry, `sent` may not. So
 * the unknown case becomes `sent`. Being wrong that way costs a buyer a
 * "please wait" they did not need; being wrong the other way re-posts a
 * payload to a gateway that creates and charges a second order.
 *
 * Nothing here stores a card. `orders.card_last_four` is the only
 * card-derived column in the schema, and four digits are what a receipt and a
 * support call need; the number itself never reaches a table.
 */
return function (\PDO $pdo, string $driver): void {
    $id = \AsterMD\Storefront\Database\ConnectionFactory::autoIncrementPrimaryKey($driver);
    $longText = $driver === 'mysql' ? 'MEDIUMTEXT' : 'TEXT';

    // Done first, and before any table that references `orders`, because on
    // SQLite it is a rebuild rather than an ALTER: SQLite can add a column but
    // cannot relax a NOT NULL in place. Running it first means the table it
    // rebuilds is still exactly 0001's shape, so the copied column list below
    // is complete by construction, and no child table's foreign key has been
    // declared against `orders` yet for the rename to have to chase.
    if ($driver === 'sqlite') {
        $columns = $pdo->query('PRAGMA table_info(orders)')->fetchAll();
        $stillRequired = false;
        foreach ($columns as $column) {
            if (($column['name'] ?? null) === 'session_uuid' && (int) ($column['notnull'] ?? 0) === 1) {
                $stillRequired = true;
            }
        }

        // Skipped once it has already run, which is what makes re-applying
        // this migration on a half-migrated database safe.
        if ($stillRequired) {
            $pdo->exec('PRAGMA foreign_keys = OFF');
            $pdo->exec("CREATE TABLE orders_rebuilt (
                {$id},
                session_uuid VARCHAR(64) NULL,
                provider_reference VARCHAR(191) NOT NULL,
                anchor_slug VARCHAR(191) NOT NULL,
                amount_cents INTEGER NOT NULL,
                currency VARCHAR(3) NOT NULL,
                status VARCHAR(32) NOT NULL,
                treatment_reference VARCHAR(191) NULL,
                verification_status VARCHAR(32) NULL,
                verification_at VARCHAR(32) NULL,
                created_at VARCHAR(32) NOT NULL,
                updated_at VARCHAR(32) NOT NULL,
                FOREIGN KEY (session_uuid) REFERENCES sessions(session_uuid)
            )");
            $pdo->exec('INSERT INTO orders_rebuilt (id, session_uuid, provider_reference, anchor_slug, amount_cents, currency, status, treatment_reference, verification_status, verification_at, created_at, updated_at)
                SELECT id, session_uuid, provider_reference, anchor_slug, amount_cents, currency, status, treatment_reference, verification_status, verification_at, created_at, updated_at FROM orders');
            $pdo->exec('DROP TABLE orders');
            $pdo->exec('ALTER TABLE orders_rebuilt RENAME TO orders');
            // The table's indexes went with it.
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_orders_session ON orders (session_uuid)');
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    } elseif ($driver === 'mysql') {
        $pdo->exec('ALTER TABLE orders MODIFY session_uuid VARCHAR(64) NULL');
    } else {
        $pdo->exec('ALTER TABLE orders ALTER COLUMN session_uuid DROP NOT NULL');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS order_lines (
        {$id},
        order_id INTEGER NOT NULL,
        slug VARCHAR(191) NOT NULL,
        name VARCHAR(191) NOT NULL,
        kind VARCHAR(32) NOT NULL,
        provider_offer VARCHAR(64) NULL,
        provider_item VARCHAR(64) NULL,
        unit_price_cents INTEGER NOT NULL,
        quantity INTEGER NOT NULL,
        sent_to_provider INTEGER NOT NULL DEFAULT 1,
        FOREIGN KEY (order_id) REFERENCES orders(id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS order_consents (
        {$id},
        order_id INTEGER NOT NULL,
        consent_key VARCHAR(64) NOT NULL,
        granted INTEGER NOT NULL,
        copy_version VARCHAR(32) NOT NULL,
        copy_shown {$longText} NOT NULL,
        recorded_at VARCHAR(32) NOT NULL,
        FOREIGN KEY (order_id) REFERENCES orders(id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS checkout_attempts (
        {$id},
        idempotency_key VARCHAR(191) NOT NULL,
        session_key VARCHAR(191) NOT NULL,
        state VARCHAR(32) NOT NULL,
        outcome {$longText} NULL,
        created_at VARCHAR(32) NOT NULL,
        completed_at VARCHAR(32) NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
        {$id},
        bucket VARCHAR(64) NOT NULL,
        identity VARCHAR(191) NOT NULL,
        hits INTEGER NOT NULL,
        window_start INTEGER NOT NULL
    )");

    // Idempotent by construction: the legacy value is written by nothing any
    // more, so a second run matches no rows. See the file docblock for why the
    // unknown case lands on `sent` rather than on `claimed`.
    $pdo->prepare('UPDATE checkout_attempts SET state = ? WHERE state = ?')->execute([
        \AsterMD\Storefront\Repository\CheckoutAttemptRepository::STATE_SENT,
        'in_flight',
    ]);

    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_checkout_attempts_key ON checkout_attempts (idempotency_key)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_rate_limits_bucket_identity ON rate_limits (bucket, identity)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_order_lines_order ON order_lines (order_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_order_consents_order ON order_consents (order_id)');

    // The columns this migration adds to the existing orders table. Each is added
    // separately and tolerantly, because 0001 has already run on any
    // environment that is not a fresh clone.
    foreach ([
        'buyer_email VARCHAR(191) NULL',
        'buyer_name VARCHAR(191) NULL',
        'buyer_territory VARCHAR(8) NULL',
        'discount_cents INTEGER NOT NULL DEFAULT 0',
        'promotion_code VARCHAR(64) NULL',
        'payment_method VARCHAR(32) NULL',
        'card_last_four VARCHAR(4) NULL',
        'idempotency_key VARCHAR(191) NULL',
        'provider_category VARCHAR(32) NULL',
        'placed_at VARCHAR(32) NULL',
    ] as $column) {
        try {
            $pdo->exec('ALTER TABLE orders ADD COLUMN ' . $column);
        } catch (\PDOException) {
            // Already present: re-running a migration must be safe, and no
            // portable "ADD COLUMN IF NOT EXISTS" exists across all three
            // supported engines.
        }
    }
};
