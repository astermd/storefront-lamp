<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Retention;

use AsterMD\Storefront\Retention\RetentionSweep;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;

/**
 * What `[30.6]`'s expiry may take out of `sessions` and `events`, and — the
 * half that matters — what it must refuse to take at any age.
 *
 * A deleted attempt row once re-armed a double charge. A deleted session row
 * is worse: it is a journey that cannot be resumed and an order that cannot be
 * reconciled, and no later run can put it back. So every refusal below is
 * asserted directly rather than inferred from a count.
 */
final class RetentionSweepTest extends TestCase
{
    use TempDatabase;

    private const string ANCIENT = '2020-01-01T00:00:00+00:00';

    private const string CUTOFF = '2026-01-01T00:00:00+00:00';

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->tempPdo();
    }

    public function testASessionPastItsPeriodIsTaken(): void
    {
        $this->seedSession('sess-aged', self::ANCIENT);

        self::assertSame(1, $this->sweep()->expireSessions(self::CUTOFF));
        self::assertSame(0, $this->sessionRows());
    }

    public function testASessionThatHasMovedInsideItsPeriodIsKept(): void
    {
        $this->seedSession('sess-fresh', '2026-06-01T00:00:00+00:00');

        self::assertSame(0, $this->sweep()->expireSessions(self::CUTOFF));
        self::assertSame(1, $this->sessionRows());
    }

    /**
     * The first hard constraint, and the one that costs money to get wrong.
     *
     * An order row carries the analytics session as its only link back to the
     * journey that placed it, and both reconciliation sweeps work through that
     * link. Deleting the session under a live order turns a recoverable charge
     * into an orphan nothing can attribute — so the configured period is a
     * floor rather than an override, and age is not an argument here.
     */
    public function testASessionAnOrderReferencesIsNeverTakenAtAnyAge(): void
    {
        $this->seedSession('sess-ordered', self::ANCIENT);
        $this->seedOrder('sess-ordered');

        self::assertSame(0, $this->sweep()->countExpirableSessions(self::CUTOFF));
        self::assertSame(0, $this->sweep()->expireSessions(self::CUTOFF));
        self::assertSame(1, $this->sessionRows());
    }

    public function testASessionKeptOnlyBecauseAnOrderReferencesItIsCountedSoNobodyHasToGuess(): void
    {
        $this->seedSession('sess-ordered', self::ANCIENT);
        $this->seedOrder('sess-ordered');
        $this->seedSession('sess-plain', self::ANCIENT);

        self::assertSame(1, $this->sweep()->countSessionsHeldByOrders(self::CUTOFF));
    }

    public function testAnOrderWithNoSessionLinkDoesNotShieldEveryAgedSession(): void
    {
        // `orders.session_uuid` became nullable for analytics-off deployments.
        // A NULL in a `NOT IN` subquery makes the whole predicate unknown,
        // which would silently turn this sweep into a no-op for every row.
        $this->seedSession('sess-aged', self::ANCIENT);
        $this->pdo->prepare(
            'INSERT INTO orders (session_uuid, provider_reference, anchor_slug, amount_cents, currency, status, created_at, updated_at)
             VALUES (NULL, ?, ?, ?, ?, ?, ?, ?)',
        )->execute(['ref-null', 'tirzepatide', 1000, 'USD', 'placed', self::ANCIENT, self::ANCIENT]);

        self::assertSame(1, $this->sweep()->expireSessions(self::CUTOFF));
    }

    public function testAnAgedEventIsTakenAndAFreshOneIsKept(): void
    {
        $this->seedSession('sess-1', self::ANCIENT);
        $this->seedEvent('sess-1', 'cart.created', self::ANCIENT);
        $this->seedEvent('sess-1', 'cart.updated', '2026-06-01T00:00:00+00:00');

        self::assertSame(1, $this->sweep()->countExpirableEvents(self::CUTOFF));
        self::assertSame(1, $this->sweep()->expireEvents(self::CUTOFF));
        self::assertSame(['cart.updated'], $this->eventNames());
    }

    /**
     * The audit trail outlives the sessions it describes on purpose
     * (`config/retention.php`), so the session sweep must not drag its own
     * events out with it.
     */
    public function testExpiringASessionLeavesItsAuditTrailStanding(): void
    {
        $this->seedSession('sess-aged', self::ANCIENT);
        $this->seedEvent('sess-aged', 'access.journey_resumed', '2026-06-01T00:00:00+00:00');

        $this->sweep()->expireSessions(self::CUTOFF);

        self::assertSame(['access.journey_resumed'], $this->eventNames());
    }

    public function testCountingNeverDeletes(): void
    {
        $this->seedSession('sess-aged', self::ANCIENT);
        $this->seedEvent('sess-aged', 'cart.created', self::ANCIENT);

        self::assertSame(1, $this->sweep()->countExpirableSessions(self::CUTOFF));
        self::assertSame(1, $this->sweep()->countExpirableEvents(self::CUTOFF));
        self::assertSame(1, $this->sessionRows());
        self::assertSame(['cart.created'], $this->eventNames());
    }

    private function sweep(): RetentionSweep
    {
        return new RetentionSweep(fn (): \PDO => $this->pdo);
    }

    private function seedSession(string $uuid, string $movedAt): void
    {
        $this->pdo->prepare(
            'INSERT INTO sessions (session_uuid, opportunity_id, journey_state, attribution, created_at, updated_at)
             VALUES (?, NULL, ?, NULL, ?, ?)',
        )->execute([$uuid, '{}', $movedAt, $movedAt]);
    }

    private function seedOrder(string $uuid): void
    {
        $this->pdo->prepare(
            'INSERT INTO orders (session_uuid, provider_reference, anchor_slug, amount_cents, currency, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([$uuid, 'ref-' . $uuid, 'tirzepatide', 19900, 'USD', 'placed', self::ANCIENT, self::ANCIENT]);
    }

    private function seedEvent(string $uuid, string $name, string $createdAt): void
    {
        $this->pdo->prepare('INSERT INTO events (session_uuid, name, payload, created_at) VALUES (?, ?, NULL, ?)')
            ->execute([$uuid, $name, $createdAt]);
    }

    private function sessionRows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn();
    }

    /** @return list<string> */
    private function eventNames(): array
    {
        return array_map(
            static fn (mixed $name): string => (string) $name,
            $this->pdo->query('SELECT name FROM events ORDER BY id ASC')->fetchAll(\PDO::FETCH_COLUMN),
        );
    }
}
