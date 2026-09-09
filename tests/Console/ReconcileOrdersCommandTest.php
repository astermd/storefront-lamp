<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Console;

use AsterMD\Storefront\Console\ReconcileCommand;
use AsterMD\Storefront\Console\ReconcileOrdersCommand;
use AsterMD\Storefront\Payment\NullPaymentAdapter;
use AsterMD\Storefront\Payment\PaymentAdapter;
use AsterMD\Storefront\Payment\Vrio\VrioAdapter;
use AsterMD\Storefront\Payment\Vrio\VrioApiFactory;
use AsterMD\Storefront\Payment\Vrio\VrioCredentials;
use AsterMD\Storefront\Reconciliation\ReverseReconciliation;
use AsterMD\Storefront\Repository\OrderRepository;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\FakeVrioTransport;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use AsterMD\Storefront\Tests\Support\UnreadableOrdersPdo;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command an operator schedules for `[21.9a]`'s reverse sweep.
 *
 * What is worth pinning at this level is the exit code and the name, because
 * those are the two things a scheduler acts on. Which orders get reported
 * belongs to {@see ReverseReconciliation} and is tested there.
 */
final class ReconcileOrdersCommandTest extends TestCase
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

    public function testItDoesNotTakeTheNameTheForwardSweepAlreadyHas(): void
    {
        // The two reconciliation jobs that ship sweep in opposite directions.
        // Registering both under one name would leave whichever loaded second
        // silently unreachable.
        self::assertSame('emr:reconcile', self::commandNameOf(ReconcileCommand::class));
        self::assertSame('provider:reconcile', self::commandNameOf(ReconcileOrdersCommand::class));
    }

    public function testItNamesEveryLostChargeSoAnOperatorCanLookItUp(): void
    {
        // The reference is the whole recovery path under the 2026-08-25
        // ruling: the sweep detects, a person investigates in the provider's
        // own dashboard.
        $tester = new CommandTester($this->command($this->vrio('vrio-order-search-mixed.json')));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('34788', $tester->getDisplay());
        self::assertStringContainsString('1', $tester->getDisplay());
    }

    public function testALostChargeDoesNotFailTheRunBecauseRerunningItChangesNothing(): void
    {
        // The exit code answers "did this job work", not "is everything
        // reconciled". An orphan needs a person; failing every run until they
        // act is how an alert gets muted.
        $tester = new CommandTester($this->command($this->vrio('vrio-order-search-mixed.json')));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
    }

    public function testAProviderThatCannotBeAskedIsNotAFailedRun(): void
    {
        $tester = new CommandTester($this->command(new NullPaymentAdapter()));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('does not support', $tester->getDisplay());
    }

    public function testAnUnansweredSearchFailsTheRunRatherThanReportingAllClear(): void
    {
        // A run that reported "nothing lost" because the provider was
        // unreachable is exactly the silent failure this sweep exists to end,
        // and the scheduler is the only thing that can notice.
        $transport = new FakeVrioTransport();
        $transport->queue(0, '', 'Could not resolve host: api.vrio.app');

        $tester = new CommandTester($this->command($this->adapterFor($transport)));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('measured nothing', $tester->getDisplay());
    }

    public function testTheWindowCanBeShortenedFromTheCommandLine(): void
    {
        // What the truncation warning asks an operator to do: run more often
        // over a shorter window rather than read more per run.
        $transport = new FakeVrioTransport();
        $transport->queueFixture('vrio-order-search-empty.json');

        $tester = new CommandTester($this->command($this->adapterFor($transport)));
        $tester->execute(['--hours' => '6']);

        parse_str((string) parse_url($transport->requests[0]->getUrl(), PHP_URL_QUERY), $query);

        $from = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            (string) $query['date_created_from'],
            new \DateTimeZone('UTC'),
        );

        self::assertInstanceOf(\DateTimeImmutable::class, $from);
        self::assertEqualsWithDelta(time() - 6 * 3600, $from->getTimestamp(), 60.0);
    }

    public function testAnUnusableWindowIsRefusedRatherThanSweptAsEmpty(): void
    {
        $transport = new FakeVrioTransport();
        $transport->queueFixture('vrio-order-search-empty.json');

        $tester = new CommandTester($this->command($this->adapterFor($transport)));

        self::assertSame(Command::FAILURE, $tester->execute(['--hours' => '0']));
        self::assertSame([], $transport->requests, 'nothing was asked of the provider');
    }


    public function testALocalReadOutageFailsTheRunRatherThanReportingZeroLostCharges(): void
    {
        // The provider answered and the local table did not, which leaves the
        // headline figure exactly as unmeasured as an unanswered search does.
        $blind = new OrderRepository(static fn (): \PDO => UnreadableOrdersPdo::alongside($this->pdo));
        $tester = new CommandTester($this->command($this->vrio('vrio-order-search-mixed.json'), $blind));

        $exit = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exit, $tester->getDisplay());
        self::assertStringContainsString('could not be checked', $tester->getDisplay());
    }

    public function testATruncatedWindowFailsTheRunBecauseItsHeadlineFigureIsOnlyAFloor(): void
    {
        // A run that read 4 of 143 and a run that read its whole window must
        // not hand the scheduler the same answer.
        $complete = new CommandTester($this->command($this->vrio('vrio-order-search-empty.json')));
        $truncated = new CommandTester($this->command($this->vrio('vrio-order-search-truncated.json')));

        self::assertSame(Command::SUCCESS, $complete->execute([]));
        self::assertSame(Command::FAILURE, $truncated->execute([]), $truncated->getDisplay());
        self::assertStringContainsString('4 of 143', $truncated->getDisplay());
    }

    public function testAFailedRunReportsTheExceptionClassAndNeverItsMessage(): void
    {
        // A cron job's stdout is mail spool, CI artefact and shell history at
        // once, and a driver is free to quote the row it refused. Same policy
        // the journey save-back states: the class and the SQLSTATE, never the
        // free text.
        $refusal = new \PDOException('SQLSTATE[HY000]: order 34788 for dana@example.com is locked');
        $refusal->errorInfo = ['HY000', 1, 'order 34788 for dana@example.com is locked'];

        $command = new ReconcileOrdersCommand(new ReverseReconciliation(
            static fn (): PaymentAdapter => throw $refusal,
            $this->orders,
            $this->log->log,
        ));
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringNotContainsString('dana@example.com', $tester->getDisplay());
        self::assertStringNotContainsString('34788', $tester->getDisplay());
        self::assertStringContainsString('PDOException', $tester->getDisplay());
        self::assertStringContainsString('HY000', $tester->getDisplay());
    }

    public function testAnUnreadableHoursValueIsRefusedRatherThanSweptAtTheDefaultWindow(): void
    {
        // A crontab typo must not sweep a window nobody asked for and then
        // report success: the figure would be honest about a window the
        // operator does not believe was measured.
        $transport = new FakeVrioTransport();
        $transport->queueFixture('vrio-order-search-empty.json');

        $tester = new CommandTester($this->command($this->adapterFor($transport)));

        self::assertSame(Command::FAILURE, $tester->execute(['--hours' => 'six']));
        self::assertSame([], $transport->requests, 'nothing was asked of the provider');
    }

    public function testAnUnusableWindowStillTellsTheOperatorWhichOptionToChange(): void
    {
        $tester = new CommandTester($this->command($this->vrio('vrio-order-search-empty.json')));

        self::assertSame(Command::FAILURE, $tester->execute(['--hours' => '0.1']));
        self::assertStringContainsString('--hours', $tester->getDisplay());
    }

    /** @param class-string $command */
    private static function commandNameOf(string $command): ?string
    {
        $attribute = (new \ReflectionClass($command))->getAttributes(AsCommand::class)[0] ?? null;

        return $attribute?->newInstance()->name;
    }

    private function command(PaymentAdapter $adapter, ?OrderRepository $orders = null): ReconcileOrdersCommand
    {
        return new ReconcileOrdersCommand(new ReverseReconciliation(
            static fn (): PaymentAdapter => $adapter,
            $orders ?? $this->orders,
            $this->log->log,
            lookbackSeconds: 172800,
            graceSeconds: 900,
        ));
    }

    private function vrio(string $fixture): VrioAdapter
    {
        $transport = new FakeVrioTransport();
        $transport->queueFixture($fixture);

        return $this->adapterFor($transport);
    }

    private function adapterFor(FakeVrioTransport $transport): VrioAdapter
    {
        return new VrioAdapter(
            new VrioCredentials('api.vrio.app', '', 'test-key', 147, 1),
            new VrioApiFactory($transport),
            $this->log->log,
            shippingProfileId: 1,
        );
    }
}
