<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Reconciliation;

/**
 * What one reverse sweep saw at the provider, and how much of it this
 * storefront has no record of.
 *
 * **{@see self::$lostCharges} is `[20.12]`'s monitored count** — "orders
 * placed at the provider but not recorded locally" — and it is deliberately
 * the narrowest of the four unmatched figures rather than their sum. The other
 * three are beside it, not inside it, because each is unmatched for a reason
 * that is not a lost charge:
 *
 * - **{@see self::$unmatchedUncharged}** — the provider holds an order
 *   container whose card was never charged (a null `status_type_id`: no
 *   `date_ordered`, no `date_authorized`, no `date_capture`, and 55 of the 143
 *   orders in the recorded window). No money moved, so nothing was lost.
 * - **{@see self::$unmatchedTest}** — a sandbox order. It is a real record at
 *   the provider and never a real charge. Counted rather than dropped, because
 *   in a sandbox deployment it is the only proof the sweep is still running.
 * - **{@see self::$unreadable}** — the local row could not be read at all. A
 *   database error is not evidence that an order is missing, and treating it
 *   as one would report every order the provider holds as a lost charge the
 *   moment the database went away.
 *
 * **{@see self::$searchFailed} exists so zero can be trusted.** An empty
 * window and an unanswered call both produce zero orders, and only one of them
 * means nothing was lost. Every figure here is meaningless while this is true,
 * and the operator line says so rather than reporting an all-clear.
 *
 * **{@see self::$truncated} is the same guarantee for the other end.** A sweep
 * whose window held more orders than its limit returned examined a prefix and
 * said nothing at all about the rest — and the prefix is the provider's
 * ordering, not one this code asked for. Recorded newest first, so the unread
 * orders are the oldest in the window. {@see self::$lostCharges} is then a
 * floor rather than a total, which is why the console fails the run on it;
 * {@see \AsterMD\Storefront\Payment\OrderSearchResult::truncated()} carries
 * the detail.
 *
 * **{@see self::$unreadable} makes {@see self::$lostCharges} a floor in the
 * same way**, and for the same reason it must not be folded into it: an order
 * that could not be checked is neither matched nor lost, so a count taken
 * beside a non-zero `unreadable` is a lower bound on a question that was only
 * partly asked.
 */
final readonly class ReverseReconciliationReport
{
    /**
     * @param bool         $supported            whether the configured adapter can be asked for its orders at all
     * @param bool         $searchFailed         whether the provider was asked and did not answer
     * @param ?string      $failureReason        an operator-facing code, never provider free text
     * @param int          $examined             provider orders this run read and matched against local rows
     * @param int          $reportedTotal        how many the provider says the window held
     * @param bool         $truncated            whether the limit cut the window short
     * @param int          $matched              provider orders that do have a local row
     * @param int          $lostCharges          charged, live, and recorded nowhere locally (`[20.12]`)
     * @param int          $unmatchedTest        charged sandbox orders with no local row
     * @param int          $unmatchedUncharged   unmatched orders whose card was never charged
     * @param int          $unreadable           unmatched-or-not: the local row could not be read
     * @param list<string> $lostChargeReferences the provider references an operator has to look up by hand
     */
    public function __construct(
        public bool $supported = true,
        public bool $searchFailed = false,
        public ?string $failureReason = null,
        public int $examined = 0,
        public int $reportedTotal = 0,
        public bool $truncated = false,
        public int $matched = 0,
        public int $lostCharges = 0,
        public int $unmatchedTest = 0,
        public int $unmatchedUncharged = 0,
        public int $unreadable = 0,
        public array $lostChargeReferences = [],
    ) {
    }

    /**
     * The report as the operator log and the console both render it.
     *
     * One shape for both destinations, for the reason the forward sweep's
     * report gives: two spellings of the same count is how they drift.
     *
     * @return array<string, int|bool|string|null>
     */
    public function toArray(): array
    {
        return [
            'supported' => $this->supported,
            'search_failed' => $this->searchFailed,
            'failure_reason' => $this->failureReason,
            'examined' => $this->examined,
            'reported_total' => $this->reportedTotal,
            'truncated' => $this->truncated,
            'matched' => $this->matched,
            'lost_charges' => $this->lostCharges,
            'unmatched_test' => $this->unmatchedTest,
            'unmatched_uncharged' => $this->unmatchedUncharged,
            'unreadable' => $this->unreadable,
        ];
    }
}
