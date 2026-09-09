<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Reconciliation;

use AsterMD\Storefront\Checkout\CheckoutEventReporter;
use AsterMD\Storefront\Repository\OrderRepository;
use AsterMD\Storefront\Support\OperatorLog;

/**
 * `[21.8]`'s forward sweep: the orders this storefront recorded and the EMR was
 * never told about, told about.
 *
 * **The predicate is `orders.treatment_reference IS NULL`**, and it was settled
 * before anything could run it: {@see \AsterMD\Storefront\Checkout\EmrCheckoutEventReporter}
 * stamps that column only after a sync the EMR accepted, so null means "never
 * told" and nothing else. Recorded, and load-bearing: **the EMR cannot be asked
 * what it already has.** Its treatment listing ignores every filter offered to
 * it — a 1990 date range, a bogus status and a nonexistent patient all return
 * the same rows — and its projection carries no external order references at
 * all. The local null column is the only usable signal there is.
 *
 * **The grace window is not tidiness.** An order placed seconds ago has not
 * failed to sync: the request that placed it reports it in the same breath.
 * Sweeping inside that window would race a call already in flight, and because
 * the EMR deduplicates a sync per session the race would be invisible — no
 * error, no duplicate record, just a call that should never have been made.
 *
 * **Repeat failure is measured in age, because there is no attempt counter.**
 * `[21.8]` (c) asks for the orders that keep failing, and the schema has no
 * columns to record attempts in. Age is a sound stand-in here rather than a
 * shortcut: every order with a session is synced on the money path when it is
 * placed and again when the journey completes, so a row still null a day later
 * has outlived both of those and every sweep since. What age is *not* a
 * stand-in for is an order with no session, which has never been attempted at
 * all — those are reported separately, and calling them repeat failures would
 * send an operator looking for an outage that is not there.
 *
 * **Nothing here decides whether a sync worked; it reads the row.** The
 * reporting port returns nothing by contract (`[20.1]`), and that is the right
 * contract: an order that was charged is not un-charged because the EMR did not
 * hear about it. So the sweep asks the durable signal instead — the same column
 * it selected on — which also means it cannot be fooled by a reporter that
 * claims success and writes nothing.
 *
 * **This job must never crash the storefront** (`[20.1]`). Every EMR call is
 * wrapped even though the port forbids throwing, and a session that fails is
 * logged and followed by the next one.
 *
 * There is no scheduler in this codebase and this class does not become one.
 * It is run by {@see \AsterMD\Storefront\Console\ReconcileCommand}, which an
 * operator schedules externally.
 */
final class ForwardReconciliation
{
    /**
     * The one order state this sweep will report to the EMR.
     *
     * Only a charged order is a treatment. Today nothing writes an order row in
     * any other state — {@see \AsterMD\Storefront\Checkout\DatabaseOrderRecorder}
     * runs on the placement path alone — but a row in some other state must be
     * refused loudly rather than sent, and the refusal is counted so it cannot
     * become a sweep that silently finds nothing.
     */
    private const string PLACED = 'placed';

    /**
     * @param int $graceSeconds              how long after placement an order is left to the checkout path before a sweep touches it
     * @param int $repeatFailureAfterSeconds how long a row may stay null before it stops being a retry and becomes something to look at
     * @param int $batchSize                 the most orders one run will read; the monitored counts are not bounded by it
     */
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly CheckoutEventReporter $events,
        private readonly OperatorLog $log,
        private readonly int $graceSeconds = 900,
        private readonly int $repeatFailureAfterSeconds = 86400,
        private readonly int $batchSize = 200,
    ) {
    }

    /**
     * One sweep.
     *
     * `$apply` false is the safe default every outbound command in this theme
     * has: the backlog is counted and reported and nothing is sent.
     */
    public function run(bool $apply = false): ReconciliationReport
    {
        // Both cutoffs are computed once, before anything is read, so every
        // count and every row in this run is measured against the same instant.
        // Timestamps are `gmdate('c')` throughout the schema, a fixed-width UTC
        // format that sorts lexicographically in chronological order -- which
        // is the only reason a string comparison is a valid range query here.
        $now = time();
        $graceCutoff = gmdate('c', $now - $this->graceSeconds);
        $escalationCutoff = gmdate('c', $now - $this->repeatFailureAfterSeconds);

        $rows = $this->orders->findUnsyncedPlacedBefore($graceCutoff, $this->batchSize);

        /** @var array<string, list<string>> $batches one entry per analytics session, holding its unsynced references */
        $batches = [];
        /** @var list<array{reference: string, placed_at: string}> $aged candidates for `[21.8]` (c), confirmed after the attempt */
        $aged = [];
        $skipped = 0;

        foreach ($rows as $row) {
            $reason = self::refusalReasonFor($row);

            if ($reason !== null) {
                ++$skipped;
                $this->log->warning('reconcile.order_unreconcilable', [
                    'reference' => $row['provider_reference'],
                    'reason' => $reason,
                    'placed_at' => $row['placed_at'],
                ]);

                continue;
            }

            if ($row['placed_at'] !== null && $row['placed_at'] < $escalationCutoff) {
                // A list rather than a map keyed by reference: PHP turns a
                // numeric-string key into an integer, and these references are
                // digits at this provider -- so a map would quietly hand the
                // operator log an int where every other line carries a string.
                $aged[] = ['reference' => $row['provider_reference'], 'placed_at' => $row['placed_at']];
            }

            // Grouped by session because the EMR accumulates a session's orders
            // into one treatment record: recorded, five times now, that two
            // calls for two orders and one call for both leave the same single
            // record with both references on it. One call per order would reach
            // the same place the expensive way.
            $batches[(string) $row['session_uuid']][] = $row['provider_reference'];
        }

        $synced = 0;
        $failed = 0;
        /** @var list<string> $stillUnsynced references that were attempted and are still null */
        $stillUnsynced = [];

        if ($apply) {
            foreach ($batches as $sessionUuid => $references) {
                $this->syncBatch($sessionUuid, $references);

                foreach ($references as $reference) {
                    if ($this->wasStamped($reference)) {
                        ++$synced;
                        continue;
                    }

                    ++$failed;
                    $stillUnsynced[] = $reference;
                    $this->log->warning('reconcile.order_sync_failed', ['reference' => $reference]);
                }
            }
        }

        // `[21.8]` (c), and it is reported *after* the attempt rather than
        // before it: an order that has been stuck for a day but went through on
        // this run is not something an operator needs to look at, and naming it
        // anyway would train them to ignore the list. On a dry run nothing was
        // attempted, so every aged order is still stuck by definition.
        /** @var list<string> $repeatFailureReferences */
        $repeatFailureReferences = [];

        foreach ($aged as $candidate) {
            if ($apply && !in_array($candidate['reference'], $stillUnsynced, true)) {
                continue;
            }

            $repeatFailureReferences[] = $candidate['reference'];
            $this->log->warning('reconcile.repeat_failure', $candidate);
        }

        $report = new ReconciliationReport(
            unsynced: $this->orders->countUnsynced(),
            backlog: $this->orders->countUnsyncedPlacedBefore($graceCutoff),
            repeatFailures: $this->orders->countUnsyncedPlacedBefore($escalationCutoff),
            unreconcilable: $this->orders->countUnsyncedWithoutSession(),
            examined: count($rows),
            synced: $synced,
            failed: $failed,
            skipped: $skipped,
            repeatFailureReferences: $repeatFailureReferences,
            dryRun: !$apply,
        );

        // `[20.12]`: one line per run carrying every monitored count, whether
        // or not there was anything to do. A log that only speaks up when
        // something is wrong cannot be distinguished from one that has stopped
        // running.
        $this->log->info('reconcile.forward_sweep', $report->toArray());

        return $report;
    }

    /**
     * Why this row will not be sent, or null when it will be.
     *
     * @param array{id: int, provider_reference: string, session_uuid: ?string, status: string, placed_at: ?string} $row
     */
    private static function refusalReasonFor(array $row): ?string
    {
        if ($row['session_uuid'] === null) {
            // Not a failure and not backlog: the EMR keys a treatment record on
            // the session uuid, so there is nothing to file this order under,
            // and `[20.8]` forbids inventing an identifier to close the gap.
            // Retrying it on every sweep forever would report a queue that can
            // never drain.
            return 'no_session';
        }

        return $row['status'] === self::PLACED ? null : 'not_placed';
    }

    /**
     * One session's batch, with the whole containment contract around it.
     *
     * The port is documented never to throw and this catches anyway: `[20.1]`
     * is a property this job has to hold on its own, not one it borrows from an
     * implementation it does not choose. The provider's message is never
     * logged, for the reason the reporting boundary gives -- it is built from
     * the EMR's own text and this payload carries order references (`[20.6]`).
     *
     * @param list<string> $references
     */
    private function syncBatch(string $sessionUuid, array $references): void
    {
        try {
            $this->events->treatmentsSynced($sessionUuid, $references);
        } catch (\Throwable $e) {
            $this->log->warning('reconcile.batch_failed', [
                'session' => $sessionUuid,
                'orders' => count($references),
                'exception' => $e::class,
                'status' => $e->getCode(),
            ]);
        }
    }

    /**
     * Did the EMR actually learn about this order?
     *
     * Read back off the row rather than taken from the call, because the call
     * returns nothing to take. That is not a limitation to work around: the
     * column is the durable signal the whole predicate rests on, so asking it
     * is the only answer that stays true after this process exits — and a
     * reporter that reported success while writing nothing would be caught
     * here rather than believed.
     */
    private function wasStamped(string $reference): bool
    {
        try {
            return ($this->orders->findByReference($reference)['treatment_reference'] ?? null) !== null;
        } catch (\Throwable $e) {
            $this->log->warning('reconcile.read_back_failed', [
                'reference' => $reference,
                'exception' => $e::class,
                'status' => $e->getCode(),
            ]);

            // An order whose row could not be re-read is not an order known to
            // have synced. Counting it as one would retire it from the figure
            // an operator watches on the strength of a database error.
            return false;
        }
    }
}
