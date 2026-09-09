<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Repository;

/**
 * The `rate_limits` table: a fixed-window counter per `(bucket, identity)`.
 *
 * Two integers per key and no history, which is all a flood guard needs — but
 * unlike the session-backed counter this replaces for checkout, the identity
 * is not something the caller can discard. Dropping a cookie reset the old
 * one; nothing a browser does resets a row keyed on the client address
 * (`[13.8]`, `[29.23]`).
 *
 * The window is fixed rather than sliding: a sliding window needs the
 * timestamps of individual hits, and keeping a durable list of when each
 * address touched checkout is a log of visitor behaviour this storefront has
 * no reason to hold. The cost is the usual one — an address may spend its
 * whole allowance at the end of one window and again at the start of the next
 * — and it is the right trade for a guard whose job is to stop a stuck retry
 * loop rather than a determined attacker.
 *
 * Callers pass `$now` rather than this class reading the clock, so the caller
 * that owns the policy also owns the time it is judged against, and so the
 * behaviour is testable without waiting.
 *
 * SQL is portable, with the single exception noted on {@see self::hit()}. The
 * connection arrives as a closure and is opened on the first query rather than
 * in the constructor, for the same reason {@see SessionRepository}'s is.
 */
final class RateLimitRepository
{
    private ?\PDO $connection = null;

    /**
     * @param \Closure(): \PDO $pdo    opened on the first query, not on construction
     * @param string           $driver `sqlite`, `mysql` or `pgsql`; passed in rather than read
     *                                 from the connection so choosing the conflict clause does
     *                                 not itself force the connection open
     */
    public function __construct(private readonly \Closure $pdo, private readonly string $driver)
    {
    }

    /** Memoised so one request opens at most one connection through this repository. */
    private function pdo(): \PDO
    {
        return $this->connection ??= ($this->pdo)();
    }

    /**
     * Counts one hit against `(bucket, identity)` and returns the running
     * total for the window it falls in.
     *
     * Three statements rather than one because no portable upsert expresses
     * "increment, unless the window has moved on, in which case start again":
     *
     * 1. A conditional insert, which wins when this key has never been seen.
     *    MySQL has no `ON CONFLICT` and SQLite and Postgres have no
     *    `ON DUPLICATE KEY UPDATE`, so this is the one line that differs by
     *    engine.
     * 2. A conditional update that rolls the window over. It fires when the
     *    window has expired **or when `$now` is behind the stored start** —
     *    a clock that moved backwards, from an NTP correction or a rollback,
     *    would otherwise leave an address locked out until real time caught
     *    up with a window that had not happened yet.
     * 3. Otherwise, a plain increment inside the current window.
     *
     * Two requests racing on the same key can both read step 3's count as the
     * same number, which undercounts by one. That is accepted: this is a flood
     * guard, and taking a row lock on every checkout submission to make a
     * generous limit exact would cost more than the error does.
     *
     * @return int hits recorded in the current window, this one included
     */
    public function hit(string $bucket, string $identity, int $windowSeconds, int $now): int
    {
        $insert = 'INSERT INTO rate_limits (bucket, identity, hits, window_start) VALUES (?, ?, 1, ?)';

        // The no-op assignment rather than `INSERT IGNORE`, matching
        // {@see SessionRepository::insert()} and
        // {@see CheckoutAttemptRepository::claim()}: a suppressed insert error
        // here reads as "a row already exists" and silently forfeits a limit.
        $insert .= $this->driver === 'mysql'
            ? ' ON DUPLICATE KEY UPDATE bucket = bucket'
            : ' ON CONFLICT (bucket, identity) DO NOTHING';

        $statement = $this->pdo()->prepare($insert);
        $statement->execute([$bucket, $identity, $now]);
        if ($statement->rowCount() === 1) {
            return 1;
        }

        $rolled = $this->pdo()->prepare(
            'UPDATE rate_limits SET hits = 1, window_start = ?
             WHERE bucket = ? AND identity = ? AND (window_start <= ? OR window_start > ?)',
        );
        $rolled->execute([$now, $bucket, $identity, $now - $windowSeconds, $now]);
        if ($rolled->rowCount() > 0) {
            return 1;
        }

        $this->pdo()
            ->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ? AND identity = ?')
            ->execute([$bucket, $identity]);

        $read = $this->pdo()->prepare('SELECT hits FROM rate_limits WHERE bucket = ? AND identity = ?');
        $read->execute([$bucket, $identity]);

        return (int) $read->fetchColumn();
    }

    /**
     * Deletes counters whose window closed before `$before`, and returns how
     * many went.
     *
     * Nothing has ever deleted from this table: {@see self::hit()} reuses a row
     * in place forever, so every `(bucket, identity)` ever seen is still here —
     * one row per client address that has touched a guarded path, kept for no
     * reason once its window has passed. That is both unbounded growth and a
     * durable list of addresses the storefront has no use for.
     *
     * **Deleting a closed window can only ever be a no-op for the guard, and
     * that is the property to hold on to.** A row whose `window_start` is older
     * than the bucket's window is one {@see self::hit()} would reset to a single
     * hit on the very next request, so its stored count already grants nothing.
     * Deleting a row *inside* its window is a different matter — it hands that
     * identity a fresh allowance it has not earned, which is the flood guard
     * failing open — so the caller is responsible for a cutoff comfortably
     * beyond the longest window it has configured. The delete does not read the
     * per-bucket window itself, because this table does not know it: the window
     * is policy the caller passes to `hit()`, and inventing a second copy of it
     * here is how the two would come to disagree.
     *
     * Racing a live request is safe in the same sense. A row deleted the instant
     * before a hit is re-inserted by the conditional insert at the top of
     * `hit()` and the caller counts one, which is exactly what the rollover it
     * displaced would have returned.
     *
     * @param  int $before unix seconds; `window_start` is an integer here, not
     *                     the ISO-8601 string the other sweepable table stamps
     * @return int rows deleted
     */
    public function expire(int $before): int
    {
        $statement = $this->pdo()->prepare('DELETE FROM rate_limits WHERE window_start < ?');
        $statement->execute([$before]);

        return $statement->rowCount();
    }

    /**
     * How many rows {@see self::expire()} would take, without taking them —
     * the dry run's half, sharing that method's predicate so the two cannot
     * describe different sweeps.
     */
    public function countExpirable(int $before): int
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM rate_limits WHERE window_start < ?');
        $statement->execute([$before]);

        return (int) $statement->fetchColumn();
    }
}
