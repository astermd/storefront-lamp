<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Repository;

/**
 * The `orders`, `order_lines` and `order_consents` tables: the storefront's
 * own record of what was bought, for how much, and what the buyer agreed to.
 *
 * This is deliberately a second copy of something the payment provider and the
 * EMR also hold. It is the only copy the storefront can read without a network
 * call, and it is what makes "the provider charged someone but the EMR never
 * heard about it" a query rather than an investigation (`[18.1]`):
 * `treatment_reference` stays null on exactly those orders.
 *
 * Three things about the shape are load-bearing:
 *
 * - **Amounts are integer cents**, as everywhere else in this codebase. No
 *   column here holds a decimal and nothing here converts one.
 * - **`session_uuid` is nullable.** An order taken with analytics off, or on a
 *   journey whose EMR session could not be minted, is still an order. Making
 *   the column required would have forced a synthetic identifier, which
 *   `[20.8]` forbids precisely because an order carrying one can never be
 *   reconciled back to anything.
 * - **No card ever reaches this class.** `card_last_four` is the only
 *   card-derived value it accepts, and four digits are what a receipt and a
 *   support call need (`[15.8]`). A caller that hands `insert()` a full number
 *   under some other key would write it, so callers hand it the four digits
 *   and nothing else.
 *
 * SQL is kept portable: this file must work unchanged on SQLite, MySQL and
 * Postgres, with engine differences confined to the connection factory and the
 * migrations. The connection arrives as a closure and is opened on the first
 * query rather than in the constructor, for the same reason
 * {@see SessionRepository}'s is: constructing a repository must not be the act
 * that connects, or everything assembled around one inherits the database as a
 * hard dependency.
 */
final class OrderRepository
{
    private ?\PDO $connection = null;

    /** @param \Closure(): \PDO $pdo opened on the first query, not on construction */
    public function __construct(private readonly \Closure $pdo)
    {
    }

    /** Memoised so one request opens at most one connection through this repository. */
    private function pdo(): \PDO
    {
        return $this->connection ??= ($this->pdo)();
    }

    /**
     * Writes one order together with its lines and its consents, and returns
     * the local row id.
     *
     * All three writes happen in a single transaction because a half-written
     * order is worse than no local record at all: the money has already moved
     * by the time this is called, and an order row whose lines are missing
     * would read as a charge for nothing. The consents are inside the same
     * boundary for the same reason — `[26.6]` wants the wording that was shown
     * kept next to what it was shown for.
     *
     * A line the catalog could not map to a provider offer is stored with
     * `sent_to_provider = 0` rather than dropped, so the local record still
     * says what the buyer saw even though the provider was never told
     * (`[13.19]`).
     *
     * `is_upsell` defaults to false rather than being required, because the
     * checkout order is the ordinary case and an order the caller says nothing
     * about is that one. An upsell order is recorded here like any other
     * (`[16.12]`) and the flag is the only thing separating them: `[16.10]`'s
     * running total is computed from these rows at completion rather than
     * accumulated anywhere, so an upsell that failed to be marked would be
     * counted as part of the total the buyer agreed to on a form.
     *
     * @param array{session_uuid?: ?string, provider_reference: string, anchor_slug: string, amount_cents: int, currency: string, status: string, buyer_email?: ?string, buyer_name?: ?string, buyer_territory?: ?string, discount_cents?: int, promotion_code?: ?string, payment_method?: ?string, card_last_four?: ?string, idempotency_key?: ?string, provider_category?: ?string, placed_at?: ?string, is_upsell?: bool} $order
     * @param list<array{slug: string, name: string, kind?: string, provider_offer?: ?string, provider_item?: ?string, unit_price_cents: int, quantity: int, sent_to_provider?: bool}>                                                                                                                                            $lines
     * @param list<array{key: string, granted: bool, copy_version: string, copy_shown: string, at: string}>                                                                                                                                                                                                                     $consents in {@see \AsterMD\Storefront\Checkout\ConsentRecord::toArray()}'s shape
     */
    public function insert(array $order, array $lines, array $consents): int
    {
        $now = gmdate('c');
        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $pdo->prepare(
                'INSERT INTO orders (
                    session_uuid, provider_reference, anchor_slug, amount_cents, currency, status,
                    buyer_email, buyer_name, buyer_territory, discount_cents, promotion_code,
                    payment_method, card_last_four, idempotency_key, provider_category, placed_at,
                    is_upsell, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            )->execute([
                $order['session_uuid'] ?? null,
                (string) $order['provider_reference'],
                (string) $order['anchor_slug'],
                (int) $order['amount_cents'],
                (string) $order['currency'],
                (string) $order['status'],
                $order['buyer_email'] ?? null,
                $order['buyer_name'] ?? null,
                $order['buyer_territory'] ?? null,
                (int) ($order['discount_cents'] ?? 0),
                $order['promotion_code'] ?? null,
                $order['payment_method'] ?? null,
                $order['card_last_four'] ?? null,
                $order['idempotency_key'] ?? null,
                $order['provider_category'] ?? null,
                $order['placed_at'] ?? $now,
                ($order['is_upsell'] ?? false) === true ? 1 : 0,
                $now,
                $now,
            ]);

            // Read immediately, before the line and consent inserts run: on
            // Postgres this resolves to `lastval()`, which reports whichever
            // sequence the connection last advanced, so asking afterwards
            // would hand back an `order_consents` id.
            $id = (int) $pdo->lastInsertId();

            $lineStatement = $pdo->prepare(
                'INSERT INTO order_lines (order_id, slug, name, kind, provider_offer, provider_item, unit_price_cents, quantity, sent_to_provider)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            );
            foreach ($lines as $line) {
                $lineStatement->execute([
                    $id,
                    (string) $line['slug'],
                    (string) $line['name'],
                    (string) ($line['kind'] ?? 'otc'),
                    $line['provider_offer'] ?? null,
                    $line['provider_item'] ?? null,
                    (int) $line['unit_price_cents'],
                    (int) $line['quantity'],
                    ($line['sent_to_provider'] ?? true) === true ? 1 : 0,
                ]);
            }

            $consentStatement = $pdo->prepare(
                'INSERT INTO order_consents (order_id, consent_key, granted, copy_version, copy_shown, recorded_at)
                 VALUES (?, ?, ?, ?, ?, ?)',
            );
            foreach ($consents as $consent) {
                $consentStatement->execute([
                    $id,
                    (string) $consent['key'],
                    ($consent['granted'] ?? false) === true ? 1 : 0,
                    (string) ($consent['copy_version'] ?? ''),
                    (string) ($consent['copy_shown'] ?? ''),
                    (string) ($consent['at'] ?? $now),
                ]);
            }

            $pdo->commit();

            return $id;
        } catch (\Throwable $error) {
            $pdo->rollBack();

            throw $error;
        }
    }

    /**
     * The whole order — header, lines and consents — as the provider's
     * reference names it.
     *
     * Keyed on the provider reference rather than the local id because that is
     * the identifier every other party in this system knows: it is what the
     * buyer sees on the receipt, what appears in the provider's dashboard, and
     * what a support call arrives quoting.
     *
     * @return array{id: int, session_uuid: ?string, provider_reference: string, anchor_slug: string, amount_cents: int, currency: string, status: string, treatment_reference: ?string, buyer_email: ?string, buyer_name: ?string, buyer_territory: ?string, discount_cents: int, promotion_code: ?string, payment_method: ?string, card_last_four: ?string, idempotency_key: ?string, provider_category: ?string, placed_at: ?string, is_upsell: bool, created_at: string, updated_at: string, lines: list<array{slug: string, name: string, kind: string, provider_offer: ?string, provider_item: ?string, unit_price_cents: int, quantity: int, sent_to_provider: bool}>, consents: list<array{key: string, granted: bool, copy_version: string, copy_shown: string, at: string}>}|null
     */
    public function findByReference(string $reference): ?array
    {
        $statement = $this->pdo()->prepare('SELECT * FROM orders WHERE provider_reference = ?');
        $statement->execute([$reference]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        $id = (int) $row['id'];

        return [
            'id' => $id,
            'session_uuid' => self::nullableString($row['session_uuid'] ?? null),
            'provider_reference' => (string) $row['provider_reference'],
            'anchor_slug' => (string) $row['anchor_slug'],
            'amount_cents' => (int) $row['amount_cents'],
            'currency' => (string) $row['currency'],
            'status' => (string) $row['status'],
            'treatment_reference' => self::nullableString($row['treatment_reference'] ?? null),
            'buyer_email' => self::nullableString($row['buyer_email'] ?? null),
            'buyer_name' => self::nullableString($row['buyer_name'] ?? null),
            'buyer_territory' => self::nullableString($row['buyer_territory'] ?? null),
            'discount_cents' => (int) ($row['discount_cents'] ?? 0),
            'promotion_code' => self::nullableString($row['promotion_code'] ?? null),
            'payment_method' => self::nullableString($row['payment_method'] ?? null),
            'card_last_four' => self::nullableString($row['card_last_four'] ?? null),
            'idempotency_key' => self::nullableString($row['idempotency_key'] ?? null),
            'provider_category' => self::nullableString($row['provider_category'] ?? null),
            'placed_at' => self::nullableString($row['placed_at'] ?? null),
            'is_upsell' => (int) ($row['is_upsell'] ?? 0) === 1,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'lines' => $this->linesFor($id),
            'consents' => $this->consentsFor($id),
        ];
    }

    /**
     * Records the EMR treatment reference a successful post-placement sync
     * returned.
     *
     * Its own method, and a null column until it is called, because that null
     * is the reconciliation signal: an order the provider charged but the EMR
     * never learned of is exactly the set of rows this column is still null on
     * (`[18.1]`). Scoped to one id so a sweep over several orders cannot
     * stamp a reference onto the wrong one.
     */
    public function markTreatmentSynced(int $id, string $reference): void
    {
        $this->pdo()
            ->prepare('UPDATE orders SET treatment_reference = ?, updated_at = ? WHERE id = ?')
            ->execute([$reference, gmdate('c'), $id]);
    }

    /**
     * The orders the EMR was never told about, oldest first, bounded.
     *
     * This is the query the whole reconciliation predicate was reserved for
     * (`[21.8]`, `[18.1]`): `treatment_reference IS NULL` means "never told",
     * because {@see self::markTreatmentSynced()} is the only writer of that
     * column and it runs only after a sync the EMR accepted.
     *
     * **`$placedBefore` is a grace window, not a filter for tidiness.** An
     * order placed seconds ago has not failed to sync — the request that
     * placed it reports it in the same breath, and a sweep running against
     * that row would race a call already in flight and re-send a treatment for
     * an order nobody has finished recording. Callers pass a timestamp far
     * enough in the past that the checkout path has demonstrably finished.
     *
     * The comparison is a string one, and that is sound rather than lucky:
     * every writer of these columns uses `gmdate('c')`, a fixed-width UTC
     * format that sorts lexicographically in the same order it sorts
     * chronologically. Introducing a second timestamp format anywhere would
     * break this query silently, which is why the format is stated here.
     *
     * **`placed_at` falls back to `created_at`.** That column arrived in 0002
     * and is nullable, so a row written before it existed has none. Skipping
     * such a row would make it invisible to every sweep while
     * {@see self::countUnsynced()} went on counting it — an operator watching a
     * figure that never moves, with nothing anywhere saying why. `created_at`
     * is NOT NULL, is written by the same `gmdate('c')` call on the same
     * insert, and is the same instant as `placed_at` on every row this
     * storefront has written. The fallback is applied to the returned
     * `placed_at` as well as to the filter, so a caller measuring how long an
     * order has been outstanding gets an answer on every row it is handed
     * rather than on most of them.
     *
     * **`status` is returned rather than filtered on.** Only a charged order
     * belongs in the EMR, and today only a charged order is ever written here
     * — but a `WHERE status = 'placed'` would mean a deployment whose
     * placement vocabulary differed at all had a reconciliation sweep that
     * silently found nothing, and silence is the exact failure this sweep
     * exists to end. The caller decides what it is willing to send and counts
     * what it refused.
     *
     * `LIMIT` is spelled inline from a clamped integer rather than bound as a
     * parameter: the three engines this file must run on agree on the literal
     * and disagree on the bound form once emulated prepares are off. A limit
     * of zero returns nothing, so a misconfigured batch size cannot fall
     * through to an unbounded scan on a schedule.
     *
     * @param string $placedBefore an ISO-8601 UTC instant from {@see gmdate()}'s `c` format
     * @param int    $limit        the most rows one sweep may take
     *
     * @return list<array{id: int, provider_reference: string, session_uuid: ?string, status: string, placed_at: ?string}>
     */
    public function findUnsyncedPlacedBefore(string $placedBefore, int $limit): array
    {
        $limit = max(0, $limit);

        if ($limit === 0) {
            return [];
        }

        $statement = $this->pdo()->prepare(
            'SELECT id, provider_reference, session_uuid, status, COALESCE(placed_at, created_at) AS placed_at
             FROM orders
             WHERE treatment_reference IS NULL AND COALESCE(placed_at, created_at) < ?
             ORDER BY COALESCE(placed_at, created_at) ASC, id ASC
             LIMIT ' . $limit,
        );
        $statement->execute([$placedBefore]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'provider_reference' => (string) $row['provider_reference'],
            'session_uuid' => self::nullableString($row['session_uuid'] ?? null),
            'status' => (string) $row['status'],
            'placed_at' => self::nullableString($row['placed_at'] ?? null),
        ], $statement->fetchAll());
    }

    /**
     * How many orders the EMR has never been told about, at all.
     *
     * `[20.12]` monitors this figure rather than the sweep's own workload,
     * so it deliberately ignores the grace window: an order placed a second
     * ago is genuinely not yet in the EMR, and a count that hid it would read
     * as zero on exactly the deployment where the sync had just stopped
     * working.
     */
    public function countUnsynced(): int
    {
        return $this->count('SELECT COUNT(*) FROM orders WHERE treatment_reference IS NULL');
    }

    /**
     * The reconciliation backlog: never told, and old enough that the
     * checkout path has had its chance.
     *
     * Called twice with different cutoffs. With the grace window it is the
     * work a sweep has to do; with a much older cutoff it is `[21.8]`'s
     * repeat-failure figure, because a row still null long after placement has
     * survived every attempt made since — the one on the money path, the one
     * at the receipt, and every sweep in between.
     *
     * **Rows with no session are excluded, deliberately.** They are not
     * backlog: no retry can ever drain them, because the EMR keys a treatment
     * on the session uuid and there is none. Counting them here would make a
     * permanent gap look like a queue, and would make the repeat-failure
     * figure alert on orders that were never attempted rather than on ones
     * that keep failing. {@see self::countUnsyncedWithoutSession()} reports
     * them, so the two figures are disjoint and both are visible.
     */
    public function countUnsyncedPlacedBefore(string $placedBefore): int
    {
        return $this->count(
            'SELECT COUNT(*) FROM orders
             WHERE treatment_reference IS NULL AND session_uuid IS NOT NULL
               AND COALESCE(placed_at, created_at) < ?',
            [$placedBefore],
        );
    }

    /**
     * Never told, and with no analytics session to tell anyone under.
     *
     * These are not backlog and must never be counted as it. The EMR keys a
     * treatment record on the session uuid, so an order recorded without one —
     * analytics off, or a session the EMR would not mint (`[20.1]`, decision
     * 14) — can never be reconciled forward, and no number of retries will
     * change that. `[20.8]` forbids inventing an identifier to close the gap,
     * so the honest thing is to report the count and let an operator decide.
     */
    public function countUnsyncedWithoutSession(): int
    {
        return $this->count('SELECT COUNT(*) FROM orders WHERE treatment_reference IS NULL AND session_uuid IS NULL');
    }

    /** @param list<string> $bindings */
    private function count(string $sql, array $bindings = []): int
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($bindings);

        return (int) $statement->fetchColumn();
    }

    /** @return list<array{slug: string, name: string, kind: string, provider_offer: ?string, provider_item: ?string, unit_price_cents: int, quantity: int, sent_to_provider: bool}> */
    private function linesFor(int $orderId): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT slug, name, kind, provider_offer, provider_item, unit_price_cents, quantity, sent_to_provider
             FROM order_lines WHERE order_id = ? ORDER BY id ASC',
        );
        $statement->execute([$orderId]);

        return array_map(static fn (array $row): array => [
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'kind' => (string) $row['kind'],
            'provider_offer' => self::nullableString($row['provider_offer'] ?? null),
            'provider_item' => self::nullableString($row['provider_item'] ?? null),
            'unit_price_cents' => (int) $row['unit_price_cents'],
            'quantity' => (int) $row['quantity'],
            'sent_to_provider' => (int) $row['sent_to_provider'] === 1,
        ], $statement->fetchAll());
    }

    /** @return list<array{key: string, granted: bool, copy_version: string, copy_shown: string, at: string}> */
    private function consentsFor(int $orderId): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT consent_key, granted, copy_version, copy_shown, recorded_at
             FROM order_consents WHERE order_id = ? ORDER BY id ASC',
        );
        $statement->execute([$orderId]);

        return array_map(static fn (array $row): array => [
            'key' => (string) $row['consent_key'],
            'granted' => (int) $row['granted'] === 1,
            'copy_version' => (string) $row['copy_version'],
            'copy_shown' => (string) $row['copy_shown'],
            'at' => (string) $row['recorded_at'],
        ], $statement->fetchAll());
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
