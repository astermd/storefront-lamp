<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Observability;

use AsterMD\Storefront\Observability\OperationalCounts;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;

final class OperationalCountsTest extends TestCase
{
    use TempDatabase;

    private const int NOW = 1_800_000_000;

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->tempPdo();
    }

    private function counts(?\PDO $pdo = null): OperationalCounts
    {
        $pdo ??= $this->pdo;

        return new OperationalCounts(
            fn (): \PDO => $pdo,
            declineWindowSeconds: 2_592_000,
            clock: static fn (): int => self::NOW,
        );
    }

    /** @param array<string, mixed> $payload */
    private function event(string $sessionUuid, string $name, array $payload = [], int $agoSeconds = 60): void
    {
        $this->pdo->prepare('INSERT INTO events (session_uuid, name, payload, created_at) VALUES (?, ?, ?, ?)')
            ->execute([
                $sessionUuid,
                $name,
                (string) json_encode($payload, JSON_UNESCAPED_SLASHES),
                gmdate('c', self::NOW - $agoSeconds),
            ]);
    }

    public function testCheckoutsReachedWithoutAnEmrSessionAreCountedApartFromTheTotal(): void
    {
        $this->event('s-1', 'checkout.visited');
        $this->event('s-2', 'checkout.visited');
        $this->event('', 'checkout.visited');

        self::assertSame(1, $this->counts()->checkoutsWithoutEmrSession()->value);
        self::assertSame(3, $this->counts()->checkoutsReached()->value);
    }

    /**
     * A count that could not be computed must not render as zero — the exact
     * shape a reverse sweep once reported "0 lost charges" in.
     */
    public function testACountThatCouldNotBeComputedIsUnavailableRatherThanZero(): void
    {
        $broken = new \PDO('sqlite::memory:');
        $broken->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $counts = $this->counts($broken);

        self::assertFalse($counts->checkoutsWithoutEmrSession()->available());
        self::assertNull($counts->checkoutsWithoutEmrSession()->value);
        self::assertNotNull($counts->checkoutsWithoutEmrSession()->failure);
    }

    public function testDeclinesAreGroupedByReasonAndRatedAgainstEveryChargeAttempt(): void
    {
        $this->event('s-1', 'checkout.order_declined', ['reason' => 'Insufficient funds']);
        $this->event('s-2', 'checkout.order_declined', ['reason' => 'Insufficient funds']);
        $this->event('s-3', 'checkout.order_declined', ['reason' => 'Do not honour']);
        $this->event('s-4', 'checkout.order_placed', ['total_cents' => 1000]);

        $declines = $this->counts()->declines();

        self::assertTrue($declines->available());
        self::assertSame(3, $declines->total);
        self::assertSame(4, $declines->attempts);
        self::assertSame(['Insufficient funds' => 2, 'Do not honour' => 1], $declines->byReason);
        self::assertSame(75.0, $declines->rate());
    }

    public function testDeclinesOutsideTheWindowAreNotCounted(): void
    {
        $this->event('s-1', 'checkout.order_declined', ['reason' => 'Insufficient funds'], 60);
        $this->event('s-2', 'checkout.order_declined', ['reason' => 'Expired card'], 5_000_000);

        self::assertSame(['Insufficient funds' => 1], $this->counts()->declines()->byReason);
    }

    /**
     * A decline with no recorded reason is still a decline; dropping it would
     * make the rate disagree with the total it is a rate of.
     */
    public function testADeclineWithNoRecordedReasonIsCountedUnderAnExplicitBucket(): void
    {
        $this->event('s-1', 'checkout.order_declined', []);

        $declines = $this->counts()->declines();
        self::assertSame(1, $declines->total);
        self::assertSame([OperationalCounts::REASON_UNRECORDED => 1], $declines->byReason);
    }

    public function testADeclineReasonCarryingACardNumberIsScrubbedBeforeItIsReported(): void
    {
        $this->event('s-1', 'checkout.order_declined', ['reason' => 'Card 4111111111111111 was refused']);

        $reasons = array_keys((array) $this->counts()->declines()->byReason);
        self::assertStringNotContainsString('4111111111111111', $reasons[0]);
    }

    public function testAnUnreadableDeclineBreakdownIsUnavailableRatherThanEmpty(): void
    {
        $broken = new \PDO('sqlite::memory:');
        $broken->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $declines = $this->counts($broken)->declines();

        self::assertFalse($declines->available());
        self::assertNull($declines->byReason);
        self::assertNull($declines->rate());
    }

    public function testTheRateIsUndefinedRatherThanZeroWhenNothingWasAttempted(): void
    {
        self::assertNull($this->counts()->declines()->rate());
    }
}
