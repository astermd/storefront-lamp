<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Repository;

/**
 * The `sessions` table: one row per analytics session, carrying the durable
 * server-side journey state.
 *
 * Journey state must be durable and server-side (`[19.8]`) — browser storage
 * is a cache of this row, not the system of record — because cross-device
 * resume and the abandonment sweep both read it without a browser present.
 * The two JSON columns are encoded and decoded here so nothing above this
 * class handles serialised state, and `attribution` stays genuinely NULL
 * when nothing was ever captured, since "never captured" and "captured and
 * empty" are different states (`[5.13]`).
 *
 * SQL is kept portable: this file must work unchanged on SQLite, MySQL and
 * Postgres. Engine differences belong in the connection factory and the
 * migrations, with the single exception noted on {@see self::insert()}, where
 * no portable spelling of "insert unless it is already there" exists.
 *
 * The connection arrives as a closure and is opened on the first query rather
 * than in the constructor, because *constructing* this repository is not the
 * same act as using it. Everything the cart needs — the working copy of the
 * lines, the flash notice — lives in the PHP session, and the visitor must be
 * able to add to their cart with the database flat on its back (`[20.1]`);
 * but the cart store reaches this class through the journey store, so an
 * eager connection would have made every consumer of a cart a consumer of the
 * database. Deferring it here removes that coupling once, for every call
 * site, instead of asking each one to wrap its own construction in a
 * try/catch and remember why.
 */
final class SessionRepository
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
     * @return array{session_uuid: string, opportunity_id: ?string, journey_state: array<string, mixed>, attribution: ?array<string, mixed>, created_at: string, updated_at: string}|null
     */
    public function find(string $uuid): ?array
    {
        $statement = $this->pdo()->prepare(
            'SELECT session_uuid, opportunity_id, journey_state, attribution, created_at, updated_at
             FROM sessions WHERE session_uuid = ?',
        );
        $statement->execute([$uuid]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        return [
            'session_uuid' => (string) $row['session_uuid'],
            'opportunity_id' => $row['opportunity_id'] === null ? null : (string) $row['opportunity_id'],
            'journey_state' => self::decode((string) $row['journey_state']) ?? [],
            'attribution' => $row['attribution'] === null ? null : self::decode((string) $row['attribution']),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * Creates the row for a newly minted, adopted or re-minted session.
     * Attribution is written here as well as by {@see self::save()} so a first
     * touch is durable the moment it is captured, even if the request that
     * captured it never reaches the save-back middleware. The opportunity is
     * accepted here for the same reason: a journey transplanted onto a
     * re-minted session arrives with its opportunity already known, and
     * {@see \AsterMD\Storefront\Journey\JourneyStore::adopt()} fingerprints
     * the state it is handed, so the save-back would see no change and never
     * write it.
     *
     * The conflict clause replaces a find-then-insert pair, which cost a
     * round-trip on every landing and left a window in which two concurrent
     * requests for one session could both decide the row was absent. Doing
     * nothing on conflict — rather than upserting — is deliberate: an existing
     * row already holds this journey's history, and this method's job is to
     * guarantee a row exists, not to overwrite one.
     *
     * @param array<string, mixed>      $journeyState
     * @param array<string, mixed>|null $attribution
     */
    public function insert(string $uuid, array $journeyState, ?array $attribution, ?string $opportunityId = null): void
    {
        $now = gmdate('c');
        $sql = 'INSERT INTO sessions (session_uuid, opportunity_id, journey_state, attribution, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?)';

        // MySQL has no ON CONFLICT clause; SQLite (>= 3.24) and Postgres have
        // no ON DUPLICATE KEY UPDATE. This is the one place the difference
        // shows, and the driver is read from the live connection rather than
        // injected so no caller has to know which engine it is talking to.
        //
        // Both spellings suppress exactly one thing: a duplicate key on
        // `session_uuid`. `ON DUPLICATE KEY UPDATE session_uuid = session_uuid`
        // is a deliberate no-op assignment — MySQL's only way to say "do
        // nothing on conflict" — and is used in preference to `INSERT IGNORE`,
        // which downgrades *every* insert error to a warning: a truncated
        // value, an oversized column, a NOT NULL violation. That distinction
        // is load-bearing rather than pedantic, because nothing retries this
        // insert. {@see \AsterMD\Storefront\Journey\SessionResolver::remint()}
        // inserts and then adopts, and adoption fingerprints the state, so the
        // save-back at the end of the request sees no change and writes
        // nothing. A silently dropped insert would leave the visitor holding a
        // cookie for a session with no local row — the transplanted cart,
        // attribution and opportunity link all gone, and not a line in the
        // logs to say so.
        $sql = $this->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? $sql . ' ON DUPLICATE KEY UPDATE session_uuid = session_uuid'
            : $sql . ' ON CONFLICT (session_uuid) DO NOTHING';

        $this->pdo()->prepare($sql)->execute([
            $uuid,
            $opportunityId,
            self::encode($journeyState),
            $attribution === null ? null : self::encode($attribution),
            $now,
            $now,
        ]);
    }

    /**
     * Writes the journey's durable state back over an existing row.
     *
     * Returns false when the update matched nothing, which means the row is
     * gone: a failed earlier insert, a reset database, or a row removed by the
     * deployment's retention policy. Reporting it matters more than it looks —
     * `reconciled` is among the fields lost, so a flush swallowed here does
     * not surface as an error but as a permanent per-request EMR read
     * (`[4.14]`) that nothing in the logs explains.
     *
     * The affected-row count is the signal because a caller only reaches here
     * with state that differs from what was loaded, so a matched row is always
     * a changed row — which is what makes this reliable on MySQL too, where
     * `rowCount()` reports rows changed rather than rows matched.
     *
     * @param array<string, mixed>      $journeyState
     * @param array<string, mixed>|null $attribution
     */
    public function save(string $uuid, array $journeyState, ?array $attribution, ?string $opportunityId): bool
    {
        $statement = $this->pdo()->prepare(
            'UPDATE sessions SET journey_state = ?, attribution = ?, opportunity_id = ?, updated_at = ?
             WHERE session_uuid = ?',
        );
        $statement->execute([
            self::encode($journeyState),
            $attribution === null ? null : self::encode($attribution),
            $opportunityId,
            gmdate('c'),
            $uuid,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Every session whose journey last *moved* inside a window, oldest first.
     *
     * The abandonment sweep's only read (`[21.10]`). It is a "last moved"
     * question rather than a "last seen" one, and `updated_at` genuinely
     * answers it: {@see \AsterMD\Storefront\Journey\JourneyStore::flush()} is
     * fingerprint-gated and writes nothing when journey state has not changed,
     * so a visitor refreshing a page does not keep resetting their own
     * abandonment clock. That is what makes `[21.12]`'s "server-side and
     * time-based, never a browser unload event" implementable at all here.
     *
     * Both bounds are inclusive and both are required, because the sweep needs
     * two different things from them: `$latest` is the idle interval — nothing
     * newer than this has stopped moving yet — and `$earliest` is the lookback
     * horizon, which stops a sweep from re-offering to nudge journeys that
     * went cold months ago and from paying to scan the whole table to decide
     * so.
     *
     * The comparison is a plain string range and that is deliberate rather
     * than lazy: these columns are `VARCHAR(32)` holding `gmdate('c')`, whose
     * fixed-width UTC form sorts lexicographically in the same order it sorts
     * chronologically. It stays portable across SQLite, MySQL and Postgres
     * precisely because no engine's date functions are involved. Both callers
     * must pass that format; any other spelling of a timestamp compares
     * meaninglessly here.
     *
     * `$limit` is bound as an integer rather than interpolated: the value
     * reaches this from configuration, and a query built by concatenation is
     * a query someone can eventually feed something else into. It is also
     * floored at one, since `LIMIT 0` is a query that always answers nothing
     * and would make a misconfigured sweep look like a quiet one.
     *
     * @param string $earliest the oldest `updated_at` to consider, `gmdate('c')`
     * @param string $latest   the newest `updated_at` to consider, `gmdate('c')`
     *
     * @return list<array{session_uuid: string, opportunity_id: ?string, journey_state: array<string, mixed>, attribution: ?array<string, mixed>, created_at: string, updated_at: string}>
     */
    public function findLastMovedBetween(string $earliest, string $latest, int $limit): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT session_uuid, opportunity_id, journey_state, attribution, created_at, updated_at
             FROM sessions WHERE updated_at >= ? AND updated_at <= ?
             ORDER BY updated_at ASC LIMIT ?',
        );
        $statement->bindValue(1, $earliest, \PDO::PARAM_STR);
        $statement->bindValue(2, $latest, \PDO::PARAM_STR);
        $statement->bindValue(3, max(1, $limit), \PDO::PARAM_INT);
        $statement->execute();

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[] = [
                'session_uuid' => (string) $row['session_uuid'],
                'opportunity_id' => $row['opportunity_id'] === null ? null : (string) $row['opportunity_id'],
                'journey_state' => self::decode((string) $row['journey_state']) ?? [],
                'attribution' => $row['attribution'] === null ? null : self::decode((string) $row['attribution']),
                'created_at' => (string) $row['created_at'],
                'updated_at' => (string) $row['updated_at'],
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $value */
    private static function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed>|null */
    private static function decode(string $json): ?array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
