<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Repository;

use AsterMD\Storefront\Payment\PlacementOutcome;

/**
 * The `checkout_attempts` table: one row per checkout submission, and the
 * thing that stops a second submission becoming a second charge.
 *
 * The payment provider offers no idempotency of its own — an identical payload
 * posted twice creates two orders and charges both, confirmed against the live
 * sandbox — so the guard has to live here.
 *
 * **Three states, and the middle one is the whole point.** A row is `claimed`
 * before anything is sent, becomes `sent` immediately before the provider is
 * called, and `complete` once an outcome is known. Two states could not tell
 * "this request has not talked to the provider yet" from "this request talked
 * to the provider and never came back", and the difference decides whether the
 * key may be taken over: a claim that died before the call demonstrably
 * charged nothing, while one that died after it may correspond to a real
 * order. Reconstructing the second case and re-posting it produced two
 * provider orders and two charges, which is what this distinction now
 * prevents (`[13.37]`).
 *
 * The `outcome` column is a JSON blob rather than a set of columns because it
 * is replayed verbatim to whoever lost the race: its job is to reproduce the
 * first request's answer, not to be queried.
 *
 * Nothing here stores anything about the card. The idempotency key is a
 * digest, and what a caller derives it from is that caller's business — but
 * `[15.8]` means a card must not be among the inputs, because a digest that
 * lands in a durable column is one more place a card has been.
 *
 * **`created_at` is an ownership token, not only a timestamp.** Every write
 * that advances a row — and the delete that gives it back — is conditional on
 * the `created_at` its owner put there, because the key alone cannot say
 * *which* request is asking: a claim old enough to be taken over is taken over
 * by rewriting that column ({@see self::renewClaim()}), so the request that wrote the previous value is
 * no longer the owner and {@see self::markSent()} refuses it. That is what
 * makes two requests unable to be mid-charge against one key at the same time,
 * whether the second arrived by racing the first or by outliving the staleness
 * window while still alive.
 *
 * The two conditional updates are counted with `rowCount()`, which is why both
 * are written so that a win always *changes* something: MySQL reports changed
 * rows rather than matched rows, so an update setting a column to the value it
 * already holds is indistinguishable there from one that matched nothing.
 * `renewClaim()` only ever runs with a `created_at` that differs from the one
 * it is replacing (the row has to be at least a whole staleness window old),
 * and `markSent()` only ever moves a row out of `claimed`.
 *
 * SQL is portable, with the single exception noted on {@see self::claim()}
 * where no portable spelling of "insert unless it is already there" exists.
 * The connection arrives as a closure and is opened on the first query rather
 * than in the constructor, for the same reason {@see SessionRepository}'s is.
 */
final class CheckoutAttemptRepository
{
    /** The key is held by a request that has not contacted the provider yet. */
    public const string STATE_CLAIMED = 'claimed';

    /** The provider has been contacted and the outcome is not known: this row may stand for a real charge. */
    public const string STATE_SENT = 'sent';

    /** The provider answered, and the `outcome` column holds what it said. */
    public const string STATE_COMPLETE = 'complete';

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
     * Claims this idempotency key for the current request, or reports that
     * someone else already has it.
     *
     * A conditional insert against a UNIQUE index rather than a
     * select-then-insert: two submits arriving together would both pass a
     * select, and the payment provider offers no duplicate protection of its
     * own — an identical payload posted twice creates two orders and charges
     * both, which was confirmed against the live sandbox. The index is the
     * serialisation point (`[13.37]`).
     *
     * @return bool true when this caller owns the attempt and must proceed to
     *              place the order; false when an attempt already exists and
     *              the caller must read its outcome instead
     */
    public function claim(string $key, string $sessionKey, string $now): bool
    {
        $sql = 'INSERT INTO checkout_attempts (idempotency_key, session_key, state, created_at) VALUES (?, ?, ?, ?)';

        // MySQL has no ON CONFLICT clause; SQLite and Postgres have no ON
        // DUPLICATE KEY UPDATE. The MySQL spelling is a deliberate no-op
        // assignment rather than `INSERT IGNORE`, for the reason
        // {@see SessionRepository::insert()} documents and which matters more
        // here: `INSERT IGNORE` downgrades *every* insert error to a warning,
        // so a NOT NULL or truncation failure would return zero affected rows
        // and be indistinguishable from "someone else holds this key". The
        // caller would then look for an outcome that does not exist, and the
        // one guard standing between a double submit and a double charge
        // would have failed open silently. Letting the driver raise instead
        // fails closed, which on this path is the only safe direction.
        $sql .= $this->driver === 'mysql'
            ? ' ON DUPLICATE KEY UPDATE idempotency_key = idempotency_key'
            : ' ON CONFLICT (idempotency_key) DO NOTHING';

        $statement = $this->pdo()->prepare($sql);
        $statement->execute([$key, $sessionKey, self::STATE_CLAIMED, $now]);

        return $statement->rowCount() === 1;
    }

    /**
     * The attempt held against this key, or null when nobody has claimed it.
     *
     * Returns the whole attempt rather than only its stored outcome, because
     * "there is no row" and "there is a row that has not finished yet" are the
     * two cases the caller has to tell apart, and only the first means it is
     * free to proceed. `created_at` comes back for the same reason: a claim
     * older than the configured staleness window belongs to a request that
     * died, and the caller decides what to do about that -- a decision that
     * turns on the state as well as the age.
     *
     * The outcome nests under its own key so that its `state` — what the
     * provider said — can never be confused with the attempt's `state`, which
     * is only how far this row has got.
     *
     * @return array{key: string, session_key: string, state: string, outcome: array<string, mixed>|null, created_at: string, completed_at: ?string}|null
     */
    public function outcomeFor(string $key): ?array
    {
        $statement = $this->pdo()->prepare(
            'SELECT idempotency_key, session_key, state, outcome, created_at, completed_at
             FROM checkout_attempts WHERE idempotency_key = ?',
        );
        $statement->execute([$key]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        $outcome = $row['outcome'] === null ? null : json_decode((string) $row['outcome'], true);

        return [
            'key' => (string) $row['idempotency_key'],
            'session_key' => (string) $row['session_key'],
            'state' => (string) $row['state'],
            'outcome' => is_array($outcome) ? $outcome : null,
            'created_at' => (string) $row['created_at'],
            'completed_at' => $row['completed_at'] === null ? null : (string) $row['completed_at'],
        ];
    }

    /**
     * Takes over a claim old enough to have been abandoned, in place.
     *
     * An in-place conditional update rather than a delete followed by a fresh
     * insert. The delete is what made the double charge possible: it removes
     * the row the UNIQUE index would have serialised against, so two requests
     * working from one snapshot of a stale claim both succeed in re-inserting
     * and both charge the card. Here the *row* is the serialisation point —
     * exactly one update can match a given `created_at`, so exactly one of any
     * number of concurrent takers wins, and the loser is told someone else
     * holds the key.
     *
     * The `state` predicate is load-bearing beyond the race: a `sent` row
     * belongs to a request that has already reached a provider with no
     * idempotency of its own, and no amount of age makes re-posting that
     * payload safe. This update cannot touch one, whatever `$observedAt` says.
     *
     * @param  string $observedAt the `created_at` the caller read, which is
     *                            what proves it is acting on the row it saw
     * @return bool   true when this caller now owns the attempt
     */
    public function renewClaim(string $key, string $sessionKey, string $now, string $observedAt): bool
    {
        $statement = $this->pdo()->prepare(
            'UPDATE checkout_attempts SET session_key = ?, created_at = ?
             WHERE idempotency_key = ? AND state = ? AND created_at = ?',
        );
        $statement->execute([$sessionKey, $now, $key, self::STATE_CLAIMED, $observedAt]);

        return $statement->rowCount() === 1;
    }

    /**
     * Records that this attempt is now with the provider.
     *
     * Called *before* the call, not after, because the row only has to be
     * wrong for the width of one network round trip for a retry to charge a
     * second time: a request that dies mid-charge leaves whatever this column
     * last said, and `claimed` invites the stale-takeover path to re-post an
     * identical payload to a gateway that will happily create and charge a
     * second order.
     *
     * Conditional on the claim this caller actually made, and it raises rather
     * than returning quietly when that claim is no longer there. Two things
     * can take it away: a taker that decided the claim was stale while the
     * first request was merely slow, and any future path that deletes the row.
     * In both cases the row no longer stands for this request, so proceeding
     * would charge a card with nothing in `checkout_attempts` recording it —
     * which lets the next submit claim cleanly and charge again, and lets the
     * outcome of this one be written onto somebody else's row. This runs
     * *before* the provider is contacted, so raising here costs nobody any
     * money; the caller refuses the submission instead.
     *
     * `\DomainException` specifically, and the type carries meaning: it is the
     * one failure of this method after which the caller must **not** release
     * the key, because the row it would delete is somebody else's. A driver
     * failure raises a `\PDOException`, which is a `\RuntimeException` and
     * never this — so the caller can tell "my row is gone" from "the database
     * is unwell" without inspecting a message.
     *
     * @param  string            $claimedAt the `created_at` this caller's own claim wrote
     * @throws \DomainException  when the claim this caller made is no longer the one on the row
     */
    public function markSent(string $key, string $claimedAt): void
    {
        $statement = $this->pdo()->prepare(
            'UPDATE checkout_attempts SET state = ? WHERE idempotency_key = ? AND state = ? AND created_at = ?',
        );
        $statement->execute([self::STATE_SENT, $key, self::STATE_CLAIMED, $claimedAt]);

        if ($statement->rowCount() !== 1) {
            throw new \DomainException('The checkout attempt claimed by this request is no longer held by it.');
        }
    }

    /**
     * Completes the attempt with what the provider said.
     *
     * Written for every outcome the provider actually answered with, so a
     * duplicate submit is given that answer rather than reaching the provider
     * again. An outcome that cannot rule out a charge — a timeout, a thrown
     * client, a body with no reference in it — is deliberately *not* recorded
     * here: that row stays `sent`, because "the provider declined you" is a
     * claim nobody is entitled to make about a call that never came back.
     *
     * @param array<string, mixed> $outcome
     */
    public function recordOutcome(string $key, array $outcome): void
    {
        // Predicated on the row still being `sent`, which is the state the
        // caller must have put it in before it reached the provider. Without
        // it this was the one write that advanced a row conditionally on
        // nothing -- it could complete a row that had been taken over, or one
        // already completed by somebody else's answer, and had no way to
        // report that it had.
        //
        // No `rowCount()` check and no exception, unlike {@see self::markSent()}:
        // by the time this runs the provider has answered and the card may be
        // debited, so a caller that raised here would turn a stored outcome
        // into an error page in front of a buyer whose money has moved. Matching
        // nothing is the safe direction -- the outcome is lost, the row keeps
        // whatever it already said, and the reconciliation log line is the
        // trail. Every caller runs this inside the post-charge guard for that
        // reason.
        $this->pdo()
            ->prepare('UPDATE checkout_attempts SET state = ?, outcome = ?, completed_at = ? WHERE idempotency_key = ? AND state = ?')
            ->execute([
                self::STATE_COMPLETE,
                (string) json_encode($outcome, JSON_UNESCAPED_SLASHES),
                gmdate('c'),
                $key,
                self::STATE_SENT,
            ]);
    }

    /**
     * Gives the key back, so the same submission may be tried again.
     *
     * A delete rather than a state change, because the key is derived from the
     * submission itself: a buyer who fixes a typo and resubmits produces a
     * different key, and one who retries the identical submission is entitled
     * to have it treated as a first attempt once the previous one is known to
     * have taken no money.
     *
     * Called in exactly two situations, and both of them are "no money moved,
     * and we know it": a request refused before the charge, and a decline the
     * provider itself answered with. The second is not optional — the key
     * excludes the card by design (`[15.8]`), so a buyer retrying with a
     * different card derives the *same* key, and holding a decline against it
     * would replay that decline forever and never let the second card reach
     * the provider.
     *
     * Never called for a placement, a challenge, or an outcome that cannot
     * rule out a charge: releasing any of those arms the double charge this
     * table exists to prevent.
     *
     * **Only the request that owns the row may delete it**, and that is now in
     * the predicate rather than in a convention every call site has to honour.
     * An unconditional delete was the root cause of a double charge: the
     * stale-claim takeover released somebody else's row before re-claiming it,
     * so a row that moved `claimed` → `sent` between that read and that delete
     * was removed while its owner was mid-charge, and the taker then charged
     * the same card again. {@see self::renewClaim()} closed that one call site;
     * naming the ownership token here closes the shape, so a future caller
     * cannot reopen it by being merely slow rather than dead.
     *
     * A state predicate cannot stand in for ownership, for the reason already
     * recorded above: this is called once against a `claimed` row (a refusal
     * before the charge) and once against a `sent` one (a decline the provider
     * itself answered with), so there is no single state to name. `created_at`
     * is the one column that says *which* request is asking.
     *
     * **No `rowCount()` check, deliberately.** A release that matched nothing
     * is the correct no-op rather than an error: the only way to match nothing
     * is to no longer own the row, and refusing to touch it is exactly what
     * was asked for. The two conditional *updates* count their rows because
     * each has a caller that must know whether it won; a release has nothing
     * to decide afterwards.
     *
     * @param string $claimedAt the `created_at` this caller's own claim wrote,
     *                          which is what proves the row is still its own
     *                          to give back
     */
    public function release(string $key, string $claimedAt): void
    {
        $this->pdo()
            ->prepare('DELETE FROM checkout_attempts WHERE idempotency_key = ? AND created_at = ?')
            ->execute([$key, $claimedAt]);
    }

    /**
     * The rows an aged sweep is entitled to take, named once so the count and
     * the delete below can never describe different sweeps.
     *
     * The state list is in the SQL rather than in PHP on purpose. A sweep that
     * selected candidates and then deleted them by key would re-open the exact
     * shape that caused a double charge once already: a row can move
     * `claimed` → `sent` between the read and the write, and the delete would
     * then remove a row whose owner is mid-charge. Written this way there is no
     * gap — a row that changes state loses the predicate, and the engine
     * decides which of the two statements got there first.
     */
    private const string EXPIRABLE = 'state = ? AND created_at < ?';

    /**
     * Deletes attempts old enough that nothing can still be waiting on them,
     * and returns how many went.
     *
     * Nothing has ever expired this table, which grows without bound — but the
     * growth is the smaller problem, and *what may not be deleted* is most of
     * the reasoning here. The retention is per state:
     *
     * **`claimed` — expired.** The row holds a key for a request that has not
     * contacted the provider. Its useful life is one staleness window
     * (`payment.attempt_stale_after_seconds`, two minutes by default), after
     * which {@see self::renewClaim()} already lets any new submit take it over
     * in place, so a claim older than that protects nothing. Deleting one does
     * not re-open the race either: {@see self::claim()} serialises on the
     * UNIQUE index, not on the row, so two submits arriving after a sweep still
     * produce exactly one winner. And a sweep that raced a live owner fails in
     * the safe direction — the owner's {@see self::markSent()} finds its claim
     * gone and raises *before* the provider is contacted, which costs nobody
     * any money. The caller is expected to hold a retention far longer than the
     * staleness window so that never happens in practice.
     *
     * **`sent` — never expired, at any age.** The provider was contacted and
     * did not answer, so the card may already have been charged; the row is the
     * only local record saying so. Age does not settle that question — a charge
     * from a month ago is still a charge — and re-posting an identical payload
     * to a gateway with no idempotency of its own creates and charges a second
     * order, which is what this whole table exists to prevent.
     * {@see self::renewClaim()} refuses these rows at any age for the same
     * reason, and a sweep that deleted them would be a slower spelling of the
     * takeover it forbids. The real cost is admitted rather than swept: a
     * permanently `sent` row also makes that one cart permanently unretryable
     * for that buyer. That is a call somebody has to resolve against the
     * provider, so {@see self::countUnresolved()} surfaces it instead.
     *
     * **`complete` — expired.** The stored outcome exists to replay the first
     * request's answer to a duplicate of the same submission, and only the same
     * browser can produce one: the key's material is the analytics session, the
     * PHP session id, the cart lines and the buyer's own fields
     * ({@see \AsterMD\Storefront\Checkout\IdempotencyKey::forSubmit()}). A
     * completed row is a placement or a challenge — a provider-answered decline
     * releases its key rather than completing — and **only the placement half
     * is expired.**
     *
     * For a placement, `[13.32]` clears the cart the moment the order is made,
     * so the journey that owns that key cannot re-derive it: a duplicate is
     * answered from the journey's own record of what it placed, not from this
     * table. What the row still covers is the narrow window in which a
     * duplicate carrying the pre-clear cart can arrive — a double-click, a
     * browser retry, a second tab — which is minutes, not weeks.
     *
     * A challenge is the opposite case and an earlier revision of this
     * paragraph named it and then reasoned only about placements. Nothing
     * clears the cart for a challenge: the buyer is on the provider's own page
     * with theirs intact, every input to the key survives, and the answer that
     * would stop a resubmit reaching the provider again lives only in this row.
     * {@see self::replaceableCompletions()} is where that distinction is made
     * and why.
     *
     * `created_at` rather than `completed_at`, because it is the indexed column
     * and because {@see self::renewClaim()} only ever moves it forward: a swept
     * row is therefore never older than this reads it as, which is the
     * conservative direction. The two are one request apart in any case.
     *
     * @param  string $before an ISO-8601 UTC instant in the fixed-width form
     *                        every writer here uses, which is what makes a
     *                        string comparison a valid range query
     * @return int    rows deleted
     */
    public function expire(string $before): int
    {
        $statement = $this->pdo()->prepare('DELETE FROM checkout_attempts WHERE ' . self::EXPIRABLE);
        $statement->execute([self::STATE_CLAIMED, $before]);

        $deleted = $statement->rowCount();

        foreach ($this->replaceableCompletions($before) as $id) {
            $delete = $this->pdo()->prepare('DELETE FROM checkout_attempts WHERE id = ?');
            $delete->execute([$id]);
            $deleted += $delete->rowCount();
        }

        return $deleted;
    }

    /**
     * The ids of aged `complete` rows whose answer nothing still needs.
     *
     * Read first and deleted by id, which the single-statement sweep above may
     * not do and this may. The race that forbids it there is `claimed` → `sent`
     * between the read and the write; a `complete` row is terminal, so there is
     * no later state for it to move to and nothing for the gap to hide.
     *
     * The filtering is the point. A completed row holds either a placement or a
     * **challenge**, and only the first is safe to forget. A duplicate of a
     * placed order is answered from the journey's own record of what it placed,
     * so the row is redundant; a challenge is answered from *here* and nowhere
     * else, and `[13.32]` does not clear the cart for one — the buyer is still
     * holding it, waiting on the provider's own page. Every input to
     * {@see \AsterMD\Storefront\Checkout\IdempotencyKey::forSubmit()} therefore
     * survives, and the PHP session id rolls forward on a thirty-day cookie, so
     * the same submit stays derivable long after any sweep would have taken the
     * row.
     *
     * Deleting one is the slower spelling of the takeover
     * {@see self::renewClaim()} correctly refuses for `sent`: it hands the key
     * back to a purchase the provider has already created an order for, against
     * a gateway with no idempotency of its own. The next submit creates a second
     * order and charges it. {@see \AsterMD\Storefront\Checkout\CheckoutService}
     * states the rule in its own words at the point it declines to release such
     * a key; this is the same rule, one sweep later.
     *
     * @return list<int>
     */
    private function replaceableCompletions(string $before): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT id, outcome FROM checkout_attempts WHERE state = ? AND created_at < ?',
        );
        $statement->execute([self::STATE_COMPLETE, $before]);

        $ids = [];

        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (!self::holdsAChallenge($row['outcome'] ?? null)) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
    }

    /**
     * Whether a stored outcome is a challenge still owed an answer.
     *
     * An outcome that cannot be decoded counts as a challenge. That is the
     * conservative direction and it is the whole reason this reads rather than
     * guesses: keeping a row nothing needed costs one row until the next
     * retention window, and dropping one that was needed costs a second charge.
     */
    private static function holdsAChallenge(mixed $outcome): bool
    {
        if (!is_string($outcome) || $outcome === '') {
            return false;
        }

        $decoded = json_decode($outcome, true);

        if (!is_array($decoded)) {
            return true;
        }

        return ($decoded['state'] ?? null) === PlacementOutcome::PENDING_ACTION;
    }

    /**
     * How many rows {@see self::expire()} would take, without taking them.
     *
     * The dry run's whole promise, and it shares that method's predicate so the
     * report cannot describe a different sweep from the one that follows it.
     */
    public function countExpirable(string $before): int
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM checkout_attempts WHERE ' . self::EXPIRABLE);
        $statement->execute([self::STATE_CLAIMED, $before]);

        return (int) $statement->fetchColumn() + count($this->replaceableCompletions($before));
    }

    /**
     * Aged attempts that reached the provider and were never answered.
     *
     * A count, not a sweep: {@see self::expire()} explains why these rows may
     * never be deleted on a timer. It exists so the state that cannot be
     * expired is at least *visible*, because two things are true of every row
     * it counts — money may have moved with nothing local confirming it, and
     * the buyer cannot retry that cart until somebody resolves it. The details
     * are not repeated here; each row was logged as `checkout.attempt_unresolved`
     * with its key, its analytics session and the amount at risk when it was
     * left behind, and that line is what an operator works from.
     */
    public function countUnresolved(string $before): int
    {
        $statement = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM checkout_attempts WHERE state = ? AND created_at < ?',
        );
        $statement->execute([self::STATE_SENT, $before]);

        return (int) $statement->fetchColumn();
    }
}
