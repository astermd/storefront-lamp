<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Abandonment;

use AsterMD\Sdk\Enum\CheckoutEvent;
use AsterMD\Storefront\Abandonment\AbandonmentClassifier;
use AsterMD\Storefront\Abandonment\AbandonmentState;
use AsterMD\Storefront\Journey\JourneyState;
use PHPUnit\Framework\TestCase;

final class AbandonmentClassifierTest extends TestCase
{
    private const string TELEFORM = 'tf_intake';

    /** A cart with one line, in the shape `Cart::toArray()` produces. */
    private static function cart(): array
    {
        return ['lines' => [['slug' => 'abc123-tadalafil', 'quantity' => 1]]];
    }

    private static function cartAbandoned(): JourneyState
    {
        $state = new JourneyState();
        $state->cart = self::cart();

        return $state;
    }

    private static function leadAbandoned(): JourneyState
    {
        $state = self::cartAbandoned();
        $state->opportunityId = 'opp_1';
        $state->formStatus = [self::TELEFORM => JourneyState::FORM_COMPLETED];

        return $state;
    }

    private static function intakeAbandoned(): JourneyState
    {
        $state = self::cartAbandoned();
        $state->opportunityId = 'opp_1';
        $state->formAnswers = [self::TELEFORM => ['first_name' => 'Ada']];
        $state->formStatus = [self::TELEFORM => JourneyState::FORM_IN_PROGRESS];

        return $state;
    }

    private static function checkoutAbandoned(): JourneyState
    {
        $state = self::leadAbandoned();
        $state->recordEmrEvent(CheckoutEvent::CheckoutVisited->value);

        return $state;
    }

    /**
     * A journey the provider actually refused.
     *
     * The decline is recorded as the funnel event the checkout stamps, not as
     * the presence of consents. Consents are written the moment a submission
     * passes validation, so they are equally true of a submission that never
     * reached the provider at all -- and a rung built on them chases a geo
     * block or a rate limit as though a card had been declined.
     */
    private static function paymentAbandoned(): JourneyState
    {
        $state = self::checkoutAbandoned();
        $state->buyer = ['email' => 'ada@example.test'];
        $state->consents = [[
            'key' => 'marketing',
            'granted' => true,
            'copy_version' => 'v1',
            'copy_shown' => 'copy',
            'at' => '2026-08-25T10:00:00+00:00',
        ]];
        $state->recordEmrEvent(CheckoutEvent::OrderDeclined->value);

        return $state;
    }

    /**
     * The absence that the switch to the real event bought.
     *
     * A checkout submission refused before the provider was ever reached --
     * validation, a geo block, a rate limit, an attempt that could not be
     * claimed -- leaves consents behind and no decline. It is a checkout
     * abandoner, and must not be chased as somebody whose card was refused.
     */
    public function testASubmissionRefusedBeforeTheProviderIsNotADeclinedPayment(): void
    {
        $state = self::checkoutAbandoned();
        $state->buyer = ['email' => 'ada@example.test'];
        $state->consents = [[
            'key' => 'marketing',
            'granted' => true,
            'copy_version' => 'v1',
            'copy_shown' => 'copy',
            'at' => '2026-08-25T10:00:00+00:00',
        ]];

        self::assertSame(AbandonmentState::Checkout, (new AbandonmentClassifier())->classify($state));
    }

    private static function upsellAbandoned(): JourneyState
    {
        $state = self::paymentAbandoned();
        $state->cart = [];
        $state->recordPlacedOrder('34660');
        $state->upsellQueue = ['offer-a'];
        $state->upsellOutcomes = ['offer-a' => JourneyState::UPSELL_OFFERED];

        return $state;
    }

    public function testAPopulatedCartThatStoppedMovingIsClassifiedAsCartAbandoned(): void
    {
        self::assertSame(
            AbandonmentState::Cart,
            (new AbandonmentClassifier())->classify(self::cartAbandoned()),
        );
    }

    public function testAJourneyTheEmrHoldsALeadForButWhichWentNoFurtherIsClassifiedAsLeadAbandoned(): void
    {
        self::assertSame(
            AbandonmentState::Lead,
            (new AbandonmentClassifier())->classify(self::leadAbandoned()),
        );
    }

    public function testAQuestionnaireLeftInProgressIsClassifiedAsIntakeAbandoned(): void
    {
        self::assertSame(
            AbandonmentState::Intake,
            (new AbandonmentClassifier())->classify(self::intakeAbandoned()),
        );
    }

    public function testACheckoutPageThatWasVisitedButNeverSubmittedIsClassifiedAsCheckoutAbandoned(): void
    {
        self::assertSame(
            AbandonmentState::Checkout,
            (new AbandonmentClassifier())->classify(self::checkoutAbandoned()),
        );
    }

    public function testASubmissionThatReachedAPlacementAndProducedNoOrderIsClassifiedAsPaymentAbandoned(): void
    {
        self::assertSame(
            AbandonmentState::Payment,
            (new AbandonmentClassifier())->classify(self::paymentAbandoned()),
        );
    }

    public function testAnOfferShownAndNeverAnsweredIsClassifiedAsUpsellAbandoned(): void
    {
        self::assertSame(
            AbandonmentState::Upsell,
            (new AbandonmentClassifier())->classify(self::upsellAbandoned()),
        );
    }

    /**
     * `[21.10]`'s whole point: the six are told apart. Six individually
     * written tests would still pass if the classifier collapsed two of them,
     * because each of these journeys satisfies several of the six descriptions
     * at once.
     */
    public function testTheSixStatesAreMutuallyDistinguishableAndCoverEveryCaseOfTheEnum(): void
    {
        $classifier = new AbandonmentClassifier();
        $classified = [
            $classifier->classify(self::cartAbandoned()),
            $classifier->classify(self::leadAbandoned()),
            $classifier->classify(self::intakeAbandoned()),
            $classifier->classify(self::checkoutAbandoned()),
            $classifier->classify(self::paymentAbandoned()),
            $classifier->classify(self::upsellAbandoned()),
        ];

        self::assertSame(AbandonmentState::cases(), $classified);
    }

    public function testACompletedJourneyIsNotAbandonedInAnyState(): void
    {
        $state = self::upsellAbandoned();
        $state->completedAt = '2026-08-25T10:00:00+00:00';

        self::assertNull((new AbandonmentClassifier())->classify($state));
    }

    public function testAJourneyTheServerRuledIneligibleIsNotAbandoned(): void
    {
        $state = self::intakeAbandoned();
        $state->recordDisqualification(self::TELEFORM, 'rule_age');

        self::assertNull((new AbandonmentClassifier())->classify($state));
    }

    public function testAVisitorWhoLandedAndLeftWithoutTakingAnyStepIsNotAbandoned(): void
    {
        self::assertNull((new AbandonmentClassifier())->classify(new JourneyState()));
    }

    public function testABuyerWhoPlacedAnOrderAndWasNeverOfferedAnUpsellIsNotAbandoned(): void
    {
        $state = self::upsellAbandoned();
        $state->upsellQueue = [];
        $state->upsellOutcomes = [];

        self::assertNull((new AbandonmentClassifier())->classify($state));
    }

    public function testABuyerWhoAnsweredEveryOfferIsNotUpsellAbandoned(): void
    {
        $state = self::upsellAbandoned();
        $state->upsellOutcomes = ['offer-a' => JourneyState::UPSELL_DECLINED];

        self::assertNull((new AbandonmentClassifier())->classify($state));
    }

    /**
     * A placed order is proof the funnel was not abandoned at any rung below
     * the upsell, and every one of those rungs still has standing evidence on
     * this journey — the checkout visit, the consents, the lead.
     */
    public function testAPlacedOrderIsNeverReportedAsAbandonedEarlierInTheFunnel(): void
    {
        $state = self::upsellAbandoned();
        $state->upsellOutcomes = [];

        self::assertNull((new AbandonmentClassifier())->classify($state));
    }
}
