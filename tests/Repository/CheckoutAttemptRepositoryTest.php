<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Repository;

use AsterMD\Storefront\Repository\CheckoutAttemptRepository;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;

final class CheckoutAttemptRepositoryTest extends TestCase
{
    use TempDatabase;

    private const NOW = '2026-08-24T00:00:00+00:00';

    public function testAFirstClaimSucceeds(): void
    {
        self::assertTrue($this->repository()->claim('idem-1', 'sess-1', self::NOW));
    }

    public function testASecondClaimOfTheSameKeyLosesTheRace(): void
    {
        // The provider charges an identical payload twice, verified live. This
        // index is the only thing that stops a double-click from doing the same.
        $repository = $this->repository();

        self::assertTrue($repository->claim('idem-1', 'sess-1', '2026-08-24T00:00:00+00:00'));
        self::assertFalse($repository->claim('idem-1', 'sess-1', '2026-08-24T00:00:01+00:00'));
    }

    public function testADifferentKeyIsClaimedFreely(): void
    {
        $repository = $this->repository();
        $repository->claim('idem-1', 'sess-1', self::NOW);

        self::assertTrue($repository->claim('idem-2', 'sess-1', self::NOW));
    }

    public function testTheLosingClaimLeavesExactlyOneRowRatherThanTwo(): void
    {
        // Losing the race must be a no-op, not a second attempt row: a caller
        // reading the outcome has to find the winner's, and only the winner's.
        $pdo = $this->tempPdo();
        $repository = new CheckoutAttemptRepository(static fn (): \PDO => $pdo, 'sqlite');
        $repository->claim('idem-1', 'sess-1', self::NOW);
        $repository->claim('idem-1', 'sess-2', self::NOW);

        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM checkout_attempts')->fetchColumn());
        self::assertSame('sess-1', $repository->outcomeFor('idem-1')['session_key']);
    }

    public function testAFreshClaimSaysTheProviderHasNotBeenContacted(): void
    {
        // Three states, not two. A claimed row demonstrably stopped short of
        // the provider, which is the only thing that makes taking it over safe.
        $repository = $this->repository();
        $repository->claim('idem-1', 'sess-1', self::NOW);

        $attempt = $repository->outcomeFor('idem-1');

        self::assertSame(CheckoutAttemptRepository::STATE_CLAIMED, $attempt['state']);
        self::assertNull($attempt['outcome']);
        self::assertSame(self::NOW, $attempt['created_at']);
        self::assertNull($attempt['completed_at']);
    }

    public function testMarkingAnAttemptSentRecordsThatTheProviderHasBeenContacted(): void
    {
        // Written *before* the call, because a row that only becomes `sent`
        // afterwards is exactly the row a request that dies mid-charge leaves
        // behind -- and re-posting that payload creates and charges a second
        // order (the provider has no idempotency of its own).
        $repository = $this->repository();
        $repository->claim('idem-1', 'sess-1', self::NOW);

        $repository->markSent('idem-1', self::NOW);

        self::assertSame(CheckoutAttemptRepository::STATE_SENT, $repository->outcomeFor('idem-1')['state']);
        self::assertNull($repository->outcomeFor('idem-1')['completed_at'], 'sent is not an outcome');
    }

    public function testMarkingOneAttemptSentLeavesTheOthersAlone(): void
    {
        $repository = $this->repository();
        $repository->claim('idem-1', 'sess-1', self::NOW);
        $repository->claim('idem-2', 'sess-1', self::NOW);

        $repository->markSent('idem-1', self::NOW);

        self::assertSame(CheckoutAttemptRepository::STATE_CLAIMED, $repository->outcomeFor('idem-2')['state']);
    }

    public function testOnlyOneOfTwoConcurrentTakeoversOfOneStaleClaimWins(): void
    {
        // Two requests read the same stale `claimed` row and both decide it is
        // theirs. The takeover used to be a delete followed by a fresh insert,
        // which removed the row the unique index would have serialised against,
        // so both re-inserted and both went on to charge the same card. The row
        // itself is the serialisation point now: exactly one update can match a
        // given `created_at`.
        $repository = $this->repository();
        $repository->claim('idem-1', 'sess-dead', self::NOW);
        $observed = $repository->outcomeFor('idem-1')['created_at'];

        $later = '2026-08-24T00:10:00+00:00';

        self::assertTrue($repository->renewClaim('idem-1', 'sess-b', $later, $observed));
        self::assertFalse(
            $repository->renewClaim('idem-1', 'sess-c', $later, $observed),
            'the second taker was working from a snapshot the row has moved past',
        );
        self::assertSame('sess-b', $repository->outcomeFor('idem-1')['session_key']);
    }

    public function testASentAttemptIsNeverTakenOverHoweverOldItIs(): void
    {
        // A `sent` row belongs to a request that already reached a provider
        // with no idempotency of its own. Age says the request is not coming
        // back; it says nothing about whether the card was charged.
        $repository = $this->repository();
        $repository->claim('idem-1', 'sess-dead', self::NOW);
        $repository->markSent('idem-1', self::NOW);

        self::assertFalse($repository->renewClaim('idem-1', 'sess-b', '2027-01-01T00:00:00+00:00', self::NOW));
        self::assertSame(CheckoutAttemptRepository::STATE_SENT, $repository->outcomeFor('idem-1')['state']);
        self::assertSame('sess-dead', $repository->outcomeFor('idem-1')['session_key']);
    }

    public function testATakeoverOfAKeyNobodyHoldsChangesNothing(): void
    {
        self::assertFalse($this->repository()->renewClaim('idem-1', 'sess-b', self::NOW, self::NOW));
    }

    public function testMarkingSentRaisesWhenTheClaimHasBeenTakenOver(): void
    {
        // The request that made the claim was merely slow, not dead, and its
        // row was taken over. Letting this pass would present a card with
        // nothing in `checkout_attempts` recording it: the next submit would
        // claim cleanly and charge again, and this one's outcome would be
        // written onto the taker's row.
        $repository = $this->repository();
        $repository->claim('idem-1', 'sess-slow', self::NOW);
        $repository->renewClaim('idem-1', 'sess-b', '2026-08-24T00:10:00+00:00', self::NOW);

        $this->expectException(\DomainException::class);
        $repository->markSent('idem-1', self::NOW);
    }

    public function testMarkingSentRaisesWhenTheRowIsGoneAltogether(): void
    {
        $repository = $this->repository();
        $repository->claim('idem-1', 'sess-1', self::NOW);
        $repository->release('idem-1', self::NOW);

        $this->expectException(\DomainException::class);
        $repository->markSent('idem-1', self::NOW);
    }

    public function testMarkingSentRaisesRatherThanRepeatingItself(): void
    {
        // The second call has no `claimed` row to move, and a silent no-op
        // there is indistinguishable from the taken-over case above.
        $repository = $this->repository();
        $repository->claim('idem-1', 'sess-1', self::NOW);
        $repository->markSent('idem-1', self::NOW);

        $this->expectException(\DomainException::class);
        $repository->markSent('idem-1', self::NOW);
    }

    public function testRecordingAnOutcomeMakesItReadableByKey(): void
    {
        $repository = $this->repository();
        $repository->claim('idem-1', 'sess-1', self::NOW);
        // Through `sent`, which is the only way production ever reaches this:
        // a row is marked sent immediately before the provider is called, and
        // an outcome only exists because that call was made.
        $repository->markSent('idem-1', self::NOW);
        $repository->recordOutcome('idem-1', ['state' => 'placed', 'reference' => '34660', 'reason' => null, 'raw_status' => '1']);

        $attempt = $repository->outcomeFor('idem-1');

        self::assertSame(CheckoutAttemptRepository::STATE_COMPLETE, $attempt['state']);
        self::assertSame(['state' => 'placed', 'reference' => '34660', 'reason' => null, 'raw_status' => '1'], $attempt['outcome']);
        self::assertNotNull($attempt['completed_at']);
    }

    public function testADeclineIsRecordedTooSoTheDuplicateGetsTheSameAnswer(): void
    {
        // Leaving a declined attempt in flight would let the buyer's second
        // click reach the provider a second time.
        $repository = $this->repository();
        $repository->claim('idem-1', 'sess-1', self::NOW);
        $repository->markSent('idem-1', self::NOW);
        $repository->recordOutcome('idem-1', ['state' => 'declined', 'reference' => '34661', 'reason' => 'Failed test transaction', 'raw_status' => '5']);

        self::assertSame('declined', $repository->outcomeFor('idem-1')['outcome']['state']);
    }

    public function testAnOutcomeCannotBeWrittenOntoARowThisRequestNoLongerOwns(): void
    {
        // The one write that used to advance a row conditionally on nothing.
        // Every other write carries the ownership token; this one carries the
        // state the owner must have put the row in, which is the same
        // guarantee reached a different way — the outcome belongs to the
        // request that made the provider call, and only a `sent` row made one.
        $repository = $this->repository();
        $repository->claim('idem-1', 'sess-1', self::NOW);
        $repository->markSent('idem-1', self::NOW);
        $repository->recordOutcome('idem-1', ['state' => 'placed', 'reference' => '34660', 'reason' => null, 'raw_status' => '1']);

        // A straggler answering the same key writes nothing: the row is
        // `complete`, not `sent`.
        $repository->recordOutcome('idem-1', ['state' => 'placed', 'reference' => '99999', 'reason' => null, 'raw_status' => '1']);

        self::assertSame(
            '34660',
            $repository->outcomeFor('idem-1')['outcome']['reference'],
            'the first answer stands; a later one cannot overwrite it',
        );
    }

    public function testAnUnclaimedKeyHasNoAttempt(): void
    {
        self::assertNull($this->repository()->outcomeFor('idem-never-seen'));
    }

    public function testReleasingAKeyLetsTheSameSubmissionBeTriedAgain(): void
    {
        // Only ever for an attempt the provider was never reached on — a
        // request refused before the charge, or one that died mid-flight.
        $repository = $this->repository();
        $repository->claim('idem-1', 'sess-1', self::NOW);

        $repository->release('idem-1', self::NOW);

        self::assertNull($repository->outcomeFor('idem-1'));
        self::assertTrue($repository->claim('idem-1', 'sess-1', self::NOW));
    }

    public function testReleasingOneKeyLeavesTheOthersClaimed(): void
    {
        $repository = $this->repository();
        $repository->claim('idem-1', 'sess-1', self::NOW);
        $repository->claim('idem-2', 'sess-1', self::NOW);

        $repository->release('idem-1', self::NOW);

        self::assertNotNull($repository->outcomeFor('idem-2'));
    }

    public function testReleasingWillNotDeleteARowAnotherRequestNowOwns(): void
    {
        // The double-charge Critical's root cause was a delete by a request
        // that no longer held the row: the first owner was merely slow, its
        // claim was taken over, and its release then removed the taker's row
        // mid-charge so a third submit claimed cleanly and charged again.
        // Ownership belongs in the predicate rather than in a convention every
        // call site has to remember.
        $repository = $this->repository();
        $repository->claim('idem-1', 'sess-a', self::NOW);

        $taken = '2026-08-24T00:05:00+00:00';
        self::assertTrue($repository->renewClaim('idem-1', 'sess-b', $taken, self::NOW));

        $repository->release('idem-1', self::NOW);

        $attempt = $repository->outcomeFor('idem-1');

        self::assertNotNull($attempt, 'the taker still holds the row');
        self::assertSame($taken, $attempt['created_at']);
        self::assertSame('sess-b', $attempt['session_key']);
    }

    public function testAReleaseAfterASentMarkerWasRefusedCannotTouchTheTakersRow(): void
    {
        // The composition both services actually perform, which the two halves
        // above only pin separately: `markSent()` raises, and the caller then
        // declines to release. The refusal is the safety property, so what
        // makes it safe has to be on record — `release()` carries this
        // request's `created_at`, the takeover moved it, and the delete
        // therefore matches nothing even if a caller did make it.
        $repository = $this->repository();
        $repository->claim('idem-1', 'sess-slow', self::NOW);

        $taken = '2026-08-24T00:10:00+00:00';
        self::assertTrue($repository->renewClaim('idem-1', 'sess-b', $taken, self::NOW), 'precondition: the claim was taken over');

        try {
            $repository->markSent('idem-1', self::NOW);
            self::fail('markSent must refuse a claim this request no longer holds');
        } catch (\DomainException) {
            // The refusal under test.
        }

        $repository->release('idem-1', self::NOW);

        $attempt = $repository->outcomeFor('idem-1');
        self::assertNotNull($attempt, "the taker's row must survive a release by the request that lost it");
        self::assertSame($taken, $attempt['created_at']);
        self::assertSame('sess-b', $attempt['session_key']);
    }

    public function testAReleaseAfterAStateOnlyRefusalWouldDeleteARowAlreadyWithTheProvider(): void
    {
        // The other half of `markSent()`'s predicate, and the reason the
        // refusal above is a rule rather than a convenience: here the row is
        // still ours by `created_at` and only `state` has moved on, so a
        // release *does* match — and deletes the one record that stops the next
        // submit from claiming cleanly behind an order the provider is already
        // processing.
        //
        // Pinned as the boundary rather than as a defect. No caller can reach
        // this shape today, which the next test is the argument for; this is
        // what changes if one ever does.
        $repository = $this->repository();
        $repository->claim('idem-1', 'sess-1', self::NOW);
        $repository->markSent('idem-1', self::NOW);

        try {
            $repository->markSent('idem-1', self::NOW);
            self::fail('markSent must refuse a row that is no longer claimed');
        } catch (\DomainException) {
            // Same exception, different half of the predicate.
        }

        $repository->release('idem-1', self::NOW);

        self::assertNull(
            $repository->outcomeFor('idem-1'),
            'release is no no-op here: it removes a row that has already reached the provider',
        );
    }

    public function testATakeoverAlwaysMovesTheOwnershipTokenSoTheTwoHalvesOfTheSentPredicateAgree(): void
    {
        // Why the dangerous shape above is unreachable, stated as an assertion
        // rather than left in a comment. `state` and `created_at` can never
        // disagree about who owns a row: a takeover always rewrites the token,
        // and a row that has reached the provider is never taken over at all.
        $repository = $this->repository();
        $repository->claim('idem-1', 'sess-a', self::NOW);

        self::assertTrue($repository->renewClaim('idem-1', 'sess-b', '2026-08-24T00:10:00+00:00', self::NOW));
        self::assertNotSame(
            self::NOW,
            $repository->outcomeFor('idem-1')['created_at'],
            'a takeover must move the ownership token, or the loser could still match the winner\'s row',
        );

        $other = $this->repository();
        $other->claim('idem-2', 'sess-a', self::NOW);
        $other->markSent('idem-2', self::NOW);

        self::assertFalse(
            $other->renewClaim('idem-2', 'sess-b', '2027-01-01T00:00:00+00:00', self::NOW),
            'and a sent row is never takeable, so no taker can advance state while leaving the token alone',
        );
    }

    public function testReleasingStillFreesASentRowItsOwnerHolds(): void
    {
        // Called once against a `claimed` row -- a refusal before the charge --
        // and once against a `sent` one -- a decline the provider itself
        // answered with -- so no single state can stand in for ownership in the
        // predicate. This is the second of those two, and it must still work.
        $repository = $this->repository();
        $repository->claim('idem-1', 'sess-1', self::NOW);
        $repository->markSent('idem-1', self::NOW);

        $repository->release('idem-1', self::NOW);

        self::assertNull($repository->outcomeFor('idem-1'));
    }

    public function testTheConnectionIsNotOpenedUntilTheFirstQuery(): void
    {
        // Constructing a repository must not be the act that connects, or
        // everything assembled around one inherits the database.
        $opened = 0;
        $pdo = $this->tempPdo();
        $repository = new CheckoutAttemptRepository(function () use ($pdo, &$opened): \PDO {
            ++$opened;

            return $pdo;
        }, 'sqlite');

        self::assertSame(0, $opened);

        $repository->claim('idem-1', 'sess-1', self::NOW);
        $repository->outcomeFor('idem-1');

        self::assertSame(1, $opened);
    }

    private function repository(): CheckoutAttemptRepository
    {
        $pdo = $this->tempPdo();

        return new CheckoutAttemptRepository(static fn (): \PDO => $pdo, 'sqlite');
    }
}
