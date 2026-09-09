<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Observability;

use AsterMD\Storefront\Support\CardScrubber;

/**
 * The two of `[20.12]`'s monitored counts that nothing counted: journeys
 * reaching payment without an EMR session, and the decline rate by reason.
 *
 * The other three are already computed by the two reconciliation sweeps and
 * are read from {@see \AsterMD\Storefront\Repository\OrderRepository} rather
 * than re-derived, so `ops:status` and `emr:reconcile` cannot report different
 * numbers for the same question.
 *
 * **Both figures here are read from `[18.1]`'s local trail** rather than from
 * the `orders` table, and the difference matters. The EMR keeps one checkout
 * record per session and updates it in place, so a decline the buyer retried
 * past is gone from it; and no order row exists at all for a charge that never
 * landed. The append-only `events` table is the only place either question can
 * still be answered.
 *
 * **A journey reaching payment with no session is not necessarily a fault.** A
 * deployment with analytics switched off has no session for any journey, so
 * this figure is read against its total rather than alone — which is why
 * {@see self::checkoutsReached()} exists beside it. What it catches is the
 * deployment where the two diverge: sessions are configured, and some
 * proportion of buyers reach the payment step without one. Those orders can
 * never be reconciled forward — the EMR keys a treatment on the session uuid
 * and `[20.8]` forbids inventing one — so each is a permanent gap.
 *
 * Every read is wrapped, and a read that failed answers
 * {@see Measured::unavailable()} rather than zero.
 */
final class OperationalCounts
{
    /**
     * The trail's names for the three checkout facts these counts are drawn
     * from, written by
     * {@see \AsterMD\Storefront\Checkout\EmrCheckoutEventReporter}.
     */
    private const string VISITED = 'checkout.visited';

    private const string DECLINED = 'checkout.order_declined';

    private const string PLACED = 'checkout.order_placed';

    /**
     * What a decline with no recorded reason is filed under.
     *
     * Named rather than dropped: a decline whose reason went missing is still
     * a decline, and omitting it would make the breakdown disagree with the
     * total it is a breakdown of.
     */
    public const string REASON_UNRECORDED = '(no reason recorded)';

    /**
     * The most distinct reasons reported before the tail is folded together.
     *
     * A provider composes these sentences and can vary them per decline, so
     * the distinct count is not bounded by anything this application controls.
     * An operator reading a status page needs the shape, not the long tail.
     */
    private const int MAX_REASONS = 20;

    /** What the folded tail is called, so the figures still add up to the total. */
    private const string REASON_OTHER = '(other)';

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param \Closure(): \PDO       $pdo
     * @param int                    $declineWindowSeconds how far back the decline rate is measured; a rate over all time
     *                                                     tells an operator nothing about today
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        private readonly \Closure $pdo,
        private readonly int $declineWindowSeconds = 2_592_000,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** Checkouts reached with no analytics session to file them under (`[20.12]`). */
    public function checkoutsWithoutEmrSession(): Measured
    {
        // The empty string is the absence recorded literally: `events.session_uuid`
        // is NOT NULL and an invented value would be indistinguishable from a
        // real identifier to anyone reading the table later (`[20.8]`).
        return $this->count(
            'SELECT COUNT(*) FROM events WHERE name = ? AND (session_uuid = ? OR session_uuid IS NULL)',
            [self::VISITED, ''],
        );
    }

    /** Every checkout reached, so the figure above can be read as a proportion. */
    public function checkoutsReached(): Measured
    {
        return $this->count('SELECT COUNT(*) FROM events WHERE name = ?', [self::VISITED]);
    }

    public function declines(): DeclineBreakdown
    {
        $since = gmdate('c', ($this->clock)() - $this->declineWindowSeconds);

        try {
            $statement = ($this->pdo)()->prepare(
                'SELECT payload FROM events WHERE name = ? AND created_at >= ?',
            );
            $statement->execute([self::DECLINED, $since]);

            /** @var array<string, int> $byReason */
            $byReason = [];
            $total = 0;

            foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $payload) {
                ++$total;
                $reason = $this->reasonFrom(is_string($payload) ? $payload : '');
                $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
            }

            $placed = $this->count('SELECT COUNT(*) FROM events WHERE name = ? AND created_at >= ?', [self::PLACED, $since]);

            if (!$placed->available()) {
                return DeclineBreakdown::unavailable($placed->failure ?? 'unknown');
            }

            arsort($byReason);

            return DeclineBreakdown::of(self::capped($byReason), $total, $total + (int) $placed->value);
        } catch (\Throwable $e) {
            return DeclineBreakdown::unavailable($e::class);
        }
    }

    /**
     * The reason a decline was filed under, scrubbed again on the way out.
     *
     * It was scrubbed on the way in, and it is scrubbed again here because
     * this method reads rows that may predate that defence and because a
     * status surface is a second destination for the same string
     * (`[15.8]`, `[20.14]`). Truncated for the same reason it is capped: a
     * provider that writes a paragraph must not be able to make an operator's
     * status page unreadable.
     */
    private function reasonFrom(string $payload): string
    {
        $decoded = json_decode($payload, true);
        $reason = is_array($decoded) && is_string($decoded['reason'] ?? null) ? trim($decoded['reason']) : '';

        if ($reason === '') {
            return self::REASON_UNRECORDED;
        }

        return CardScrubber::scrub(mb_substr($reason, 0, 120));
    }

    /**
     * @param array<string, int> $byReason already ordered highest first
     *
     * @return array<string, int>
     */
    private static function capped(array $byReason): array
    {
        if (count($byReason) <= self::MAX_REASONS) {
            return $byReason;
        }

        $kept = array_slice($byReason, 0, self::MAX_REASONS, true);
        $kept[self::REASON_OTHER] = array_sum(array_slice($byReason, self::MAX_REASONS, null, true));

        return $kept;
    }

    /** @param list<string> $bindings */
    private function count(string $sql, array $bindings): Measured
    {
        try {
            $statement = ($this->pdo)()->prepare($sql);
            $statement->execute($bindings);

            return Measured::of((int) $statement->fetchColumn());
        } catch (\Throwable $e) {
            return Measured::unavailable($e::class);
        }
    }
}
