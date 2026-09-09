<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Upsell;

use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Support\OperatorLog;

/**
 * Where in its upsell queue one journey has got to (`[16.3]`–`[16.6]`).
 *
 * Decisions only — no database, no provider, no HTTP — because the arithmetic
 * here is the part that can be wrong without anything noticing. A cursor one
 * too high costs the buyer an offer; one too low shows them an add-on they
 * have already been charged for. Neither looks like a fault from any layer
 * above: both look like a queue behaving normally.
 *
 * **The queue is the record of what was planned; the cursor is the record of
 * what is left.** Nothing here shortens {@see JourneyState::$upsellQueue}, so
 * the trail of what this journey was going to be offered survives the whole
 * flow, and a cursor that has run past the end is simply spent. That also
 * makes durable state safe to outlive a configuration change: a queue written
 * at checkout and read three requests later can name a key that no longer
 * resolves, and that is an ordinary skip rather than a corruption.
 *
 * **Reading the current offer can move the cursor, deliberately.** An entry
 * that no longer resolves is skipped, recorded and advanced past *at the
 * moment it is read* (`[16.4]`), rather than being left in place for the view
 * to notice: leaving it would re-resolve and re-log the dead entry on every
 * render, and the skip is a decision that has been taken rather than a view of
 * one. It also means one request steps over any number of dead entries to
 * reach a live one, so a buyer never pays for a misconfiguration in redirects.
 */
final class UpsellQueue
{
    public function __construct(
        private readonly Upsells $upsells,
        private readonly OperatorLog $log,
    ) {
    }

    /**
     * The offer to present now, or null when this journey has none left.
     *
     * Skips, records and advances past every entry the configuration can no
     * longer resolve, in one pass, so the answer is always an offer that can
     * actually be rendered and charged for (`[16.4]`). Null means the queue is
     * spent and the receipt is next (`[16.5]`).
     *
     * A cursor past the end answers null rather than reading from the front.
     * That is the fail-safe direction: sending the buyer to the receipt costs
     * an optional add-on, while re-reading the queue from the front would
     * re-offer something they may already have been charged for.
     *
     * A *negative* cursor is answered null here too, but only when one reaches
     * this method: {@see JourneyState::fromArray()} clamps a negative value to
     * zero on the way out of the database, so the durable path re-reads from
     * the front instead. The two guards were written to different rules and
     * only the in-process one matches this paragraph. Neither is reachable
     * without a corrupted column, and a repeat charge is caught by the
     * idempotency key either way — but they should be made to agree, and the
     * argument above is the one that should win, because it is about money
     * where the other is about a lost offer.
     */
    public function current(JourneyState $state): ?Upsell
    {
        while (array_key_exists($state->upsellCursor, $state->upsellQueue)) {
            $key = $state->upsellQueue[$state->upsellCursor];

            // The queue comes back out of a JSON column, so an entry that is
            // not a key at all is reachable. Advanced past rather than treated
            // as the end of the queue, which would silently drop every offer
            // behind it -- and there is no key to record an outcome against,
            // so the operator log is the only trail it can leave.
            if (!is_string($key) || $key === '') {
                $this->log->info('upsell.skipped', ['key' => null, 'reason' => 'unreadable_entry']);
                $state->upsellCursor++;

                continue;
            }

            $upsell = $this->upsells->resolve($key);
            if ($upsell !== null) {
                return $upsell;
            }

            // `Upsells::resolve()` has already said *why* it could not be
            // resolved, at warning level. This line says what happened to the
            // buyer as a result, which is the fact an operator reading the
            // funnel needs: the offer was never put, and the queue moved on.
            $this->log->info('upsell.skipped', ['key' => $key, 'reason' => 'unresolvable']);
            $state->upsellOutcomes[$key] = JourneyState::UPSELL_SKIPPED;
            $state->upsellCursor++;
        }

        return null;
    }

    /**
     * Records what happened to the offer at the cursor and moves past it.
     *
     * The key is read from the cursor and never inferred from which outcomes
     * are missing. Inferring it — "the first entry nothing has answered yet" —
     * would write this answer onto a key that was skipped earlier, and the
     * answer being written is sometimes "charged".
     *
     * A spent queue records nothing and moves nothing. There is no offer to
     * answer for, and a cursor that keeps climbing past the end is a cursor
     * that will never agree with the queue again.
     *
     * @param string $outcome one of {@see JourneyState}'s `UPSELL_*` constants
     */
    public function advance(JourneyState $state, string $outcome): void
    {
        $key = $state->upsellQueue[$state->upsellCursor] ?? null;
        if (!is_string($key) || $key === '') {
            return;
        }

        $state->upsellOutcomes[$key] = $outcome;
        $state->upsellCursor++;
    }

    /**
     * Whether this journey has any offer left to make (`[16.5]`, `[16.6]`).
     *
     * Answered by asking for the current offer, which means it **also skips**
     * the dead entries it walks over. That is the point: a queue of nothing but
     * unresolvable keys is spent, and finding that out is exactly the work
     * {@see self::current()} does. A second implementation that only compared
     * the cursor with the length would answer "not spent" for such a queue and
     * send the buyer to a page with nothing on it.
     */
    public function isSpent(JourneyState $state): bool
    {
        return $this->current($state) === null;
    }

    /**
     * How far through the offers this journey is, as "offer $n of $total".
     *
     * Both figures count only entries that resolve, because the buyer cannot
     * be shown an entry that does not: telling them this is offer 1 of 3 when
     * two of the three will be skipped promises two screens that never arrive.
     * An entry already recorded as skipped is excluded without being resolved
     * again, so the count settles rather than shifting as the queue is walked.
     *
     * `[0, 0]` for a queue with nothing live in it, which is the honest answer
     * and is never rendered — a spent queue redirects to the receipt before
     * anything asks for a position.
     *
     * @return array{0: int, 1: int} 1-based position, and the total
     */
    public function position(JourneyState $state): array
    {
        $position = 0;
        $total = 0;

        foreach ($state->upsellQueue as $index => $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if (($state->upsellOutcomes[$key] ?? null) === JourneyState::UPSELL_SKIPPED) {
                continue;
            }

            if ($this->upsells->resolve($key) === null) {
                continue;
            }

            $total++;

            if (is_int($index) && $index <= $state->upsellCursor) {
                $position++;
            }
        }

        return [$position, $total];
    }
}
