<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Reconciliation;

use AsterMD\Storefront\Reconciliation\ForwardReconciliation;
use AsterMD\Storefront\Repository\OrderRepository;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;

/**
 * `[21.8]`'s forward sweep: find the orders the EMR was never told about, tell
 * it, and say which ones still will not go.
 *
 * Most of what is worth pinning here is an absence. A sweep that syncs
 * something it should have left alone is silent — the EMR deduplicates per
 * session, so a wrongly re-sent order produces no error and no duplicate
 * record, just a call that should never have been made. The three that matter
 * most: a freshly placed order inside the grace window is not swept, a failed
 * sync does not stamp the row, and a row that already carries a treatment
 * reference is never sent again.
 */
final class ForwardReconciliationTest extends TestCase
{
    use TempDatabase;

    private const string SESSION = '9c1d5e8a-3b7f-4a2c-8e6d-1f4b9a7c2e50';

    private const string OTHER_SESSION = '2e7a4c19-5d8b-4f3e-9a1c-6b0d8e2f7a34';

    private \PDO $pdo;

    private OrderRepository $orders;

    private CapturedLog $log;

    protected function setUp(): void
    {
        $this->pdo = $this->tempPdo();
        $this->orders = new OrderRepository(fn (): \PDO => $this->pdo);
        $this->log = new CapturedLog();

        $sessions = new SessionRepository(fn (): \PDO => $this->pdo);
        $sessions->insert(self::SESSION, [], null);
        $sessions->insert(self::OTHER_SESSION, [], null);
    }

    public function testAnOrderTheEmrWasNeverToldAboutIsSyncedAndStamped(): void
    {
        $this->seedOrder('34660', self::ago(3600));
        $reporter = new RecordingTreatmentReporter($this->orders);

        $report = $this->sweep($reporter)->run(apply: true);

        self::assertSame([['session' => self::SESSION, 'references' => ['34660']]], $reporter->syncs);
        self::assertSame('6a8c72619f9b46188233413b', $this->treatmentReferenceOf('34660'));
        self::assertSame(1, $report->synced);
        self::assertSame(0, $report->failed);
    }

    public function testAFreshlyPlacedOrderInsideTheGraceWindowIsNotSwept(): void
    {
        // The absence the whole grace window exists for. The request that
        // placed this order reports it in the same breath, so a sweep that
        // picked it up would be racing a call already in flight.
        $this->seedOrder('34660', self::ago(5));
        $reporter = new RecordingTreatmentReporter($this->orders);

        $report = $this->sweep($reporter)->run(apply: true);

        self::assertSame([], $reporter->syncs, 'a five-second-old order is not a failed one');
        self::assertNull($this->treatmentReferenceOf('34660'));
        self::assertSame(0, $report->examined);
    }

    public function testAnOrderThatAlreadyCarriesATreatmentReferenceIsNeverSyncedAgain(): void
    {
        // The stamped column is the sweep's only "leave this alone" signal.
        $id = $this->seedOrder('34660', self::ago(3600));
        $this->orders->markTreatmentSynced($id, '6a8c72619f9b46188233413b');
        $reporter = new RecordingTreatmentReporter($this->orders);

        $report = $this->sweep($reporter)->run(apply: true);

        self::assertSame([], $reporter->syncs);
        self::assertSame(0, $report->examined);
        self::assertSame(0, $report->unsynced);
    }

    public function testASyncTheEmrRefusedLeavesTheRowUnstampedAndIsReported(): void
    {
        // A row that stays null is the failure signal, and it has to stay null:
        // stamping a refusal would retire the order from every future sweep
        // while the EMR still knows nothing about it.
        $this->seedOrder('34660', self::ago(3600));
        $reporter = new RecordingTreatmentReporter($this->orders, failing: ['34660']);

        $report = $this->sweep($reporter)->run(apply: true);

        self::assertNull($this->treatmentReferenceOf('34660'));
        self::assertSame(1, $report->failed);
        self::assertSame(0, $report->synced);
        self::assertSame('34660', $this->log->eventsNamed('reconcile.order_sync_failed')[0]['context']['reference'] ?? null);
    }

    public function testOneOrderThatWillNotSyncDoesNotStopTheNextOneFromBeingTried(): void
    {
        // `[20.1]`: a reporting failure is logged and the job continues.
        $this->seedOrder('34660', self::ago(7200), session: self::SESSION);
        $this->seedOrder('34661', self::ago(3600), session: self::OTHER_SESSION);
        $reporter = new RecordingTreatmentReporter($this->orders, failing: ['34660']);

        $report = $this->sweep($reporter)->run(apply: true);

        self::assertNull($this->treatmentReferenceOf('34660'));
        self::assertSame('6a8c72619f9b46188233413b', $this->treatmentReferenceOf('34661'));
        self::assertSame(1, $report->failed);
        self::assertSame(1, $report->synced);
    }

    public function testAReporterThatThrowsIsSwallowedSoAScheduledJobNeverCrashes(): void
    {
        // The port forbids throwing, and this catches anyway: `[20.1]` is a
        // property of the storefront, not a promise held somewhere else.
        $this->seedOrder('34660', self::ago(7200), session: self::SESSION);
        $this->seedOrder('34661', self::ago(3600), session: self::OTHER_SESSION);
        $reporter = new RecordingTreatmentReporter($this->orders, throwing: true);

        $report = $this->sweep($reporter)->run(apply: true);

        self::assertCount(2, $reporter->syncs, 'the second session was still tried');
        self::assertSame(2, $report->failed);
        self::assertNull($this->treatmentReferenceOf('34660'));
    }

    public function testOrdersOnOneSessionAreSentAsASingleBatchBecauseTheEmrAccumulatesThem(): void
    {
        // Recorded: one session's orders become one treatment record with every
        // reference under `external_refs.vrio`. One call per order would be the
        // same record, reached the expensive way.
        $this->seedOrder('34787', self::ago(7200));
        $this->seedOrder('34734', self::ago(3600));
        $reporter = new RecordingTreatmentReporter($this->orders);

        $report = $this->sweep($reporter)->run(apply: true);

        self::assertSame([['session' => self::SESSION, 'references' => ['34787', '34734']]], $reporter->syncs);
        self::assertSame(2, $report->synced);
    }

    public function testAnOrderWithNoAnalyticsSessionIsSkippedRatherThanRetriedOnEverySweep(): void
    {
        // The EMR keys a treatment on the session, so there is nothing to file
        // this under and no retry will ever change that. `[20.8]` forbids
        // inventing one, so it is reported and left.
        $this->seedOrder('34660', self::ago(3600), session: null);
        $reporter = new RecordingTreatmentReporter($this->orders);

        $report = $this->sweep($reporter)->run(apply: true);

        self::assertSame([], $reporter->syncs, 'a sync with no session would be a call about nothing');
        self::assertSame(1, $report->skipped);
        self::assertSame(0, $report->failed, 'it did not fail; it can never be attempted');
        self::assertSame('no_session', $this->log->eventsNamed('reconcile.order_unreconcilable')[0]['context']['reason'] ?? null);
    }

    public function testAnOrderRecordedInAStateOtherThanPlacedIsNotReportedAsATreatment(): void
    {
        // Only a charged order belongs in the EMR. A row in any other state is
        // counted and named rather than dropped, because a sweep that silently
        // found nothing is the failure this sweep exists to end.
        $this->seedOrder('34660', self::ago(3600), status: 'pending_action');
        $reporter = new RecordingTreatmentReporter($this->orders);

        $report = $this->sweep($reporter)->run(apply: true);

        self::assertSame([], $reporter->syncs);
        self::assertSame(1, $report->skipped);
        self::assertSame('not_placed', $this->log->eventsNamed('reconcile.order_unreconcilable')[0]['context']['reason'] ?? null);
    }

    public function testAnOrderStillUnsyncedLongAfterPlacementIsReportedForOperatorAttention(): void
    {
        // `[21.8]` (c). There is no attempt counter in the schema, so age is
        // the counter: an order still null a day after placement has outlived
        // the sync on the money path, the one at the receipt, and every sweep
        // since.
        $this->seedOrder('34660', self::ago(200000));
        $reporter = new RecordingTreatmentReporter($this->orders, failing: ['34660']);

        $report = $this->sweep($reporter)->run(apply: true);

        self::assertSame(['34660'], $report->repeatFailureReferences);
        self::assertSame(1, $report->repeatFailures);
        self::assertSame('34660', $this->log->eventsNamed('reconcile.repeat_failure')[0]['context']['reference'] ?? null);
    }

    public function testAnOrderPastTheGraceWindowButInsideTheEscalationWindowIsNotCalledARepeatFailure(): void
    {
        // The absence beside it: one failed attempt is a retry, not an alert.
        $this->seedOrder('34660', self::ago(3600));
        $reporter = new RecordingTreatmentReporter($this->orders, failing: ['34660']);

        $report = $this->sweep($reporter)->run(apply: true);

        self::assertSame([], $report->repeatFailureReferences);
        self::assertSame(0, $report->repeatFailures);
        self::assertSame([], $this->log->eventsNamed('reconcile.repeat_failure'));
        self::assertSame(1, $report->failed, 'it did fail; it has just not failed for long enough to escalate');
    }

    public function testAnOrderWithNoSessionIsNeverCalledARepeatFailureHoweverOldItIs(): void
    {
        // It has not repeatedly failed to sync -- it has never been attempted,
        // and an alert saying otherwise sends an operator looking for an outage
        // that is not there.
        $this->seedOrder('34660', self::ago(200000), session: null);
        $reporter = new RecordingTreatmentReporter($this->orders);

        $report = $this->sweep($reporter)->run(apply: true);

        self::assertSame([], $report->repeatFailureReferences);
        self::assertSame(0, $report->repeatFailures);
        self::assertSame(1, $report->unreconcilable);
    }

    public function testADryRunReportsTheBacklogAndSendsNothingAndStampsNothing(): void
    {
        // Every outbound command in this theme is safe by default.
        $this->seedOrder('34660', self::ago(3600));
        $reporter = new RecordingTreatmentReporter($this->orders);

        $report = $this->sweep($reporter)->run(apply: false);

        self::assertSame([], $reporter->syncs, 'a dry run reaches no network');
        self::assertNull($this->treatmentReferenceOf('34660'), 'and writes nothing');
        self::assertTrue($report->dryRun);
        self::assertSame(1, $report->examined);
        self::assertSame(0, $report->synced);
        self::assertSame(0, $report->failed);
    }

    public function testTheMonitoredCountsCoverTheWholeTableAndNotJustThisRun(): void
    {
        // `[20.12]` monitors "orders never synced to the EMR" and the
        // reconciliation backlog. A figure bounded by the batch size would
        // read as healthy on exactly the deployment whose backlog had
        // overflowed it.
        $this->seedOrder('34660', self::ago(5));
        $this->seedOrder('34661', self::ago(3600));
        $this->seedOrder('34662', self::ago(7200));
        $this->seedOrder('34663', self::ago(200000), session: null);
        $reporter = new RecordingTreatmentReporter($this->orders, failing: ['34661', '34662']);

        $report = $this->sweep($reporter, batchSize: 1)->run(apply: true);

        self::assertSame(4, $report->unsynced);
        self::assertSame(2, $report->backlog, 'past the grace window and with a session to sync under');
        self::assertSame(1, $report->unreconcilable);
        self::assertSame(1, $report->examined, 'and the run itself is bounded');
    }

    public function testTheSweepEmitsOneOperatorLineCarryingEveryMonitoredCount(): void
    {
        // The table-wide counts are what the run leaves behind, not what it
        // found: a line reporting the backlog it had just drained would alert
        // on healthy runs and read the same on broken ones.
        $this->seedOrder('34660', self::ago(3600));
        $this->seedOrder('34661', self::ago(3600), session: null);
        $reporter = new RecordingTreatmentReporter($this->orders);

        $this->sweep($reporter)->run(apply: true);

        $line = $this->log->eventsNamed('reconcile.forward_sweep')[0]['context'] ?? [];

        self::assertSame(1, $line['unsynced'] ?? null, 'the sessionless order is still outstanding');
        self::assertSame(0, $line['backlog'] ?? null, 'and the one that could be drained was');
        self::assertSame(0, $line['repeat_failures'] ?? null);
        self::assertSame(1, $line['unreconcilable'] ?? null);
        self::assertSame(1, $line['synced'] ?? null);
        self::assertSame(0, $line['failed'] ?? null);
        self::assertSame(1, $line['skipped'] ?? null);
        self::assertFalse($line['dry_run'] ?? null);
    }

    public function testTheBatchSizeBoundsWhatOneRunWillTouch(): void
    {
        foreach (['34660', '34661', '34662'] as $index => $reference) {
            $this->seedOrder($reference, self::ago(7200 - $index));
        }
        $reporter = new RecordingTreatmentReporter($this->orders);

        $report = $this->sweep($reporter, batchSize: 2)->run(apply: true);

        self::assertSame(2, $report->examined);
        self::assertSame(2, $report->synced);
        self::assertSame(
            1,
            $report->unsynced,
            'the count sees the row beyond the page, so an overflowing backlog cannot read as drained',
        );
    }

    public function testASweepOverNothingIsQuietRatherThanAnError(): void
    {
        $reporter = new RecordingTreatmentReporter($this->orders);

        $report = $this->sweep($reporter)->run(apply: true);

        self::assertSame([], $reporter->syncs);
        self::assertSame(0, $report->unsynced);
        self::assertSame([], $this->log->eventsNamed('reconcile.repeat_failure'));
    }

    private function sweep(RecordingTreatmentReporter $reporter, int $batchSize = 50): ForwardReconciliation
    {
        return new ForwardReconciliation(
            $this->orders,
            $reporter,
            $this->log->log,
            graceSeconds: 300,
            repeatFailureAfterSeconds: 86400,
            batchSize: $batchSize,
        );
    }

    private function treatmentReferenceOf(string $reference): ?string
    {
        return $this->orders->findByReference($reference)['treatment_reference'] ?? null;
    }

    private function seedOrder(string $reference, string $placedAt, ?string $session = self::SESSION, string $status = 'placed'): int
    {
        return $this->orders->insert(
            [
                'session_uuid' => $session,
                'provider_reference' => $reference,
                'anchor_slug' => 'tirzepatide',
                'amount_cents' => 10800,
                'currency' => 'USD',
                'status' => $status,
                'placed_at' => $placedAt,
            ],
            [],
            [],
        );
    }

    private static function ago(int $seconds): string
    {
        return gmdate('c', time() - $seconds);
    }
}
