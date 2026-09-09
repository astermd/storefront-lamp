<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Console;

use AsterMD\Storefront\Console\StatusCommand;
use AsterMD\Storefront\Observability\OperationalCounts;
use AsterMD\Storefront\Reconciliation\ReverseReconciliationReport;
use AsterMD\Storefront\Repository\OrderRepository;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class StatusCommandTest extends TestCase
{
    use TempDatabase;

    private const int NOW = 1_800_000_000;

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->tempPdo();
    }

    private function command(?\PDO $pdo = null, ?\Closure $providerSweep = null): StatusCommand
    {
        $pdo ??= $this->pdo;
        $resolve = fn (): \PDO => $pdo;

        return new StatusCommand(
            orders: new OrderRepository($resolve),
            counts: new OperationalCounts($resolve, clock: static fn (): int => self::NOW),
            providerSweep: $providerSweep,
            clock: static fn (): int => self::NOW,
        );
    }

    /** @param array<string, mixed> $payload */
    private function event(string $sessionUuid, string $name, array $payload = []): void
    {
        $this->pdo->prepare('INSERT INTO events (session_uuid, name, payload, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$sessionUuid, $name, (string) json_encode($payload), gmdate('c', self::NOW - 60)]);
    }

    private function order(?string $sessionUuid, ?string $treatmentReference, int $placedAgoSeconds): void
    {
        if ($sessionUuid !== null) {
            $this->pdo->prepare(
                'INSERT INTO sessions (session_uuid, opportunity_id, journey_state, attribution, created_at, updated_at)
                 VALUES (?, NULL, ?, NULL, ?, ?)',
            )->execute([$sessionUuid, '{}', gmdate('c', self::NOW), gmdate('c', self::NOW)]);
        }

        $this->pdo->prepare(
            'INSERT INTO orders (session_uuid, provider_reference, anchor_slug, amount_cents, currency, status,
                                 treatment_reference, placed_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $sessionUuid,
            'ref-' . bin2hex(random_bytes(4)),
            'slug',
            1000,
            'USD',
            'placed',
            $treatmentReference,
            gmdate('c', self::NOW - $placedAgoSeconds),
            gmdate('c', self::NOW - $placedAgoSeconds),
            gmdate('c', self::NOW - $placedAgoSeconds),
        ]);
    }

    /** @return array<string, mixed> */
    private function json(CommandTester $tester): array
    {
        $decoded = json_decode(trim($tester->getDisplay()), true);
        self::assertIsArray($decoded, 'the command must emit a parseable JSON document');

        return $decoded;
    }

    public function testAllFiveMonitoredCountsAppearInOnePlace(): void
    {
        $this->order('s-1', null, 7200);
        $this->event('s-1', 'checkout.visited');
        $this->event('', 'checkout.visited');
        $this->event('s-1', 'checkout.order_declined', ['reason' => 'Insufficient funds']);

        $tester = new CommandTester($this->command());
        $tester->execute(['--json' => true]);

        $payload = $this->json($tester);

        self::assertArrayHasKey('orders_placed_at_provider_not_recorded_locally', $payload);
        self::assertArrayHasKey('orders_recorded_locally_never_synced', $payload);
        self::assertArrayHasKey('reconciliation_backlog', $payload);
        self::assertArrayHasKey('checkouts_without_emr_session', $payload);
        self::assertArrayHasKey('declines', $payload);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testTheLocalCountsAreReadFromTheSameQueriesTheForwardSweepUses(): void
    {
        $this->order('s-1', null, 7200);
        $this->order('s-2', 'treatment-1', 7200);
        $this->order(null, null, 7200);

        $tester = new CommandTester($this->command());
        $tester->execute(['--json' => true]);
        $payload = $this->json($tester);

        self::assertSame(2, $payload['orders_recorded_locally_never_synced']['value']);
        self::assertSame(1, $payload['reconciliation_backlog']['value']);
        self::assertSame(1, $payload['unreconcilable']['value']);
    }

    /**
     * The provider half needs an outbound call, so it is opt-in — and what it
     * reports when it was not asked must never be a zero an operator could
     * read as an all-clear.
     */
    public function testTheProviderCountIsNotCheckedRatherThanZeroWhenItWasNotAsked(): void
    {
        $tester = new CommandTester($this->command());
        $tester->execute(['--json' => true]);

        $reported = $this->json($tester)['orders_placed_at_provider_not_recorded_locally'];
        self::assertNull($reported['value']);
        self::assertSame('not_checked', $reported['state']);
    }

    public function testTheProviderCountIsReportedWhenTheSweepIsRequested(): void
    {
        $tester = new CommandTester($this->command(providerSweep: static fn (): ReverseReconciliationReport =>
            new ReverseReconciliationReport(examined: 10, matched: 9, lostCharges: 1)));
        $tester->execute(['--provider' => true, '--json' => true]);

        $reported = $this->json($tester)['orders_placed_at_provider_not_recorded_locally'];
        self::assertSame(1, $reported['value']);
        self::assertSame('measured', $reported['state']);
    }

    /**
     * The shape a previous sweep shipped and a review caught: the provider
     * could not be read at all and the run reported "0 lost charges", exit 0.
     */
    public function testAFailedProviderSearchIsNeverRenderedAsZeroLostCharges(): void
    {
        $tester = new CommandTester($this->command(providerSweep: static fn (): ReverseReconciliationReport =>
            new ReverseReconciliationReport(searchFailed: true, failureReason: 'transport_error')));
        $status = $tester->execute(['--provider' => true, '--json' => true]);

        $reported = $this->json($tester)['orders_placed_at_provider_not_recorded_locally'];
        self::assertNull($reported['value']);
        self::assertSame('unavailable', $reported['state']);
        self::assertSame(Command::FAILURE, $status);
    }

    public function testATruncatedProviderSweepReportsAFloorRatherThanATotal(): void
    {
        $tester = new CommandTester($this->command(providerSweep: static fn (): ReverseReconciliationReport =>
            new ReverseReconciliationReport(examined: 200, truncated: true, lostCharges: 2)));
        $status = $tester->execute(['--provider' => true, '--json' => true]);

        $reported = $this->json($tester)['orders_placed_at_provider_not_recorded_locally'];
        self::assertSame(2, $reported['value']);
        self::assertSame('floor', $reported['state']);
        self::assertSame(Command::FAILURE, $status);
    }

    public function testAProviderSweepThatThrowsIsReportedRatherThanCrashingTheCommand(): void
    {
        $tester = new CommandTester($this->command(providerSweep: static fn (): never =>
            throw new \RuntimeException('provider credentials rejected for account 998877')));
        $status = $tester->execute(['--provider' => true, '--json' => true]);

        $reported = $this->json($tester)['orders_placed_at_provider_not_recorded_locally'];
        self::assertSame('unavailable', $reported['state']);
        self::assertStringNotContainsString('998877', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $status);
    }

    public function testALocalCountThatCouldNotBeReadFailsTheRunRatherThanReportingZero(): void
    {
        $broken = new \PDO('sqlite::memory:');
        $broken->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $tester = new CommandTester($this->command($broken));
        $status = $tester->execute(['--json' => true]);

        self::assertSame(Command::FAILURE, $status);
        self::assertNull($this->json($tester)['orders_recorded_locally_never_synced']['value']);
    }

    public function testTheHumanReadableOutputNamesEveryFigureAndItsState(): void
    {
        $this->event('', 'checkout.visited');
        $this->event('s-1', 'checkout.order_declined', ['reason' => 'Insufficient funds']);
        $this->event('s-2', 'checkout.order_placed', ['total_cents' => 1000]);

        $tester = new CommandTester($this->command());
        $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('never synced', $display);
        self::assertStringContainsString('without an EMR session', $display);
        self::assertStringContainsString('Insufficient funds', $display);
        self::assertStringContainsString('not checked', $display);
    }

    public function testDeclineReasonsAreScrubbedBeforeAnOperatorSeesThem(): void
    {
        $this->event('s-1', 'checkout.order_declined', ['reason' => 'Card 4111111111111111 refused']);

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        self::assertStringNotContainsString('4111111111111111', $tester->getDisplay());
    }
}
