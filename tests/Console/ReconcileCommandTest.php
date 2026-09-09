<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Console;

use AsterMD\Storefront\Console\ReconcileCommand;
use AsterMD\Storefront\Reconciliation\ForwardReconciliation;
use AsterMD\Storefront\Repository\OrderRepository;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Tests\Reconciliation\RecordingTreatmentReporter;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command an operator schedules.
 *
 * What is worth pinning at this level is the safety of the default and the
 * exit code, because those are the two things a scheduler acts on. Everything
 * about which orders get swept belongs to {@see ForwardReconciliation} and is
 * tested there.
 */
final class ReconcileCommandTest extends TestCase
{
    use TempDatabase;

    private const string SESSION = '9c1d5e8a-3b7f-4a2c-8e6d-1f4b9a7c2e50';

    private \PDO $pdo;

    private OrderRepository $orders;

    private CapturedLog $log;

    protected function setUp(): void
    {
        $this->pdo = $this->tempPdo();
        $this->orders = new OrderRepository(fn (): \PDO => $this->pdo);
        $this->log = new CapturedLog();

        (new SessionRepository(fn (): \PDO => $this->pdo))->insert(self::SESSION, [], null);
    }

    public function testRunningItWithNoArgumentsSendsNothingBecauseTheDefaultIsADryRun(): void
    {
        // The absence that matters: an operator who runs this to see what it
        // would do must not discover that it did it.
        $this->seedOrder('34660');
        $reporter = new RecordingTreatmentReporter($this->orders);
        $tester = new CommandTester($this->command($reporter));

        self::assertSame(0, $tester->execute([]));
        self::assertSame([], $reporter->syncs);
        self::assertNull($this->treatmentReferenceOf('34660'));
        self::assertStringContainsString('dry run', $tester->getDisplay());
    }

    public function testApplySendsTheBacklogAndStampsWhatTheEmrAccepted(): void
    {
        $this->seedOrder('34660');
        $reporter = new RecordingTreatmentReporter($this->orders);
        $tester = new CommandTester($this->command($reporter));

        self::assertSame(0, $tester->execute(['--apply' => true]));
        self::assertSame([['session' => self::SESSION, 'references' => ['34660']]], $reporter->syncs);
        self::assertSame('6a8c72619f9b46188233413b', $this->treatmentReferenceOf('34660'));
    }

    public function testAnExplicitDryRunOverridesApplySoAScheduledJobCanBeNeuteredInPlace(): void
    {
        // The two flags are not symmetrical on purpose. `--apply` is the opt-in
        // every outbound command here has; `--dry-run` is the brake, and a
        // brake that could be overridden by the flag already in the crontab
        // would not be one.
        $this->seedOrder('34660');
        $reporter = new RecordingTreatmentReporter($this->orders);
        $tester = new CommandTester($this->command($reporter));

        self::assertSame(0, $tester->execute(['--apply' => true, '--dry-run' => true]));
        self::assertSame([], $reporter->syncs);
        self::assertNull($this->treatmentReferenceOf('34660'));
    }

    public function testOrdersThatKeepFailingAreNamedInTheOutputForOperatorAttention(): void
    {
        // `[21.8]` (c). The operator reads this output; the counts also go to
        // the operator log for whatever watches that.
        $this->seedOrder('34660', ago: 200000);
        $reporter = new RecordingTreatmentReporter($this->orders, failing: ['34660']);
        $tester = new CommandTester($this->command($reporter));

        $tester->execute(['--apply' => true]);

        self::assertStringContainsString('34660', $tester->getDisplay());
        self::assertStringContainsString('repeat', strtolower($tester->getDisplay()));
    }

    public function testOrdersThatSyncedCleanlyLeaveNoRepeatFailureNoticeBehind(): void
    {
        $this->seedOrder('34660', ago: 200000);
        $reporter = new RecordingTreatmentReporter($this->orders);
        $tester = new CommandTester($this->command($reporter));

        $tester->execute(['--apply' => true]);

        self::assertStringNotContainsString('repeat', strtolower($tester->getDisplay()));
    }

    public function testAnOrderThatWillNotSyncIsReportedWithoutFailingTheRun(): void
    {
        // `[20.1]`: a sync failure is a fact to report, not a reason for the
        // job to exit non-zero. A scheduler that alerted on it would alert on
        // every run until an operator fixed the order by hand, and re-running
        // the command changes nothing about the order that is stuck.
        $this->seedOrder('34660');
        $reporter = new RecordingTreatmentReporter($this->orders, failing: ['34660']);
        $tester = new CommandTester($this->command($reporter));

        self::assertSame(0, $tester->execute(['--apply' => true]));
        self::assertStringContainsString('failed', strtolower($tester->getDisplay()));
    }

    public function testASweepThatCannotEvenReadTheOrdersFailsLoudlyInsteadOfReportingZero(): void
    {
        // The one case that is worth an exit code: a run that reported "0
        // outstanding" because the database was unreachable is the failure this
        // whole sweep exists to prevent, one level up.
        $reporter = new RecordingTreatmentReporter($this->orders);
        $command = new ReconcileCommand(new ForwardReconciliation(
            new OrderRepository(static fn (): \PDO => throw new \RuntimeException('no database')),
            $reporter,
            $this->log->log,
        ));
        $tester = new CommandTester($command);

        self::assertSame(1, $tester->execute(['--apply' => true]));
        self::assertStringContainsString('RuntimeException', $tester->getDisplay());
    }

    /**
     * The same policy {@see \AsterMD\Storefront\Checkout\EmrCheckoutEventReporter}
     * states for the EMR's free text, applied to the sink that has no
     * redaction at all: a cron job's stdout.
     */
    public function testAFailureReportsTheExceptionClassAndNeverItsMessage(): void
    {
        $refusal = new \PDOException(
            "SQLSTATE[22001]: Data too long for column 'journey_state' at row 'dana@example.com'",
        );
        $refusal->errorInfo = ['22001', 1406, "Data too long for column 'journey_state' at row 'dana@example.com'"];

        $command = new ReconcileCommand(new ForwardReconciliation(
            new OrderRepository(static fn (): \PDO => throw $refusal),
            new RecordingTreatmentReporter($this->orders),
            $this->log->log,
        ));
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringNotContainsString('dana@example.com', $tester->getDisplay());
        self::assertStringContainsString('PDOException', $tester->getDisplay());
        self::assertStringContainsString('22001', $tester->getDisplay());
    }

    public function testTheCountsReachTheOutputSoAnOperatorSeesTheBacklogWithoutReadingTheLog(): void
    {
        $this->seedOrder('34660');
        $this->seedOrder('34661', session: null);
        $tester = new CommandTester($this->command(new RecordingTreatmentReporter($this->orders)));

        $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('2', $display, 'two orders the EMR has never been told about');
        self::assertStringContainsString('unreconcilable', strtolower($display));
    }

    private function command(RecordingTreatmentReporter $reporter): ReconcileCommand
    {
        return new ReconcileCommand(new ForwardReconciliation(
            $this->orders,
            $reporter,
            $this->log->log,
            graceSeconds: 300,
            repeatFailureAfterSeconds: 86400,
            batchSize: 50,
        ));
    }

    private function treatmentReferenceOf(string $reference): ?string
    {
        return $this->orders->findByReference($reference)['treatment_reference'] ?? null;
    }

    private function seedOrder(string $reference, int $ago = 3600, ?string $session = self::SESSION): void
    {
        $this->orders->insert(
            [
                'session_uuid' => $session,
                'provider_reference' => $reference,
                'anchor_slug' => 'tirzepatide',
                'amount_cents' => 10800,
                'currency' => 'USD',
                'status' => 'placed',
                'placed_at' => gmdate('c', time() - $ago),
            ],
            [],
            [],
        );
    }
}
