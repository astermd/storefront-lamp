<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Retention;

/**
 * `[30.6]`'s expiry of the two operational tables that live in the database:
 * `sessions` and `events`.
 *
 * **Why this is not a method on each repository.** Every other repository owns
 * one table and answers questions about it. Expiring a session is not a
 * question about `sessions` — it is a question about `sessions` *and* `orders`,
 * because the only thing standing between this sweep and an unreconcilable
 * charge is a join neither repository would naturally carry. Putting the rule
 * where both halves are visible keeps it from being written twice and drifting.
 *
 * **The refusal is the interesting half**, exactly as it is in
 * {@see \AsterMD\Storefront\Console\PruneCommand}. A session row referenced by
 * any `orders` row is never taken, at any age. The configured period is a
 * floor and not an override: a deleted attempt row once re-armed a double
 * charge, and a deleted session is worse, because it is simultaneously a
 * journey that can no longer be resumed and an order that can no longer be
 * attributed to the person who placed it. Age cannot settle that, so age is
 * not asked. Those rows are counted and reported instead —
 * {@see self::countSessionsHeldByOrders()} — because "nothing expired" and
 * "everything that expired was held back by an order" are different states for
 * an operator to come back to.
 *
 * **Events outlive the sessions they describe**, so the session sweep does not
 * cascade into them. `config/retention.php` states why: `[30.7]` makes the
 * trail the record of who read health information, and a breach investigation
 * that starts after the sessions have aged out still needs it.
 *
 * Both cutoffs arrive as ISO-8601 UTC strings rather than day counts, for the
 * reason {@see RetentionPolicy} derives them once: a run must measure every
 * table against the same instant. The columns they are compared against are
 * `VARCHAR(32)` holding `gmdate('c')` — fixed width, so lexicographic order is
 * chronological order and a string range is a valid one. Both are indexed
 * (`sessions.updated_at` by `0004`, `events.created_at` by `0005`).
 *
 * Nothing here is swallowed. Unlike the audit write, a failed *delete* is
 * something the operator scheduling this must hear about, and the command
 * above turns it into a non-zero exit.
 */
final class RetentionSweep
{
    private ?\PDO $connection = null;

    /** @param \Closure(): \PDO $pdo opened on the first query, not on construction */
    public function __construct(private readonly \Closure $pdo)
    {
    }

    /**
     * How many session rows {@see self::expireSessions()} would take.
     *
     * Shares that method's predicate through {@see self::sessionPredicate()},
     * so the dry run and the delete can never describe different sweeps —
     * which on this command is the difference between an operator approving
     * one thing and getting another.
     */
    public function countExpirableSessions(string $movedBefore): int
    {
        return $this->count('SELECT COUNT(*) FROM sessions ' . self::sessionPredicate(), [$movedBefore]);
    }

    /** @return int rows deleted */
    public function expireSessions(string $movedBefore): int
    {
        $statement = $this->pdo()->prepare('DELETE FROM sessions ' . self::sessionPredicate());
        $statement->execute([$movedBefore]);

        return $statement->rowCount();
    }

    /**
     * Sessions past their period that an order holds back.
     *
     * The complement of {@see self::countExpirableSessions()} over the same
     * age window, so the two together account for every aged row and a
     * non-zero answer here explains a zero there.
     */
    public function countSessionsHeldByOrders(string $movedBefore): int
    {
        return $this->count(
            'SELECT COUNT(*) FROM sessions WHERE updated_at < ? AND session_uuid IN (' . self::ORDERED_SESSIONS . ')',
            [$movedBefore],
        );
    }

    public function countExpirableEvents(string $createdBefore): int
    {
        return $this->count('SELECT COUNT(*) FROM events WHERE created_at < ?', [$createdBefore]);
    }

    /** @return int rows deleted */
    public function expireEvents(string $createdBefore): int
    {
        $statement = $this->pdo()->prepare('DELETE FROM events WHERE created_at < ?');
        $statement->execute([$createdBefore]);

        return $statement->rowCount();
    }

    /**
     * The sessions an order points at.
     *
     * `session_uuid IS NOT NULL` is load-bearing rather than tidy. The column
     * became nullable for analytics-off deployments, and a NULL inside a
     * `NOT IN (...)` list makes the whole comparison unknown rather than true
     * — so a single order with no session link would silently turn this sweep
     * into a permanent no-op, and nothing about the output would say so.
     */
    private const string ORDERED_SESSIONS = 'SELECT session_uuid FROM orders WHERE session_uuid IS NOT NULL';

    /** Old enough, and claimed by no order. */
    private static function sessionPredicate(): string
    {
        return 'WHERE updated_at < ? AND session_uuid NOT IN (' . self::ORDERED_SESSIONS . ')';
    }

    /** @param list<string> $bindings */
    private function count(string $sql, array $bindings): int
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($bindings);

        return (int) $statement->fetchColumn();
    }

    /** Memoised so one run opens at most one connection through this sweep. */
    private function pdo(): \PDO
    {
        return $this->connection ??= ($this->pdo)();
    }
}
