<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Repository;

use AsterMD\Storefront\Payment\PlacementOutcome;
use AsterMD\Storefront\Repository\CheckoutAttemptRepository;
use AsterMD\Storefront\Repository\RateLimitRepository;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;

/**
 * What the two sweepable tables will and — far more importantly — will not
 * give up to a pruner.
 *
 * `checkout_attempts` is the serialisation point that stops one submission
 * becoming two charges against a provider with no idempotency of its own, so
 * most of what is pinned here is survival rather than deletion: a row the
 * sweep must leave alone is the assertion that protects the money, and a
 * deletion that works is worth much less than a refusal that holds.
 */
final class ExpiryTest extends TestCase
{
    use TempDatabase;

    /** A day before the cutoff every test below sweeps against. */
    private const string OLD = '2026-07-01T00:00:00+00:00';

    /** The cutoff: rows stamped before this are old enough to go. */
    private const string CUTOFF = '2026-07-02T00:00:00+00:00';

    /** A moment after the cutoff, i.e. a row that is still young. */
    private const string RECENT = '2026-07-03T00:00:00+00:00';

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->tempPdo();
    }

    public function testAnAgedClaimIsGivenUp(): void
    {
        // A claim older than the staleness window is already, by design, a row
        // any new submit would take over in place. Deleting it removes nothing
        // the guard relies on.
        $attempts = $this->attempts();
        $attempts->claim('idem-old', 'sess-1', self::OLD);

        self::assertSame(1, $attempts->expire(self::CUTOFF));
        self::assertNull($attempts->outcomeFor('idem-old'));
    }

    public function testAClaimThatIsStillYoungSurvivesTheSweep(): void
    {
        // The absence that matters most on this table: a claim minutes old may
        // be a submission in flight right now, and taking its row away is what
        // re-arms the double charge the table exists to prevent.
        $attempts = $this->attempts();
        $attempts->claim('idem-live', 'sess-1', self::RECENT);

        self::assertSame(0, $attempts->expire(self::CUTOFF));
        self::assertNotNull($attempts->outcomeFor('idem-live'));
    }

    public function testASentAttemptSurvivesHoweverOldItIs(): void
    {
        // `sent` means the provider was contacted and never answered, so the
        // card may already have been charged. No amount of age makes re-posting
        // that payload safe, and deleting the row is what would let it happen.
        $attempts = $this->attempts();
        $attempts->claim('idem-sent', 'sess-1', self::OLD);
        $attempts->markSent('idem-sent', self::OLD);

        self::assertSame(0, $attempts->expire(self::CUTOFF));
        self::assertSame(
            CheckoutAttemptRepository::STATE_SENT,
            $attempts->outcomeFor('idem-sent')['state'],
        );
    }

    public function testAnAgedCompletedAttemptIsGivenUp(): void
    {
        $attempts = $this->attempts();
        $attempts->claim('idem-done', 'sess-1', self::OLD);
        $attempts->markSent('idem-done', self::OLD);
        $attempts->recordOutcome('idem-done', ['state' => 'placed', 'reference' => '34660']);

        self::assertSame(1, $attempts->expire(self::CUTOFF));
        self::assertNull($attempts->outcomeFor('idem-done'));
    }

    public function testTheSweepTakesOnlyTheRowsItIsEntitledTo(): void
    {
        // One sweep, four rows, and only the two that qualify may go. Run
        // together because the predicate is a single statement: a sweep that
        // deleted the right rows one at a time and the wrong ones in company
        // would pass every test above.
        $attempts = $this->attempts();
        $attempts->claim('aged-claim', 'sess-1', self::OLD);
        $attempts->claim('young-claim', 'sess-1', self::RECENT);
        $attempts->claim('aged-sent', 'sess-1', self::OLD);
        $attempts->markSent('aged-sent', self::OLD);
        $attempts->claim('aged-complete', 'sess-1', self::OLD);
        $attempts->markSent('aged-complete', self::OLD);
        $attempts->recordOutcome('aged-complete', ['state' => 'placed', 'reference' => '34660']);

        self::assertSame(2, $attempts->expire(self::CUTOFF));
        self::assertNull($attempts->outcomeFor('aged-claim'));
        self::assertNull($attempts->outcomeFor('aged-complete'));
        self::assertNotNull($attempts->outcomeFor('young-claim'));
        self::assertNotNull($attempts->outcomeFor('aged-sent'));
    }

    public function testCountingWhatWouldGoDeletesNothing(): void
    {
        // The dry run's whole promise. An operator who runs the sweep to see
        // what it would do must not discover that it did it.
        $attempts = $this->attempts();
        $attempts->claim('idem-old', 'sess-1', self::OLD);

        self::assertSame(1, $attempts->countExpirable(self::CUTOFF));
        self::assertNotNull($attempts->outcomeFor('idem-old'));
    }

    public function testTheDryRunCountsExactlyWhatTheSweepTakes(): void
    {
        // Both halves read one predicate. A count that disagreed with the
        // delete would make the dry run a report about a different sweep.
        $attempts = $this->attempts();
        $attempts->claim('aged-claim', 'sess-1', self::OLD);
        $attempts->claim('young-claim', 'sess-1', self::RECENT);
        $attempts->claim('aged-sent', 'sess-1', self::OLD);
        $attempts->markSent('aged-sent', self::OLD);

        self::assertSame($attempts->countExpirable(self::CUTOFF), $attempts->expire(self::CUTOFF));
    }

    public function testAgedUnresolvedAttemptsAreCountedRatherThanDeleted(): void
    {
        // The figure that makes the undeletable state visible: a `sent` row
        // this old is an unanswered call nobody has closed, and it is also a
        // cart its buyer cannot retry. Both need a person, not a timer.
        $attempts = $this->attempts();
        $attempts->claim('idem-sent', 'sess-1', self::OLD);
        $attempts->markSent('idem-sent', self::OLD);
        $attempts->claim('idem-fresh-sent', 'sess-1', self::RECENT);
        $attempts->markSent('idem-fresh-sent', self::RECENT);

        self::assertSame(1, $attempts->countUnresolved(self::CUTOFF));
        self::assertNotNull($attempts->outcomeFor('idem-sent'));
    }

    public function testAClosedRateLimitWindowIsGivenUp(): void
    {
        $limits = $this->limits();
        $limits->hit('checkout.submit', '198.51.100.7', 300, 1_000_000);

        self::assertSame(1, $limits->expire(1_000_000 + 3600));
        self::assertSame(0, $this->rateLimitRows());
    }

    public function testARateLimitWindowStillOpenSurvivesTheSweep(): void
    {
        // The absence that matters on this table: deleting a row inside its
        // window hands the address a fresh allowance it has not earned, which
        // is the flood guard failing open.
        $limits = $this->limits();
        $limits->hit('checkout.submit', '198.51.100.7', 300, 1_000_000);
        $limits->hit('checkout.submit', '198.51.100.7', 300, 1_000_010);

        self::assertSame(0, $limits->expire(1_000_000 - 1));
        self::assertSame(1, $this->rateLimitRows());
        self::assertSame(3, $limits->hit('checkout.submit', '198.51.100.7', 300, 1_000_020));
    }

    public function testCountingClosedRateLimitWindowsDeletesNothing(): void
    {
        $limits = $this->limits();
        $limits->hit('checkout.submit', '198.51.100.7', 300, 1_000_000);

        self::assertSame(1, $limits->countExpirable(1_000_000 + 3600));
        self::assertSame(1, $this->rateLimitRows());
    }

    public function testTheRateLimitSweepLeavesOtherIdentitiesAlone(): void
    {
        $limits = $this->limits();
        $limits->hit('checkout.submit', 'old-caller', 300, 1_000_000);
        $limits->hit('checkout.submit', 'live-caller', 300, 1_000_000 + 7200);

        self::assertSame(1, $limits->expire(1_000_000 + 3600));
        self::assertSame(1, $this->rateLimitRows());
    }

    private function attempts(): CheckoutAttemptRepository
    {
        return new CheckoutAttemptRepository(fn (): \PDO => $this->pdo, 'sqlite');
    }

    private function limits(): RateLimitRepository
    {
        return new RateLimitRepository(fn (): \PDO => $this->pdo, 'sqlite');
    }

    private function rateLimitRows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM rate_limits')->fetchColumn();
    }

    /**
     * The row a challenge leaves behind is never swept, at any age.
     *
     * This is the double charge the sweep re-armed. A completed row holds
     * either a placement or a challenge, and the two differ in the one way
     * that matters: `[13.32]` clears the cart for a placement, so its key can
     * never be re-derived, while a challenge leaves the buyer holding their
     * cart on the provider's own page with every input to the key intact and a
     * thirty-day cookie carrying the session id forward.
     *
     * Delete that row and the identical resubmit finds no stored answer, so it
     * reaches a gateway with no idempotency of its own and creates a second
     * order. Asserted at an age far past any retention an operator could
     * configure, because age is precisely what does not settle it.
     */
    public function testACompletedChallengeIsNeverSweptHoweverOldItIs(): void
    {
        $attempts = $this->attempts();
        $attempts->claim('idem-challenge', 'sess-1', self::OLD);
        $attempts->markSent('idem-challenge', self::OLD);
        $attempts->recordOutcome('idem-challenge', [
            'state' => PlacementOutcome::PENDING_ACTION,
            'reference' => '34999',
            'action_url' => 'https://provider.test/3ds/34999',
        ]);

        self::assertSame(0, $attempts->countExpirable(self::CUTOFF), 'a challenge must not be offered for deletion');
        self::assertSame(0, $attempts->expire(self::CUTOFF), 'a challenge must not be deleted');
        self::assertNotNull(
            $attempts->outcomeFor('idem-challenge'),
            'the stored answer is the only thing that stops the resubmit reaching the provider',
        );
    }

    /**
     * A completed placement is still swept, which is the point of the sweep.
     *
     * Without this the fix above could be "never expire anything completed"
     * and every other test here would still pass.
     */
    public function testACompletedPlacementIsStillSwept(): void
    {
        $attempts = $this->attempts();
        $attempts->claim('idem-placed', 'sess-1', self::OLD);
        $attempts->markSent('idem-placed', self::OLD);
        $attempts->recordOutcome('idem-placed', ['state' => PlacementOutcome::PLACED, 'reference' => '34660']);

        self::assertSame(1, $attempts->countExpirable(self::CUTOFF));
        self::assertSame(1, $attempts->expire(self::CUTOFF));
    }

    /**
     * An outcome that cannot be decoded is kept, not swept.
     *
     * The conservative direction, and the one the cost asymmetry demands:
     * keeping a row nothing needed costs one row until the next window, and
     * dropping one that was needed costs a second charge.
     */
    public function testAnUnreadableStoredOutcomeIsKept(): void
    {
        $attempts = $this->attempts();
        $attempts->claim('idem-corrupt', 'sess-1', self::OLD);
        $attempts->markSent('idem-corrupt', self::OLD);
        $this->pdo->prepare('UPDATE checkout_attempts SET state = ?, outcome = ? WHERE idempotency_key = ?')
            ->execute([CheckoutAttemptRepository::STATE_COMPLETE, '{not json', 'idem-corrupt']);

        self::assertSame(0, $attempts->expire(self::CUTOFF));
    }
}
