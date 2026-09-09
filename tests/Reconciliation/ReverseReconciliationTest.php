<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Reconciliation;

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
use PHPUnit\Framework\TestCase;

/**
 * `[21.9a]`'s reverse sweep: the orders the provider took that this storefront
 * has no record of at all.
 *
 * The real adapter is driven through a faked transport rather than a
 * hand-written double, so every case here runs against the projection the
 * provider actually returned on 2026-08-25 -- including the two shapes the
 * whole severity split rests on, a null `status_type_id` and `is_test`.
 *
 * Most of what matters is an absence. A sweep that reports a matched order, an
 * uncharged container or a sandbox order as a lost charge is worse than no
 * sweep: it teaches the operator that this list is noise, and the one real
 * entry arrives in a list they have stopped reading.
 */
final class ReverseReconciliationTest extends TestCase
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

    public function testAProviderOrderWithNoLocalRowAtAllIsReportedAsALostCharge(): void
    {
        // The failure this whole sweep guards: the card was charged and the
        // storefront died before writing the row, so there is nothing local to
        // reconcile from.
        $report = $this->sweep($this->vrio('vrio-order-search-mixed.json'))->run();

        self::assertSame(1, $report->lostCharges);
        self::assertSame(['34788'], $report->lostChargeReferences);
    }

    public function testAProviderOrderThatDoesMatchALocalRowIsNotReported(): void
    {
        $this->seedOrder('34788');

        $report = $this->sweep($this->vrio('vrio-order-search-mixed.json'))->run();

        self::assertSame(1, $report->matched);
        self::assertSame(0, $report->lostCharges);
        self::assertSame([], $report->lostChargeReferences);
    }

    public function testAnUnchargedProviderOrderIsNeverReportedAsALostCharge(): void
    {
        // A null `status_type_id` is an order container the card was never
        // charged against -- `date_ordered`, `date_authorized` and
        // `date_capture` all null on every recorded one. `[14.10]` says the
        // opposite. An uncharged order is not a lost charge, and reporting it
        // as one sends an operator after money that never moved.
        $report = $this->sweep($this->vrio('vrio-order-search-mixed.json'))->run();

        self::assertSame(2, $report->unmatchedUncharged, '34661 and 34659 both carry a null status');
        self::assertNotContains('34661', $report->lostChargeReferences);
        self::assertNotContains('34659', $report->lostChargeReferences);
    }

    public function testASandboxOrderIsCountedApartFromALostChargeRatherThanAlarmedOn(): void
    {
        // Every order this sandbox account takes is a test order, so counting
        // them as lost charges would make the monitored figure permanently
        // wrong in staging. Counting them separately still proves the sweep
        // ran and saw something, which a plain zero would not.
        $report = $this->sweep($this->vrio('vrio-order-search-mixed.json'))->run();

        self::assertSame(1, $report->unmatchedTest, '34787 is charged, unmatched and a test order');
        self::assertNotContains('34787', $report->lostChargeReferences);
    }

    public function testTheSweepAsksForABoundedWindowRatherThanForEveryOrderTheProviderHolds(): void
    {
        // An order outside the window is never fetched, and it is the provider
        // that leaves it out: the window is a server-side filter, verified by
        // the recorded 1990 negative control returning zero rows.
        $transport = new FakeVrioTransport();
        $transport->queueFixture('vrio-order-search-empty.json');

        $report = $this->sweep($this->adapterFor($transport))
            ->run(new \DateTimeImmutable('2026-08-25 12:00:00', new \DateTimeZone('UTC')));

        $url = $transport->requests[0]->getUrl();

        self::assertStringContainsString('date_created_from=2026-08-23+12%3A00%3A00', $url);
        self::assertStringContainsString('date_created_to=2026-08-25+11%3A45%3A00', $url);
        self::assertSame(0, $report->examined);
        self::assertSame(0, $report->lostCharges);
        self::assertFalse($report->searchFailed, 'an empty window is an answer, not an outage');
    }

    public function testAProviderWithNoOrderSearchProducesNoSweepAndNoCrash(): void
    {
        $this->seedOrder('34788');

        $report = $this->sweep(new NullPaymentAdapter())->run();

        self::assertFalse($report->supported);
        self::assertSame(0, $report->examined);
        self::assertSame(0, $report->lostCharges);
        self::assertFalse($report->searchFailed, 'nothing was asked, so nothing failed');
        self::assertCount(1, $this->log->eventsNamed('reconcile.reverse_sweep_unsupported'));
    }

    public function testAProviderOutageIsNotReportedAsACleanSweep(): void
    {
        // Zero orders returned and zero orders existing look identical unless
        // the failure is carried separately. This is the silent failure the
        // whole reconciliation exists to end.
        $transport = new FakeVrioTransport();
        $transport->queue(0, '', 'Could not resolve host: api.vrio.app');

        $report = $this->sweep($this->adapterFor($transport))->run();

        self::assertTrue($report->supported);
        self::assertTrue($report->searchFailed);
        self::assertSame('transport_error', $report->failureReason);
        self::assertSame(0, $report->lostCharges);
        self::assertCount(1, $this->log->eventsNamed('reconcile.order_search_failed'));
    }

    public function testAWindowTheLimitCouldNotHoldIsReportedRatherThanReadAsComplete(): void
    {
        // A truncated sweep examined a prefix and said nothing about the rest.
        $report = $this->sweep($this->vrio('vrio-order-search-truncated.json'))->run();

        self::assertTrue($report->truncated);
        self::assertSame(143, $report->reportedTotal);
        self::assertSame(4, $report->examined);
        self::assertCount(1, $this->log->eventsNamed('reconcile.reverse_sweep_truncated'));
    }

    /**
     * The recorded fact the truncation copy rests on.
     *
     * Nothing in the request asks for a sort order, so which orders fall into
     * the unread tail is the provider's decision. Every recorded window
     * answers newest first, which makes the tail the OLDEST orders — the ones
     * with the longest to have failed to record and the only ones that age out
     * of a lookback before another run at the same settings reaches them.
     * {@see \AsterMD\Storefront\Payment\OrderSearchResult::truncated()} and
     * {@see \AsterMD\Storefront\Console\ReconcileOrdersCommand} both state
     * this; a re-recording that changed it would leave both saying something
     * untrue, and nothing else in the suite would notice.
     */
    public function testEveryRecordedWindowIsNewestFirstSoTheUnreadTailIsTheOldestOrders(): void
    {
        foreach (['vrio-order-search.json', 'vrio-order-search-truncated.json', 'vrio-order-search-mixed.json'] as $fixture) {
            $decoded = json_decode(
                (string) file_get_contents(dirname(__DIR__) . '/fixtures/' . $fixture),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            self::assertIsArray($decoded);
            $dates = array_column($decoded['data']['orders'], 'date_created');

            self::assertNotSame([], $dates, $fixture . ' records no orders');

            $newestFirst = $dates;
            rsort($newestFirst);

            self::assertSame($newestFirst, $dates, $fixture . ' is not newest-first');
        }
    }

    public function testALocalRowThatCannotBeReadIsNotCountedAsALostCharge(): void
    {
        // A database error is not evidence that an order is missing. Acting on
        // one would report every provider order as a lost charge the moment
        // the local database went away.
        $this->pdo->exec('DROP TABLE orders');

        $report = $this->sweep($this->vrio('vrio-order-search-mixed.json'))->run();

        self::assertSame(0, $report->lostCharges);
        self::assertSame(4, $report->unreadable);
        self::assertSame([], $report->lostChargeReferences);
    }

    public function testTheSweepEmitsOneOperatorLineCarryingTheMonitoredCount(): void
    {
        // `[20.12]`: "orders placed at the provider but not recorded locally".
        // One line per run whether or not there was anything to report, because
        // a log that only speaks up when something is wrong cannot be told
        // apart from one that has stopped running.
        $this->sweep($this->vrio('vrio-order-search-mixed.json'))->run();

        $line = $this->log->eventsNamed('reconcile.reverse_sweep')[0]['context'] ?? [];

        self::assertSame(1, $line['lost_charges'] ?? null);
        self::assertSame(1, $line['unmatched_test'] ?? null);
        self::assertSame(2, $line['unmatched_uncharged'] ?? null);
        self::assertSame(0, $line['matched'] ?? null);
        self::assertSame(4, $line['examined'] ?? null);
        self::assertFalse($line['search_failed'] ?? null);
        self::assertTrue($line['supported'] ?? null);
    }

    public function testALostChargeIsLoggedByIdentifierAndNeverByPayload(): void
    {
        // The wire log already spills verbatim provider traffic for a
        // deployment that turns it on. This sweep adds identifiers and counts.
        $this->sweep($this->vrio('vrio-order-search-mixed.json'))->run();

        $lines = $this->log->eventsNamed('reconcile.order_not_recorded');

        self::assertCount(1, $lines);
        self::assertSame('34788', $lines[0]['context']['reference'] ?? null);
        self::assertSame('2026-08-24 12:39:15', $lines[0]['context']['placed_at'] ?? null);
        self::assertSame(1250, $lines[0]['context']['discount_cents'] ?? null);
        self::assertStringNotContainsString('203.0.113.7', $this->log->contents(), 'no buyer IP');
        self::assertStringNotContainsString('13996', $this->log->contents(), 'no provider customer id');
    }

    public function testASandboxOrderIsNotNamedOneByOneInTheOperatorLog(): void
    {
        // Fifteen recorded test orders per sweep, every sweep, would bury the
        // one line that matters.
        $this->sweep($this->vrio('vrio-order-search.json'))->run();

        self::assertSame([], $this->log->eventsNamed('reconcile.order_not_recorded'));
    }

    private function sweep(PaymentAdapter $adapter): ReverseReconciliation
    {
        return new ReverseReconciliation(
            static fn (): PaymentAdapter => $adapter,
            $this->orders,
            $this->log->log,
            lookbackSeconds: 172800,
            graceSeconds: 900,
        );
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

    private function seedOrder(string $reference): int
    {
        return $this->orders->insert(
            [
                'session_uuid' => self::SESSION,
                'provider_reference' => $reference,
                'anchor_slug' => 'tirzepatide',
                'amount_cents' => 10800,
                'currency' => 'USD',
                'status' => 'placed',
                'placed_at' => gmdate('c'),
            ],
            [],
            [],
        );
    }
}
