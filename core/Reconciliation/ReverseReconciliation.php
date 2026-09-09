<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Reconciliation;

use AsterMD\Storefront\Payment\OrderSearch;
use AsterMD\Storefront\Payment\PaymentAdapter;
use AsterMD\Storefront\Payment\ProviderOrder;
use AsterMD\Storefront\Repository\OrderRepository;
use AsterMD\Storefront\Support\OperatorLog;

/**
 * `[21.9a]`'s reverse sweep: the orders the provider took that this storefront
 * has no record of at all.
 *
 * **This is not the mirror of the forward sweep, and the difference is the
 * whole point.** {@see ForwardReconciliation} starts from a local row and asks
 * whether the EMR heard about it — there is always something local to work
 * from. Here the card was charged at the provider and the storefront died
 * before the order row was written, so **there is no local record of any
 * kind**: no row, no status, no amount, nothing to reconcile from. The only
 * way to see one of these is to ask the provider what it took and look for the
 * answers that are missing on this side.
 *
 * **It detects and counts; it does not attribute, and that is a ruling rather
 * than an omission.** `[21.9b]` names the analytics session identifier as the
 * recovery key. This storefront submits one at placement and **it comes back
 * on neither the order search nor the single-order read** — checked against
 * all 143 orders in a recorded three-day window. `connection_order_id`, the
 * obvious substitute, is overwritten with the provider's own order id on 143
 * of 143 rows. The only field that survives the round trip is a tracking slot,
 * and all twenty are already allocated. The user ruling of 2026-08-25 is not
 * to spend one, so `[21.9b]` is recorded as a gap this provider cannot close
 * as written, and an orphan is handed to an operator to investigate in the
 * provider's own dashboard. `[20.8]`'s prohibition on inventing a synthetic
 * identifier to close the gap stands untouched.
 *
 * **Nothing here writes, and there is deliberately nothing to apply.** An
 * unmatched provider order cannot be turned into a local order row: the search
 * projection carries no session, no lines, no buyer and — recorded — no order
 * total at all, so a row built from it would be a fabricated record of a
 * charge, which is exactly what `[20.8]` forbids. The sweep is therefore
 * read-only by nature rather than by caution, and
 * {@see \AsterMD\Storefront\Console\ReconcileOrdersCommand} has no `--apply`
 * because there is no second behaviour for one to select.
 *
 * **How the three unmatched shapes are treated**, each decided from the
 * recordings rather than assumed:
 *
 * - **A null `status_type_id` is never a lost charge.** It marks an order
 *   container whose card was never charged — `date_ordered`,
 *   `date_authorized` and `date_capture` are all null on the recorded ones,
 *   and it is 55 of the 143 orders in the window. `[14.10]` says the opposite,
 *   that no status counts as placed; {@see \AsterMD\Storefront\Payment\Vrio\VrioOutcome}
 *   already refuses that reading at placement time and this sweep has to agree
 *   with it. Counted, so the figure exists, and never alarmed on.
 * - **A test order is counted apart from a lost charge.** Every order this
 *   sandbox account takes is `is_test: true`, so folding them in would make
 *   the monitored figure permanently wrong in staging — and dropping them
 *   silently would leave a sandbox sweep reporting zero of everything, which
 *   is indistinguishable from a sweep that has stopped running.
 * - **A local row that cannot be read is neither.** A database error is not
 *   evidence that an order is missing. It is not evidence that the order is
 *   fine either, so it is counted apart and
 *   {@see \AsterMD\Storefront\Console\ReconcileOrdersCommand} fails the run
 *   while there is one: the lost-charge count is then a floor rather than a
 *   total, and a scheduler must not be handed a figure that looks measured and
 *   is not.
 *
 * **A provider outage is a logged non-event** (`[20.1]`), but it is never a
 * clean sweep: an unanswered search and an empty window both yield zero
 * orders, and the report carries the difference so that zero can be trusted.
 *
 * There is no scheduler in this codebase and this class does not become one.
 * It is run by {@see \AsterMD\Storefront\Console\ReconcileOrdersCommand},
 * which an operator schedules externally.
 */
final class ReverseReconciliation
{
    /**
     * @param int $lookbackSeconds how far back one sweep looks; two days by default, so a run missed overnight
     *                             is covered by the next one rather than leaving a hole
     * @param int $graceSeconds    how close to now the window stops, so a checkout still in flight is not
     *                             reported as an order this storefront failed to record
     * @param int $limit           the most orders one run will read; a window that holds more is reported as
     *                             truncated rather than silently half-swept
     *
     * @param \Closure(): PaymentAdapter $adapter resolved on the first sweep rather than handed over built, for the
     *                                           reason `provider:ping` takes a closure too: constructing an adapter
     *                                           reads credentials and builds an HTTP client, and this class is
     *                                           registered in a console application where `db:migrate` must not pay
     *                                           for either
     */
    public function __construct(
        private readonly \Closure $adapter,
        private readonly OrderRepository $orders,
        private readonly OperatorLog $log,
        private readonly int $lookbackSeconds = 172800,
        private readonly int $graceSeconds = 900,
        private readonly int $limit = OrderSearch::DEFAULT_LIMIT,
    ) {
    }

    /**
     * One sweep.
     *
     * @param ?\DateTimeImmutable $now             injectable so a scheduled run and a test measure the same window
     * @param ?int                $lookbackSeconds overrides the configured lookback for this run only, which is what
     *                                             the console offers an operator whose window came back truncated
     *
     * @throws \InvalidArgumentException when the requested window does not outlast the grace window; a sweep that
     *                                   cannot see anything must not report that there was nothing to see
     */
    public function run(?\DateTimeImmutable $now = null, ?int $lookbackSeconds = null): ReverseReconciliationReport
    {
        $adapter = ($this->adapter)();

        if (!$adapter->capabilities()->supportsOrderSearch) {
            // Not a failure: a deployment whose provider cannot be asked what
            // orders it holds has no reverse sweep to run. Reported as its own
            // state so it can never be read as "asked, and nothing was lost".
            $this->log->info('reconcile.reverse_sweep_unsupported', [
                'provider' => $adapter->capabilities()->providerCategory,
            ]);

            return $this->report(new ReverseReconciliationReport(supported: false));
        }

        $result = $adapter->searchOrders(OrderSearch::lookback(
            seconds: $lookbackSeconds ?? $this->lookbackSeconds,
            graceSeconds: $this->graceSeconds,
            limit: $this->limit,
            now: $now,
        ));

        if (!$result->ok) {
            $this->log->warning('reconcile.order_search_failed', ['reason' => $result->failureReason]);

            return $this->report(new ReverseReconciliationReport(
                searchFailed: true,
                failureReason: $result->failureReason,
            ));
        }

        if ($result->truncated()) {
            // The window held more than one run can read, so everything past
            // the limit went unexamined. Said out loud rather than inferred
            // from two counts that happen to differ.
            $this->log->warning('reconcile.reverse_sweep_truncated', [
                'examined' => count($result->orders),
                'reported_total' => $result->reportedTotal,
            ]);
        }

        $matched = 0;
        $unmatchedTest = 0;
        $unmatchedUncharged = 0;
        $unreadable = 0;
        /** @var list<string> $lost */
        $lost = [];

        foreach ($result->orders as $order) {
            $known = $this->isRecordedLocally($order->reference);

            if ($known === null) {
                ++$unreadable;
                continue;
            }

            if ($known) {
                ++$matched;
                continue;
            }

            // The order the provider holds is on this side nowhere. What kind
            // of nowhere decides whether anyone is told.
            if (!$order->isCharged) {
                ++$unmatchedUncharged;
                continue;
            }

            if ($order->isTest) {
                ++$unmatchedTest;
                continue;
            }

            $lost[] = $order->reference;
            $this->reportLostCharge($order);
        }

        return $this->report(new ReverseReconciliationReport(
            examined: count($result->orders),
            reportedTotal: $result->reportedTotal,
            truncated: $result->truncated(),
            matched: $matched,
            lostCharges: count($lost),
            unmatchedTest: $unmatchedTest,
            unmatchedUncharged: $unmatchedUncharged,
            unreadable: $unreadable,
            lostChargeReferences: $lost,
        ));
    }

    /**
     * Is this provider order recorded here? Null when the question could not
     * be asked.
     *
     * The three-valued answer is the point. A read that threw is not a missing
     * order, and collapsing it to false would turn a database outage into a
     * report that every charge the provider took had been lost. Collapsing it
     * to true is the same mistake in the other direction and the more
     * dangerous one, because its output is an all-clear. So the count travels
     * on the report rather than being absorbed into either figure, and the
     * console reads a non-zero one as a run that did not measure its window.
     */
    private function isRecordedLocally(string $reference): ?bool
    {
        try {
            return $this->orders->findByReference($reference) !== null;
        } catch (\Throwable $e) {
            $this->log->warning('reconcile.local_read_failed', [
                'reference' => $reference,
                'exception' => $e::class,
                'status' => $e->getCode(),
            ]);

            return null;
        }
    }

    /**
     * One order the buyer was charged for and this storefront has no record
     * of.
     *
     * At error level because money moved and nothing on this side knows it,
     * and named one per line because the action it calls for is looking each
     * one up by hand — which, under the `[21.9b]` ruling, is the whole
     * recovery path. Test orders and uncharged containers are counted but
     * never named: fifteen recorded sandbox orders per run would bury the one
     * line that matters.
     *
     * **Identifiers and counts only.** No part of the provider's projection is
     * logged — not the buyer's IP, not the provider's customer or card ids.
     * The wire log already carries verbatim traffic for a deployment that
     * turns it on, and this is not a second copy of it.
     */
    private function reportLostCharge(ProviderOrder $order): void
    {
        $this->log->error('reconcile.order_not_recorded', [
            'reference' => $order->reference,
            'placed_at' => $order->placedAt,
            'raw_status' => $order->rawStatus,
            'discount_cents' => $order->discountCents,
        ]);
    }

    /**
     * `[20.12]`: one line per run carrying the monitored count, whether or not
     * there was anything to report.
     *
     * Emitted on every exit from {@see self::run()}, including the two that
     * examined nothing, because a log that only speaks up when something is
     * wrong cannot be told apart from one that has stopped running.
     */
    private function report(ReverseReconciliationReport $report): ReverseReconciliationReport
    {
        $this->log->info('reconcile.reverse_sweep', $report->toArray());

        return $report;
    }
}
