<?php

declare(strict_types=1);

/**
 * Initial schema: analytics-session mirror + durable journey state, orders
 * (integer-cents amounts), and the append-only local event audit trail.
 * Per-driver differences are confined to the $id/$text helpers below.
 *
 * Two MySQL/InnoDB portability caveats baked into this file:
 * - Table-level FOREIGN KEY required: MySQL/InnoDB silently ignores an
 *   inline column-level `REFERENCES` clause — it parses but never creates
 *   the constraint — so the orders->sessions link is declared as a
 *   table-level `FOREIGN KEY (...) REFERENCES ...` clause instead, which
 *   works identically on sqlite/mysql/pgsql.
 * - `CREATE INDEX IF NOT EXISTS` requires MySQL >= 8.0.29; older MySQL
 *   versions do not support the `IF NOT EXISTS` clause on `CREATE INDEX`.
 */
return function (\PDO $pdo, string $driver): void {
    $id = \AsterMD\Storefront\Database\ConnectionFactory::autoIncrementPrimaryKey($driver);
    $longText = $driver === 'mysql' ? 'MEDIUMTEXT' : 'TEXT';

    $pdo->exec("CREATE TABLE IF NOT EXISTS sessions (
        session_uuid VARCHAR(64) PRIMARY KEY,
        opportunity_id VARCHAR(64) NULL,
        journey_state {$longText} NOT NULL,
        attribution {$longText} NULL,
        created_at VARCHAR(32) NOT NULL,
        updated_at VARCHAR(32) NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        {$id},
        session_uuid VARCHAR(64) NOT NULL,
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS events (
        {$id},
        session_uuid VARCHAR(64) NOT NULL,
        name VARCHAR(191) NOT NULL,
        payload {$longText} NULL,
        created_at VARCHAR(32) NOT NULL
    )");

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_orders_session ON orders (session_uuid)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_session ON events (session_uuid)');
};
