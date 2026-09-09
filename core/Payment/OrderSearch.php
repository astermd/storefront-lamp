<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment;

/**
 * The window `[21.9a]`'s reverse sweep asks a provider for: "which orders did
 * you take between these two instants".
 *
 * Provider-neutral by construction (`[14.3]`). It carries a span and a bound
 * and nothing else -- no campaign, no merchant, no status vocabulary. Which
 * account the window is read against is a routing hint the adapter owns
 * (`[14.6c]`), so the sweep above the interface never learns that this
 * provider scopes orders by campaign.
 *
 * **Both instants are normalised to UTC**, because the two halves of the
 * comparison are computed on different clocks: this storefront writes
 * `gmdate('c')` and the provider stamps its own `date_created`. Normalising
 * here means a caller in any timezone describes the same span, and the format
 * the provider wants is applied once, in the adapter.
 *
 * **An inverted window throws rather than returning nothing.** A window whose
 * end precedes its start is answered with zero orders, and zero orders is
 * exactly what a healthy sweep looks like -- so the one shape that must never
 * be silently accepted is the one a caller's arithmetic bug produces. This is
 * a programming error at construction, not a provider outcome, so `[20.1]`'s
 * never-throw-at-the-buyer discipline does not apply: nothing here is on a
 * request path.
 */
final readonly class OrderSearch
{
    /**
     * Roughly twice the recorded volume of a default sweep: the reverse
     * sweep's default lookback is two days and the recorded account took 143
     * orders in three, so about 95 are expected and 200 is the headroom.
     *
     * It is headroom and not a guarantee. A deployment busier than the
     * recorded sandbox truncates, and since a truncated sweep now fails the
     * run ({@see \AsterMD\Storefront\Console\ReconcileOrdersCommand}) that
     * shows up as a failing job rather than as a quietly partial one — which
     * is the intended direction, but it does mean the value is a deployment
     * setting in practice and not a universal default.
     */
    public const int DEFAULT_LIMIT = 200;

    /**
     * The most orders one sweep will ask for.
     *
     * A bound rather than a preference: an unbounded page is a request whose
     * cost is set by the provider's data rather than by this code, and the
     * sweep reports truncation instead, which tells the operator to run more
     * often rather than to read more per run.
     */
    public const int MAX_LIMIT = 1000;

    private function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public int $limit,
    ) {
    }

    /**
     * An explicit span.
     *
     * @throws \InvalidArgumentException when the window ends before it starts
     */
    public static function between(\DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = self::DEFAULT_LIMIT): self
    {
        $utc = new \DateTimeZone('UTC');
        $from = $from->setTimezone($utc);
        $to = $to->setTimezone($utc);

        if ($to < $from) {
            throw new \InvalidArgumentException('An order search window cannot end before it starts.');
        }

        return new self($from, $to, max(1, min($limit, self::MAX_LIMIT)));
    }

    /**
     * The span a scheduled sweep actually wants: the recent past, stopping
     * short of now.
     *
     * **The grace cutoff is the point of the trailing edge.** An order the
     * provider accepted a second ago is not an order this storefront failed to
     * record -- the request that placed it is still running, and its row is
     * written moments later. Sweeping up to `now` would report every checkout
     * in flight as a lost charge, which is the fastest way to teach an
     * operator to ignore this report.
     *
     * **The lookback is deliberately much larger than the grace.** The two
     * clocks involved are assumed to agree, and that assumption is *not*
     * recorded: nothing observed proves the provider stamps `date_created` in
     * UTC. The design is built so it does not matter -- a skew of hours shifts
     * which orders land inside a window measured in days, so an order missed
     * at one edge is picked up by the next run. The one thing a skew can cost
     * is the in-flight protection above, and that costs an operator a lookup,
     * not a charge.
     *
     * @param int  $seconds      how far back to look
     * @param int  $graceSeconds how close to now to stop
     * @param ?\DateTimeImmutable $now injectable so a test can pin the window
     *
     * @throws \InvalidArgumentException when the lookback does not outlast its own grace
     */
    public static function lookback(
        int $seconds,
        int $graceSeconds = 0,
        int $limit = self::DEFAULT_LIMIT,
        ?\DateTimeImmutable $now = null,
    ): self {
        $now = ($now ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('UTC'));

        if ($seconds <= $graceSeconds) {
            throw new \InvalidArgumentException('An order search lookback must be longer than its grace window.');
        }

        return self::between(
            $now->sub(new \DateInterval('PT' . max(1, $seconds) . 'S')),
            $now->sub(new \DateInterval('PT' . max(0, $graceSeconds) . 'S')),
            $limit,
        );
    }
}
