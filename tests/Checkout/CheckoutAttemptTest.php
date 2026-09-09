<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Checkout;

use AsterMD\Storefront\Checkout\CheckoutAttempt;
use AsterMD\Storefront\Payment\ChargeDiscrepancy;
use AsterMD\Storefront\Payment\NullPaymentAdapter;
use AsterMD\Storefront\Payment\PlacementOutcome;
use AsterMD\Storefront\Repository\CheckoutAttemptRepository;
use AsterMD\Storefront\Support\CardScrubber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a duplicate submit is owed.
 *
 * Every outcome the adapter can return has to survive being stored and read
 * back, because the stored copy is the only answer a second submit ever gets:
 * the whole point of the attempt record is that the duplicate never reaches
 * the provider. An outcome that decodes to null is therefore not a cosmetic
 * gap — it is a completed attempt with nothing to replay.
 */
final class CheckoutAttemptTest extends TestCase
{
    public function testAPlacedOutcomeSurvivesTheRoundTrip(): void
    {
        $decoded = CheckoutAttempt::decodeOutcome(
            CheckoutAttempt::encodeOutcome(PlacementOutcome::placed('34656', '3')),
        );

        self::assertNotNull($decoded);
        self::assertSame(PlacementOutcome::PLACED, $decoded->state);
        self::assertSame('34656', $decoded->reference);
        self::assertSame('3', $decoded->rawStatus);
    }

    public function testADeclineKeepsItsReferenceAndReason(): void
    {
        // The provider returns an order reference on a decline too, at a
        // different response path -- so a replayed decline can still name the
        // order it belongs to.
        $decoded = CheckoutAttempt::decodeOutcome(
            CheckoutAttempt::encodeOutcome(PlacementOutcome::declined('34661', 'Card was declined.', null)),
        );

        self::assertNotNull($decoded);
        self::assertSame(PlacementOutcome::DECLINED, $decoded->state);
        self::assertSame('34661', $decoded->reference);
        self::assertSame('Card was declined.', $decoded->reason);
    }

    public function testAPendingActionOutcomeSurvivesTheRoundTripWithItsRedirect(): void
    {
        // The case that decoded to null: the provider has already created the
        // order, so releasing the key and letting the buyer submit again would
        // create a second one against a gateway with no idempotency.
        $decoded = CheckoutAttempt::decodeOutcome(
            CheckoutAttempt::encodeOutcome(
                PlacementOutcome::pendingAction('34670', 'https://challenge.example/3ds/34670', '101'),
            ),
        );

        self::assertNotNull($decoded, 'a completed attempt must never hold an outcome nothing can replay');
        self::assertSame(PlacementOutcome::PENDING_ACTION, $decoded->state);
        self::assertSame('34670', $decoded->reference);
        self::assertSame('https://challenge.example/3ds/34670', $decoded->actionUrl);
    }

    public function testAnUnrecognisedStoredStateDecodesToNothingRatherThanGuessing(): void
    {
        self::assertNull(CheckoutAttempt::decodeOutcome(['state' => 'refunded', 'reference' => '1']));
    }

    public function testARowIsReadBackAsTheAttemptItRecords(): void
    {
        $attempt = CheckoutAttempt::fromRow([
            'key' => 'k-1',
            'session_key' => 's-1',
            'state' => CheckoutAttemptRepository::STATE_COMPLETE,
            'outcome' => CheckoutAttempt::encodeOutcome(PlacementOutcome::placed('34656', '3')),
        ]);

        self::assertTrue($attempt->isComplete());
        self::assertFalse($attempt->isClaimed());
        self::assertFalse($attempt->isSent());
        self::assertSame('34656', $attempt->outcome?->reference);
    }

    public function testAClaimedAttemptHasNotReachedTheProviderAndHasNoOutcome(): void
    {
        $attempt = CheckoutAttempt::fromRow([
            'key' => 'k-2',
            'session_key' => 's-1',
            'state' => CheckoutAttemptRepository::STATE_CLAIMED,
            'outcome' => null,
        ]);

        self::assertTrue($attempt->isClaimed());
        self::assertFalse($attempt->isSent());
        self::assertNull($attempt->outcome);
    }

    public function testASentAttemptIsDistinctFromAClaimedOneBecauseOnlyOneOfThemMayBeReplayed(): void
    {
        // The whole point of the third state: a claimed row demonstrably never
        // reached the provider and may be taken over, while a sent one may
        // correspond to a real charge and never may.
        $attempt = CheckoutAttempt::fromRow([
            'key' => 'k-3',
            'session_key' => 's-1',
            'state' => CheckoutAttemptRepository::STATE_SENT,
            'outcome' => null,
        ]);

        self::assertTrue($attempt->isSent());
        self::assertFalse($attempt->isClaimed());
        self::assertFalse($attempt->isComplete());
    }

    public function testAProviderAnsweredDeclineGivesTheKeyBack(): void
    {
        // The key excludes the card by design, so a buyer whose card was
        // refused derives the same key on the retry. Keeping it would replay
        // the decline and the second card would never reach the provider.
        self::assertTrue(CheckoutAttempt::releasesKey(PlacementOutcome::declined('34661', 'Card was declined.', '5')));
    }

    /**
     * @param string $rawStatus the status that says the provider's answer never arrived
     */
    #[DataProvider('unresolvedStatuses')]
    public function testAnOutcomeThatCannotRuleOutAChargeNeverGivesTheKeyBack(string $rawStatus): void
    {
        // None of these knows whether money moved, and giving the key back
        // would let an identical payload reach a gateway with no idempotency
        // of its own.
        self::assertFalse(CheckoutAttempt::releasesKey(PlacementOutcome::declined(null, 'Sorry.', $rawStatus)));
    }

    /** @return list<array{0: string}> */
    public static function unresolvedStatuses(): array
    {
        return [['transport_error'], ['exception'], ['no_reference']];
    }

    public function testARefusalWithNoGatewayBehindItGivesTheKeyBack(): void
    {
        // `no_provider_configured` comes from NullPaymentAdapter, which is what
        // a deployment gets when there is no gateway at all -- so no card was
        // presented and nothing needs ruling out. Holding the key would tell
        // the buyer their card may have been charged, leave the row `sent` with
        // nothing to expire it (that cart and that buyer permanently unable to
        // pay), and fire the money-may-be-missing alert on every first submit.
        self::assertTrue(CheckoutAttempt::releasesKey(
            PlacementOutcome::declined(null, NullPaymentAdapter::DECLINE_MESSAGE, 'no_provider_configured'),
        ));
    }

    public function testARefusalWithNoChargeableLinesGivesTheKeyBackToo(): void
    {
        // The same category, and the contrast that showed the one above was
        // misfiled: neither reached a gateway.
        self::assertTrue(CheckoutAttempt::releasesKey(
            PlacementOutcome::declined(null, 'Sorry.', 'no_chargeable_lines'),
        ));
    }

    public function testAPlacedOutcomeNeverGivesTheKeyBack(): void
    {
        self::assertFalse(CheckoutAttempt::releasesKey(PlacementOutcome::placed('34660', '1')));
    }

    public function testAPendingActionNeverGivesTheKeyBackBecauseTheOrderAlreadyExists(): void
    {
        self::assertFalse(CheckoutAttempt::releasesKey(
            PlacementOutcome::pendingAction('34670', 'https://challenge.example/3ds/34670', '101'),
        ));
    }

    public function testAChargeDiscrepancySurvivesTheRoundTrip(): void
    {
        // The stored blob is replayed verbatim to whoever lost the race, so a
        // copy that silently drops the one field saying the charged total was
        // wrong is a durable record asserting something untrue.
        $decoded = CheckoutAttempt::decodeOutcome(CheckoutAttempt::encodeOutcome(
            PlacementOutcome::placed('34660', '1', new ChargeDiscrepancy(10800, 12000, 0)),
        ));

        self::assertNotNull($decoded);
        self::assertTrue($decoded->hasChargeDiscrepancy());
        self::assertSame(10800, $decoded->chargeDiscrepancy?->expectedCents);
        self::assertSame(12000, $decoded->chargeDiscrepancy?->chargedCents);
        self::assertSame(0, $decoded->chargeDiscrepancy?->providerDiscountCents);
        self::assertSame(1200, $decoded->chargeDiscrepancy?->differenceCents());
    }

    public function testACardNumberInADeclineReasonIsMaskedBeforeItIsStored(): void
    {
        // The reason can fall through to gateway free text, and this one is
        // written to a durable column and replayed to the buyer. The adapter
        // scrubs it on the way in; this is the guard that survives an adapter
        // that forgets.
        $encoded = CheckoutAttempt::encodeOutcome(
            PlacementOutcome::declined('34661', 'Card 4111111100084444 was declined.', '5'),
        );

        self::assertStringNotContainsString('4111111100084444', (string) $encoded['reason']);
        self::assertStringContainsString(CardScrubber::MASK, (string) $encoded['reason']);
    }

    public function testACardNumberInARedirectUrlIsMaskedBeforeItIsStored(): void
    {
        // The redirect a further-action outcome carries is built by the
        // gateway, and nothing constrains what it puts in a query string --
        // including the number it was just handed. It was the last provider
        // free-text field reaching a durable column unscrubbed.
        //
        // The number is Luhn-valid on purpose: the scrubber finds card numbers
        // by shape and a Luhn-invalid one is left alone, so an assertion
        // written around one passes without the scrubber ever running.
        $encoded = CheckoutAttempt::encodeOutcome(PlacementOutcome::pendingAction(
            '34670',
            'https://challenge.example/3ds/34670?pan=4111111100084444&cvv=737',
            'pending',
        ));

        self::assertStringNotContainsString('4111111100084444', (string) $encoded['action_url']);
        self::assertStringContainsString(CardScrubber::MASK, (string) $encoded['action_url']);
    }

    public function testAMaskedRedirectUrlIsStillTheUrlTheDuplicateIsSentTo(): void
    {
        // Masking must not cost the duplicate its destination: the field is
        // carried precisely so a pending-action submit can be answered without
        // reaching a provider that has already created the order.
        $encoded = CheckoutAttempt::encodeOutcome(
            PlacementOutcome::pendingAction('34670', 'https://challenge.example/3ds/34670', 'pending'),
        );
        $decoded = CheckoutAttempt::decodeOutcome($encoded);

        self::assertSame('https://challenge.example/3ds/34670', $decoded?->actionUrl);
    }
}
