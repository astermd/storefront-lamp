<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Journey;

use AsterMD\Storefront\Journey\JourneyState;
use PHPUnit\Framework\TestCase;

final class JourneyStateCheckoutTest extends TestCase
{
    public function testBuyerDetailsSurviveARoundTripThroughDurableState(): void
    {
        // [13.30]: on a decline the buyer's contact details are preserved so
        // the form re-fills for the retry.
        $state = new JourneyState();
        $state->storeBuyer(['first_name' => 'Ada', 'email' => 'ada@example.com']);

        $restored = JourneyState::fromArray($state->toArray(), null, null);

        self::assertSame('Ada', $restored->buyer()['first_name']);
    }

    public function testTheCardIsNeverPartOfDurableState(): void
    {
        // The single most important assertion in this file. Journey state is a
        // database row; a card in toArray() is a card in the sessions table.
        $state = new JourneyState();
        $state->paymentCredential = ['kind' => 'card', 'number' => '4111111111111111', 'cvv' => '123'];

        $serialised = json_encode($state->toArray(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('4111111111111111', $serialised);
        self::assertStringNotContainsString('123', $serialised);
        self::assertArrayNotHasKey('payment_credential', $state->toArray());
    }

    public function testTheCardIsNotPartOfTheFingerprintEither(): void
    {
        // The fingerprint decides whether the row is written at all; feeding a
        // card into a hash is still feeding it somewhere it need not go.
        $bare = new JourneyState();
        $withCard = new JourneyState();
        $withCard->paymentCredential = ['kind' => 'card', 'number' => '4111111111111111'];

        self::assertSame($bare->fingerprint(), $withCard->fingerprint());
    }

    public function testWipingTheCredentialLeavesNothingBehind(): void
    {
        $state = new JourneyState();
        $state->paymentCredential = ['kind' => 'card', 'number' => '4111111111111111'];

        $state->wipePaymentCredential();

        self::assertNull($state->paymentCredential);
    }

    public function testAPromotionRoundTrips(): void
    {
        $state = new JourneyState();
        $state->promotion = ['code' => 'SAVE10', 'discount_cents' => 1200];

        self::assertSame(['code' => 'SAVE10', 'discount_cents' => 1200], JourneyState::fromArray($state->toArray(), null, null)->promotion);
    }

    public function testThePromotionsCartDigestRoundTripsBesideIt(): void
    {
        // The figure and the cart it is an answer about have to survive
        // together: a promotion restored without its digest would be a discount
        // with no cart attached to it, which is the one thing that must never
        // reach a summary.
        $state = new JourneyState();
        $state->promotion = ['code' => 'SAVE10', 'discount_cents' => 1200];
        $state->promotionQuotedAgainst = 'a1b2c3';

        self::assertSame('a1b2c3', JourneyState::fromArray($state->toArray(), null, null)->promotionQuotedAgainst);
    }

    public function testAPromotionRestoredWithoutItsDigestIsQuotedAgainstNothing(): void
    {
        // A row written before the digest existed, which forces a re-quote
        // rather than trust in a figure nothing can tie to a cart.
        $restored = JourneyState::fromArray(['promotion' => ['code' => 'SAVE10', 'discount_cents' => 1200]], null, null);

        self::assertNotNull($restored->promotion);
        self::assertNull($restored->promotionQuotedAgainst);
    }

    public function testAcceptedBumpsAndConsentsRoundTrip(): void
    {
        $state = new JourneyState();
        $state->acceptedBumps = ['anti-nausea-kit'];
        $state->consents = [['key' => 'terms', 'granted' => true, 'copy_version' => 'abc', 'copy_shown' => 'I agree.', 'at' => '2026-08-24T00:00:00+00:00']];

        $restored = JourneyState::fromArray($state->toArray(), null, null);

        self::assertSame(['anti-nausea-kit'], $restored->acceptedBumps);
        self::assertSame('terms', $restored->consents[0]['key']);
    }

    public function testAPlacedOrderIsRecordedOnceEvenIfReportedTwice(): void
    {
        $state = new JourneyState();
        $state->recordPlacedOrder('34660');
        $state->recordPlacedOrder('34660');

        self::assertSame(['34660'], $state->placedOrders);
    }

    public function testTheFingerprintChangesWhenTheBuyerChanges(): void
    {
        $before = new JourneyState();
        $before->storeBuyer(['email' => 'a@example.com']);
        $after = new JourneyState();
        $after->storeBuyer(['email' => 'b@example.com']);

        self::assertNotSame($before->fingerprint(), $after->fingerprint());
    }

    public function testEveryCheckoutFieldMovesTheFingerprintSoTheSaveBackNoticesIt(): void
    {
        // The save-back writes nothing when the fingerprint is unchanged, so a
        // field missing from toArray() is a field that silently never persists.
        $state = new JourneyState();

        $baseline = $state->fingerprint();
        $state->promotion = ['code' => 'SAVE10', 'discount_cents' => 1200];
        self::assertNotSame($baseline, $state->fingerprint());

        $withPromotion = $state->fingerprint();
        $state->promotionQuotedAgainst = 'a1b2c3';
        self::assertNotSame($withPromotion, $state->fingerprint());

        $withDigest = $state->fingerprint();
        $state->acceptedBumps = ['anti-nausea-kit'];
        self::assertNotSame($withDigest, $state->fingerprint());

        $withBumps = $state->fingerprint();
        $state->consents = [['key' => 'terms', 'granted' => true, 'copy_version' => 'abc', 'copy_shown' => 'I agree.', 'at' => '2026-08-24T00:00:00+00:00']];
        self::assertNotSame($withBumps, $state->fingerprint());

        $withConsents = $state->fingerprint();
        $state->recordPlacedOrder('34660');
        self::assertNotSame($withConsents, $state->fingerprint());
    }

    public function testTheCheckoutFieldsDefaultToEmptyOnAJourneyThatPredatesThem(): void
    {
        // A row written before the checkout fields existed decodes to a state
        // array with none of these keys, and a null where a list is expected
        // would fail in a template rather than here.
        $restored = JourneyState::fromArray([], null, null);

        self::assertSame([], $restored->buyer());
        self::assertNull($restored->promotion);
        self::assertSame([], $restored->acceptedBumps);
        self::assertSame([], $restored->consents);
        self::assertSame([], $restored->placedOrders);
        self::assertNull($restored->paymentCredential);
    }

    public function testAHalfWrittenPromotionIsDiscardedRatherThanShownAsASaving(): void
    {
        // A code with no discount would badge a saving the buyer is not
        // getting; a discount with no code could not be explained on a receipt.
        self::assertNull(JourneyState::fromArray(['promotion' => ['code' => 'SAVE10']], null, null)->promotion);
        self::assertNull(JourneyState::fromArray(['promotion' => ['discount_cents' => 1200]], null, null)->promotion);
        self::assertNull(JourneyState::fromArray(['promotion' => 'SAVE10'], null, null)->promotion);
    }

    public function testAConsentMissingItsKeyIsDroppedRatherThanKeptBlank(): void
    {
        // [26.6]: the stored consent exists to prove what the visitor was
        // shown. One that cannot say what it was for proves nothing.
        $restored = JourneyState::fromArray([
            'consents' => [
                ['granted' => true, 'copy_shown' => 'I agree.'],
                ['key' => 'terms', 'granted' => true, 'copy_version' => 'abc', 'copy_shown' => 'I agree.', 'at' => '2026-08-24T00:00:00+00:00'],
            ],
        ], null, null);

        self::assertCount(1, $restored->consents);
        self::assertSame('terms', $restored->consents[0]['key']);
    }
}
