<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Repository;

use AsterMD\Storefront\Support\CardScrubber;
use AsterMD\Storefront\Support\OperatorLog;

/**
 * The `events` table: the storefront's own append-only audit trail.
 *
 * This is the local forensic record of what happened in a journey,
 * independent of what the EMR recorded — which is what makes a "the order
 * exists at the provider but nowhere else" investigation possible, and what
 * §30.7's access audit is built on. Payloads pass through the same redaction
 * as the operator log, and through one pass the operator log does not want,
 * because this table long outlives the request and must never become a second
 * copy of someone's health information — see {@see self::productReferences()}
 * for the datum the two sinks answer differently and why. A failed write is
 * swallowed: an audit line is never worth breaking the request that produced
 * it (`[18.3]`).
 *
 * The connection arrives as a closure and is opened on the first query, for
 * the same reason {@see SessionRepository}'s is: constructing a repository
 * must not be the act that connects, or every collaborator assembled around
 * one inherits the database as a hard dependency. Here it also completes the
 * swallow — a connection that cannot be opened now fails inside the two
 * methods below, which already treat a lost audit line as nothing the request
 * needs to hear about.
 */
final class EventRepository
{
    /**
     * Payload keys holding a catalog slug, and the key each is recorded under
     * instead. Exact-match, like the redaction list it sits beside, so a slug
     * travelling under a second name has to be listed under that name too.
     *
     * The key is renamed rather than kept, because the value that replaces the
     * slug is not one: a digest filed under `slug` would be indistinguishable
     * from a real slug to anyone reading this table later, which is the same
     * objection `[20.8]` raises against inventing an identifier to fill a
     * column. `product_ref` says what it holds.
     */
    private const array PRODUCT_SLUG_KEYS = ['slug' => 'product_ref'];

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
     * Records one event, with its payload redacted, scrubbed and encoded.
     *
     * Both containment passes run, for the same reason they run in
     * {@see OperatorLog::write()} and with more force here: this column
     * outlives the request, so a card number reaching it is not a leak into a
     * log an operator eventually rotates but a card **persisted durably**,
     * which `[15.8]` forbids outright. {@see OperatorLog::redact()} is
     * key-based and blanks the names we chose; {@see CardScrubber} is
     * value-based and finds card data inside a string whose key means nothing
     * to us. Neither depends on the caller having asked.
     *
     * A third pass runs ahead of them and is this table's alone:
     * {@see self::productReferences()} keeps a catalog slug off a durable row.
     *
     * `JSON_INVALID_UTF8_SUBSTITUTE` keeps one malformed byte -- typically
     * quoted back at us inside a driver message -- from turning the whole
     * payload into the empty string, which is what `(string) false` was
     * writing.
     *
     * @param array<string, mixed> $payload
     */
    public function append(string $sessionUuid, string $name, array $payload = []): void
    {
        try {
            $this->pdo()->prepare('INSERT INTO events (session_uuid, name, payload, created_at) VALUES (?, ?, ?, ?)')
                ->execute([
                    $sessionUuid,
                    $name,
                    $payload === [] ? null : self::encode($payload),
                    gmdate('c'),
                ]);
        } catch (\Throwable) {
            // Deliberately swallowed — see the class docblock.
        }
    }

    /**
     * The payload as a JSON string that is safe to keep, or a marker when it
     * cannot be encoded at all — never the empty string, which reads in the
     * table as "there was no payload".
     *
     * @param array<string, mixed> $payload
     */
    private static function encode(array $payload): string
    {
        $json = json_encode(
            CardScrubber::scrubArray(OperatorLog::redact(self::productReferences($payload))),
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return $json === false
            ? '{"error":"payload could not be encoded"}'
            : $json;
    }

    /**
     * Replaces every catalog slug in the payload with an opaque reference to
     * the product, recursing into nested arrays.
     *
     * **A slug in this catalog is the drug and its strength.**
     * `6a8a85-tirzepatide-10mg-ml` is a product name that has been slugified,
     * not an identifier; the catalog carries a treated condition against it,
     * and the drug alone is enough without that. `events` is keyed by
     * `session_uuid` and outlives the request, so a slug written here is a
     * durable link from an identifiable journey to a prescription — which is
     * the thing `[20.14]` keeps off this table, and which the `name` beside it
     * being blanked does nothing about.
     *
     * **This is not shared with the operator log, and that is the point.** The
     * two sinks read the same redaction list because a name is a name wherever
     * it goes, but a slug is not that: `[20.6]`'s log is short-lived, is read
     * by an operator working an outage, and a slug is the one thing that makes
     * `cart.mirror_failed` actionable — a log that blanks what is useful
     * teaches the operator to stop reading it. A durable table inside §30.7's
     * access-audit scope has no such claim on it. Same datum, different sink,
     * different answer; so the rule lives here rather than one level down.
     *
     * **It runs at the sink rather than at the three call sites**, for the
     * reason {@see OperatorLog::write()} gives for its own passes: this method
     * is the one thing every stored payload goes through, and the three event
     * families that were each writing a slug — the cart trail, the order bumps,
     * the upsell offers — would each have had to remember, as would the fourth.
     * It also runs *first*, so the two containment passes remain the last thing
     * every stored value passes through.
     *
     * **The reference is a pseudonym, not a secret, and is not offered as one.**
     * It is unsalted so that the same product reads the same on every row and
     * across deployments — which is what lets the trail be grouped by product,
     * the question `[27.13]` asks of the bump events. The slug space is small
     * and the catalog is public, so anyone holding the catalog can rebuild the
     * map; a per-install salt would break the grouping and buy nothing against
     * them. What it does buy is what §18's payload wanted in the first place:
     * the identifier is opaque on its face and costs a deliberate join, so the
     * table stops being, by itself, a readable record of who was treated for
     * what. That is exactly the standing the EMR's own product id has, and
     * {@see \AsterMD\Storefront\Emr\CartMirror} writes that id alongside the
     * reference wherever the line carries one.
     *
     * A value that is not a non-empty string is not a slug and is not cast into
     * one: the reference is recorded as absent instead (`[20.8]`), while the key
     * it arrived under still does not survive.
     *
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    private static function productReferences(array $payload): array
    {
        $out = [];

        foreach ($payload as $key => $value) {
            $reference = is_string($key) ? (self::PRODUCT_SLUG_KEYS[strtolower($key)] ?? null) : null;

            if ($reference !== null) {
                $out[$reference] = is_string($value) && $value !== ''
                    ? substr(hash('sha256', $value), 0, 16)
                    : null;
                continue;
            }

            $out[$key] = is_array($value) ? self::productReferences($value) : $value;
        }

        return $out;
    }

    /**
     * Whether this session has ever recorded an event by this name.
     *
     * The trail's own answer to §18's fire-once cardinalities, for the events
     * whose "deduplicated against" column names a journey flag. It is asked of
     * this table rather than of journey state on purpose: the table is keyed
     * by the analytics session and is append-only, so the answer survives a
     * journey being transplanted onto a fresh identifier — where a durable
     * flag either travels, and suppresses an event on a session that has never
     * fired one, or does not, and is silently reset. Both are the shape
     * `[4.13]` warns about, pointed in opposite directions.
     *
     * A failed read returns false — "not recorded yet" — which errs towards
     * writing a second line rather than losing one, because a duplicated audit
     * row is legible and a missing one is not. It cannot break the request
     * either way (`[18.3]`).
     */
    public function hasName(string $sessionUuid, string $name): bool
    {
        try {
            $statement = $this->pdo()->prepare('SELECT 1 FROM events WHERE session_uuid = ? AND name = ? LIMIT 1');
            $statement->execute([$sessionUuid, $name]);

            return $statement->fetchColumn() !== false;
        } catch (\Throwable) {
            // Deliberately swallowed — see the class docblock.
            return false;
        }
    }

    /** @return list<string> event names for this session, oldest first */
    public function namesFor(string $sessionUuid): array
    {
        try {
            $statement = $this->pdo()->prepare('SELECT name FROM events WHERE session_uuid = ? ORDER BY id ASC');
            $statement->execute([$sessionUuid]);

            return array_map(static fn (mixed $name): string => (string) $name, $statement->fetchAll(\PDO::FETCH_COLUMN));
        } catch (\Throwable) {
            // Deliberately swallowed — see the class docblock.
            return [];
        }
    }
}
