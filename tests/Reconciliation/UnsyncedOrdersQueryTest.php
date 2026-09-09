<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Reconciliation;

use AsterMD\Storefront\Repository\OrderRepository;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The three queries `[21.8]`'s forward sweep is built on, against a real
 * migrated database rather than a fake.
 *
 * These live here rather than beside the rest of {@see OrderRepository}'s
 * tests because they exist for one caller: nothing else in the storefront asks
 * "which orders did the EMR never learn about". The predicate itself —
 * `treatment_reference IS NULL` — is named by three docblocks elsewhere, and
 * this is the only place it is exercised against a real schema.
 *
 * The grace window is what most of these assert. An order placed a moment ago
 * has not failed to sync; the checkout path may simply not have got to it yet,
 * and a sweep that raced it would report a phantom backlog and re-send a
 * treatment the EMR is being told about in another request.
 */
final class UnsyncedOrdersQueryTest extends TestCase
{
    use TempDatabase;

    private const string SESSION = '9c1d5e8a-3b7f-4a2c-8e6d-1f4b9a7c2e50';

    private \PDO $pdo;

    private OrderRepository $orders;

    protected function setUp(): void
    {
        $this->pdo = $this->tempPdo();
        $this->orders = new OrderRepository(fn (): \PDO => $this->pdo);
        (new SessionRepository(fn (): \PDO => $this->pdo))->insert(self::SESSION, [], null);
    }

    public function testAnOrderPlacedInsideTheGraceWindowIsNotSwept(): void
    {
        // The absence that matters most here: a freshly placed order is not a
        // failed one. The checkout path syncs it in the same request, and a
        // sweep that picked it up would be racing that call.
        $this->seedOrder('34660', self::ago(5));

        self::assertSame([], $this->orders->findUnsyncedPlacedBefore(self::ago(300), 50));
    }

    public function testAnOrderOlderThanTheGraceWindowWithNoTreatmentReferenceIsSwept(): void
    {
        $this->seedOrder('34660', self::ago(3600));

        $found = $this->orders->findUnsyncedPlacedBefore(self::ago(300), 50);

        self::assertCount(1, $found);
        self::assertSame('34660', $found[0]['provider_reference']);
        self::assertSame(self::SESSION, $found[0]['session_uuid']);
        self::assertSame('placed', $found[0]['status']);
    }

    public function testAnOrderTheEmrWasAlreadyToldAboutIsNeverSweptAgain(): void
    {
        // The stamp is the whole point of the null column: a settled order
        // re-synced on every sweep would be a standing duplicate call.
        $id = $this->seedOrder('34660', self::ago(3600));
        $this->orders->markTreatmentSynced($id, '6a8c72619f9b46188233413b');

        self::assertSame([], $this->orders->findUnsyncedPlacedBefore(self::ago(300), 50));
        self::assertSame(0, $this->orders->countUnsyncedPlacedBefore(self::ago(300)));
    }

    public function testTheBacklogDrainsOldestFirstSoTheWorstArrearsAreTriedFirst(): void
    {
        $this->seedOrder('34662', self::ago(3600));
        $this->seedOrder('34660', self::ago(86400));
        $this->seedOrder('34661', self::ago(7200));

        $found = $this->orders->findUnsyncedPlacedBefore(self::ago(300), 50);

        self::assertSame(
            ['34660', '34661', '34662'],
            array_map(static fn (array $row): string => $row['provider_reference'], $found),
        );
    }

    public function testTheBoundOnHowManyComeBackIsHonouredSoOneRunCannotBeUnbounded(): void
    {
        foreach (['34660', '34661', '34662', '34663'] as $index => $reference) {
            $this->seedOrder($reference, self::ago(3600 + $index));
        }

        self::assertCount(2, $this->orders->findUnsyncedPlacedBefore(self::ago(300), 2));
    }

    public function testABoundOfZeroReturnsNothingRatherThanEverything(): void
    {
        // A limit that fell through to "no LIMIT clause" would turn a
        // misconfigured batch size into a full-table sweep on a schedule.
        $this->seedOrder('34660', self::ago(3600));

        self::assertSame([], $this->orders->findUnsyncedPlacedBefore(self::ago(300), 0));
    }

    public function testTheNeverSyncedCountIgnoresTheGraceWindowBecauseItCountsArrearsNotWork(): void
    {
        // `[20.12]` monitors "orders never synced to the EMR". That figure is
        // about the whole table, not about what this run is allowed to touch,
        // so the grace window must not shrink it.
        $this->seedOrder('34660', self::ago(5));
        $this->seedOrder('34661', self::ago(3600));

        self::assertSame(2, $this->orders->countUnsynced());
        self::assertSame(1, $this->orders->countUnsyncedPlacedBefore(self::ago(300)));
    }

    public function testAnOrderWithNoAnalyticsSessionIsCountedApartBecauseItCanNeverBeSynced(): void
    {
        // The EMR keys a treatment on the session, so an order recorded
        // without one has nothing to file it under and no sweep will ever fix
        // it. Counting it as ordinary backlog would make the backlog look like
        // work that a retry could drain.
        $this->seedOrder('34660', self::ago(3600));
        $this->seedOrder('34661', self::ago(3600), session: null);

        self::assertSame(2, $this->orders->countUnsynced());
        self::assertSame(1, $this->orders->countUnsyncedWithoutSession());
        self::assertSame(
            1,
            $this->orders->countUnsyncedPlacedBefore(self::ago(300)),
            'the backlog is work a retry could drain, and a sessionless order is not that',
        );
    }

    public function testAnOrderWithNoPlacementTimestampIsStillReachableRatherThanCountedAndNeverSwept(): void
    {
        // `orders.placed_at` was added in 0002 and is nullable, so a row
        // written before it existed has none. Such a row is counted by
        // `countUnsynced()` either way, and a finder that skipped it would
        // leave an operator watching a figure that never moves and a sweep
        // that never explains why. `created_at` stands in: it is NOT NULL, it
        // is written by the same `gmdate('c')` on the same insert, and for
        // every row this storefront wrote the two are the same instant.
        $this->orders->insert(
            [
                'session_uuid' => self::SESSION,
                'provider_reference' => '34660',
                'anchor_slug' => 'tirzepatide',
                'amount_cents' => 10800,
                'currency' => 'USD',
                'status' => 'placed',
            ],
            [],
            [],
        );
        $this->pdo->exec("UPDATE orders SET placed_at = NULL, created_at = '" . self::ago(3600) . "'");

        $found = $this->orders->findUnsyncedPlacedBefore(self::ago(300), 50);

        self::assertCount(1, $found);
        self::assertSame(
            self::ago(3600),
            $found[0]['placed_at'],
            'and it is handed back with an answer to "how long has this been outstanding"',
        );
        self::assertSame(1, $this->orders->countUnsyncedPlacedBefore(self::ago(300)));
    }

    public function testTheCountsAreZeroOnAnEmptyTableRatherThanFailing(): void
    {
        self::assertSame(0, $this->orders->countUnsynced());
        self::assertSame(0, $this->orders->countUnsyncedPlacedBefore(self::ago(300)));
        self::assertSame(0, $this->orders->countUnsyncedWithoutSession());
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
