<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Journey;

use AsterMD\Storefront\Attribution\Attribution;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Payment\PaymentCredential;
use PHPUnit\Framework\TestCase;

class JourneyStateTest extends TestCase
{
    public function testRoundTripsThroughItsStoredForm(): void
    {
        $state = new JourneyState();
        $state->furthestStep = 'intake.medical';
        $state->emrEvents = ['intake_initiated'];
        $state->reconciled = true;
        $state->opportunityId = 'opp-1';
        $state->attribution = Attribution::capture(['aff_id' => '4412'], [], null, null);

        $restored = JourneyState::fromArray($state->toArray(), $state->attribution->toArray(), 'opp-1');

        self::assertSame('intake.medical', $restored->furthestStep);
        self::assertSame(['intake_initiated'], $restored->emrEvents);
        self::assertTrue($restored->reconciled);
        self::assertSame('opp-1', $restored->opportunityId);
        self::assertSame('4412', $restored->attribution?->get('affiliate_id'));
    }

    public function testToArrayCarriesOnlyTheJsonColumnFields(): void
    {
        $state = new JourneyState();
        $state->opportunityId = 'opp-1';
        $state->attribution = Attribution::capture([], [], null, null);
        $state->paymentCredential = ['kind' => 'card', 'number' => '4111111111111111'];

        self::assertSame(
            [
                'furthest_step', 'emr_events', 'reconciled', 'read_model_shape', 'cart', 'cart_mirrored',
                'form_answers', 'form_status', 'disqualified_rule', 'disqualified_teleform',
                'buyer', 'promotion', 'promotion_quoted_against', 'accepted_bumps', 'consents', 'placed_orders',
                'payment_handle', 'upsell_queue', 'upsell_cursor', 'upsell_outcomes', 'upsell_quotes',
                'verification', 'receipt', 'completed_at', 'session_retired',
            ],
            array_keys($state->toArray()),
        );

        // The enumeration above is the whole list, so the credential's absence
        // from it is already pinned; asserted again by name because this is
        // the one key whose arrival would put a card in the `sessions` table.
        self::assertArrayNotHasKey('payment_credential', $state->toArray());
    }

    public function testTheCartAndItsMirrorFlagRoundTripThroughTheStateArray(): void
    {
        $state = new JourneyState();
        $state->cart = ['lines' => [['slug' => 'product-a']], 'territory' => 'US'];
        $state->cartMirrored = true;

        $restored = JourneyState::fromArray($state->toArray(), null, null);

        self::assertSame(['lines' => [['slug' => 'product-a']], 'territory' => 'US'], $restored->cart);
        self::assertTrue($restored->cartMirrored);

        $default = JourneyState::fromArray([], null, null);
        self::assertSame([], $default->cart);
        self::assertFalse($default->cartMirrored);
    }

    public function testFingerprintChangesWithEveryPersistedField(): void
    {
        $state = new JourneyState();
        $baseline = $state->fingerprint();

        $state->furthestStep = 'checkout';
        self::assertNotSame($baseline, $state->fingerprint());

        $withStep = $state->fingerprint();
        $state->emrEvents = ['intake_initiated'];
        self::assertNotSame($withStep, $state->fingerprint());

        $withEmrEvents = $state->fingerprint();
        $state->reconciled = true;
        self::assertNotSame($withEmrEvents, $state->fingerprint());

        $withReconciled = $state->fingerprint();
        $state->readModelShape = 7;
        self::assertNotSame($withReconciled, $state->fingerprint());

        $withShape = $state->fingerprint();
        $state->opportunityId = 'opp-1';
        self::assertNotSame($withShape, $state->fingerprint());

        $withOpportunity = $state->fingerprint();
        $state->attribution = Attribution::capture(['aff_id' => '4412'], [], null, null);
        self::assertNotSame($withOpportunity, $state->fingerprint());
    }

    public function testChangingTheCartChangesTheFingerprint(): void
    {
        $state = new JourneyState();
        $baseline = $state->fingerprint();

        $state->cart = ['lines' => [['slug' => 'product-a']], 'territory' => null];
        self::assertNotSame($baseline, $state->fingerprint());

        $withCart = $state->fingerprint();
        $state->cartMirrored = true;
        self::assertNotSame($withCart, $state->fingerprint());
    }

    public function testHasEmrEventAnswersTheDeduplicationQuestion(): void
    {
        $state = new JourneyState();
        $state->emrEvents = ['pre_qualifying_initiated'];

        self::assertTrue($state->hasEmrEvent('pre_qualifying_initiated'));
        self::assertFalse($state->hasEmrEvent('intake_initiated'));
    }

    public function testRecordingAnEventIsIdempotentAndKeepsTheOrderItWasFiredIn(): void
    {
        $state = new JourneyState();
        $state->recordEmrEvent('visit_page');
        $state->recordEmrEvent('intake_initiated');
        $state->recordEmrEvent('visit_page');

        self::assertSame(['visit_page', 'intake_initiated'], $state->emrEvents);
    }

    public function testTheReadModelShapeSurvivesTheRoundTripAndDefaultsToUnstamped(): void
    {
        self::assertNull(JourneyState::fromArray(['reconciled' => true], null, null)->readModelShape);

        $state = new JourneyState();
        $state->readModelShape = 3;

        self::assertSame(3, JourneyState::fromArray($state->toArray(), null, null)->readModelShape);
    }

    public function testStoresAnswersPerTeleformSoTwoFormsDoNotCrossContaminate(): void
    {
        $state = new JourneyState();
        $state->storeAnswers('tf-prequal', ['state' => 'CA']);
        $state->storeAnswers('tf-intake', ['first_name' => 'Dana']);

        self::assertSame(['state' => 'CA'], $state->answersFor('tf-prequal'));
        self::assertSame(['first_name' => 'Dana'], $state->answersFor('tf-intake'));
        self::assertSame([], $state->answersFor('tf-unknown'));
    }

    public function testFormCompletionIsTrackedPerTeleform(): void
    {
        $state = new JourneyState();
        $state->storeAnswers('tf-intake', ['first_name' => 'Dana']);

        self::assertFalse($state->formCompleted('tf-intake'));
        $state->markFormCompleted('tf-intake');
        self::assertTrue($state->formCompleted('tf-intake'));
    }

    public function testRecordsWhichRuleDisqualifiedTheJourney(): void
    {
        $state = new JourneyState();

        self::assertFalse($state->isDisqualified());
        $state->recordDisqualification('tf-intake', 'pregnancy_hard_stop_notice');

        self::assertTrue($state->isDisqualified());
        self::assertSame('pregnancy_hard_stop_notice', $state->disqualifiedRule);
        self::assertSame('tf-intake', $state->disqualifiedTeleform);
    }

    public function testADisqualificationCanBeClearedSoAStartOverIsNotABlockedFunnel(): void
    {
        $state = new JourneyState();
        $state->recordDisqualification('tf-intake', 'pregnancy_hard_stop_notice');
        $state->clearDisqualification();

        self::assertFalse($state->isDisqualified());
        self::assertNull($state->disqualifiedRule);
    }

    public function testAnswersAndDisqualificationSurviveTheStorageRoundTrip(): void
    {
        $state = new JourneyState();
        $state->storeAnswers('tf-intake', ['first_name' => 'Dana', 'conditions' => ['a']]);
        $state->markFormCompleted('tf-intake');
        $state->recordDisqualification('tf-intake', 'bmi_low_hard_stop_notice');

        $restored = JourneyState::fromArray($state->toArray(), null, null);

        self::assertSame(['first_name' => 'Dana', 'conditions' => ['a']], $restored->answersFor('tf-intake'));
        self::assertTrue($restored->formCompleted('tf-intake'));
        self::assertSame('bmi_low_hard_stop_notice', $restored->disqualifiedRule);
    }

    public function testStoringAnswersChangesTheFingerprintSoTheSaveBackWritesThem(): void
    {
        $state = new JourneyState();
        $before = $state->fingerprint();
        $state->storeAnswers('tf-intake', ['first_name' => 'Dana']);

        self::assertNotSame($before, $state->fingerprint());
    }

    public function testTheReusableCredentialRoundTripsThroughTheDurableBlob(): void
    {
        $state = new JourneyState();
        $state->storeReusableCredential(PaymentCredential::stored([
            'customer_id' => '13996', 'customer_card_id' => '16815',
        ]));

        $restored = JourneyState::fromArray($state->toArray(), null, null);

        self::assertNotNull($restored->reusableCredential());
        self::assertSame(
            ['customer_id' => '13996', 'customer_card_id' => '16815'],
            $restored->reusableCredential()->handle,
        );
    }

    public function testACardCanNeverReachTheDurableBlob(): void
    {
        $state = new JourneyState();

        // The card field stays request-scoped and stays out of toArray(); the
        // durable field refuses a card outright.
        $state->paymentCredential = ['kind' => 'card', 'number' => '4111111100084444'];

        $encoded = json_encode($state->toArray());

        self::assertStringNotContainsString('4111111100084444', (string) $encoded);
        self::assertArrayNotHasKey('payment_credential', $state->toArray());

        $this->expectException(\LogicException::class);
        $state->storeReusableCredential(PaymentCredential::card('4111111100084444', '12', '2030', '737'));
    }

    public function testAHandleAssignedDirectlyAsACardStillNeverReachesTheStoredRow(): void
    {
        // storeReusableCredential() refuses a card, but `paymentHandle` is a
        // public property and an assignment bypasses that refusal. The write
        // path guards it too, because this array is what the `sessions` table
        // is handed.
        $state = new JourneyState();
        $state->paymentHandle = ['kind' => 'card', 'handle' => ['number' => '4111111100084444']];

        $stored = $state->toArray();

        self::assertNull($stored['payment_handle']);
        self::assertStringNotContainsString('4111111100084444', (string) json_encode($stored));
        self::assertStringNotContainsString('4111111100084444', $state->fingerprint());
    }

    public function testAHandleStoredByAPreviousReleaseThatNoLongerParsesDegradesToNone(): void
    {
        foreach ([['kind' => 'card', 'handle' => []], ['nonsense' => true], []] as $stored) {
            $restored = JourneyState::fromArray(['payment_handle' => $stored], null, null);
            self::assertNull($restored->reusableCredential());
            self::assertNull($restored->paymentHandle);
        }
    }

    public function testTheUpsellQueueItsCursorAndItsOutcomesAreDurable(): void
    {
        $state = new JourneyState();
        $state->upsellQueue = ['wellness-pack', 'sleep-kit'];
        $state->upsellCursor = 1;
        $state->upsellOutcomes = ['wellness-pack' => JourneyState::UPSELL_ACCEPTED];

        $restored = JourneyState::fromArray($state->toArray(), null, null);

        self::assertSame(['wellness-pack', 'sleep-kit'], $restored->upsellQueue);
        self::assertSame(1, $restored->upsellCursor);
        self::assertSame(['wellness-pack' => 'accepted'], $restored->upsellOutcomes);
    }

    public function testTheUpsellFieldsDefaultToAnUnstartedQueueOnAJourneyThatPredatesThem(): void
    {
        // A row written before the upsell fields existed decodes without these
        // keys, and a null where a list is expected would fail in the queue
        // rather than here.
        $restored = JourneyState::fromArray([], null, null);

        self::assertNull($restored->paymentHandle);
        self::assertSame([], $restored->upsellQueue);
        self::assertSame(0, $restored->upsellCursor);
        self::assertSame([], $restored->upsellOutcomes);
        self::assertNull($restored->receipt);
        self::assertNull($restored->completedAt);
        self::assertFalse($restored->sessionRetired);
        self::assertFalse($restored->isComplete());
    }

    public function testAQueueAndOutcomeSetWrittenInAShapeThatNoLongerParsesDegradeToEmpty(): void
    {
        $restored = JourneyState::fromArray([
            'upsell_queue' => 'wellness-pack',
            'upsell_cursor' => 'third',
            'upsell_outcomes' => ['wellness-pack' => ['accepted']],
            'receipt' => 'a receipt',
            'completed_at' => '',
        ], null, null);

        self::assertSame([], $restored->upsellQueue);
        self::assertSame(0, $restored->upsellCursor);
        self::assertSame([], $restored->upsellOutcomes);
        self::assertNull($restored->receipt);
        self::assertNull($restored->completedAt);
    }

    public function testTheReceiptTheCompletionGuardAndTheRetirementFlagAreDurable(): void
    {
        // The retirement flag is the server-side half of [4.18]: a middleware
        // reads it on the next request to clear the session cookie, so a flag
        // that did not survive the request would never be read at all.
        $state = new JourneyState();
        $state->receipt = ['references' => ['34788'], 'paid_total_cents' => 12000];
        $state->completedAt = '2026-08-24T12:00:00+00:00';
        $state->sessionRetired = true;

        $restored = JourneyState::fromArray($state->toArray(), null, null);

        self::assertSame(['references' => ['34788'], 'paid_total_cents' => 12000], $restored->receipt);
        self::assertSame('2026-08-24T12:00:00+00:00', $restored->completedAt);
        self::assertTrue($restored->sessionRetired);
        self::assertTrue($restored->isComplete());
    }

    public function testEveryUpsellAndCompletionFieldMovesTheFingerprintSoTheSaveBackWritesIt(): void
    {
        // The save-back writes nothing when the fingerprint is unchanged, so a
        // field restored by fromArray() but missing from toArray() is a field
        // that silently never persists.
        $state = new JourneyState();

        $baseline = $state->fingerprint();
        $state->storeReusableCredential(PaymentCredential::stored(['customer_id' => '1', 'customer_card_id' => '2']));
        self::assertNotSame($baseline, $state->fingerprint());

        $withHandle = $state->fingerprint();
        $state->upsellQueue = ['wellness-pack'];
        self::assertNotSame($withHandle, $state->fingerprint());

        $withQueue = $state->fingerprint();
        $state->upsellCursor = 1;
        self::assertNotSame($withQueue, $state->fingerprint());

        $withCursor = $state->fingerprint();
        $state->upsellOutcomes = ['wellness-pack' => JourneyState::UPSELL_DECLINED];
        self::assertNotSame($withCursor, $state->fingerprint());

        $withOutcomes = $state->fingerprint();
        $state->receipt = ['references' => ['34788']];
        self::assertNotSame($withOutcomes, $state->fingerprint());

        $withReceipt = $state->fingerprint();
        $state->completedAt = '2026-08-24T12:00:00+00:00';
        self::assertNotSame($withReceipt, $state->fingerprint());

        $withCompletion = $state->fingerprint();
        $state->sessionRetired = true;
        self::assertNotSame($withCompletion, $state->fingerprint());
    }

    public function testAQuoteAssignedDirectlyIsStillCoercedOnTheWayToTheColumn(): void
    {
        // `$upsellQuotes` is a public mutable array, so the setter is not the
        // only way in. A price is what the charge is checked against, so a
        // string or a float reaching the column would be compared against an
        // int and never match — refusing an offer the buyer can see, forever.
        $state = new JourneyState();
        $state->upsellQuotes = ['wellness-pack' => '899', 'sleep-kit' => 24.0, 'junk' => 'free'];

        self::assertSame(
            ['wellness-pack' => 899, 'sleep-kit' => 24],
            $state->toArray()['upsell_quotes'],
            'coerced to cents on the way out, and what cannot be a price is dropped',
        );
    }

    public function testTheFrozenQuotesDoNotOutliveTheJourney(): void
    {
        $state = new JourneyState();
        $state->upsellQuotes = ['wellness-pack' => 899];

        $state->wipeForCompletion();

        self::assertSame([], $state->upsellQuotes, 'working state, cleared with the queue it belongs to');
    }
    public function testCompletionWipesEverythingButTheReceiptTheGuardAndTheOrders(): void
    {
        $state = new JourneyState();
        $state->attribution = Attribution::fromArray(['utm_source' => 'google']);
        $state->opportunityId = 'opp-1';
        $state->cart = ['lines' => [['slug' => 'semaglutide']]];
        $state->cartMirrored = true;
        $state->formAnswers = ['tf-a' => ['weight' => '200']];
        $state->formStatus = ['tf-a' => JourneyState::FORM_COMPLETED];
        $state->buyer = ['email' => 'buyer@example.com'];
        $state->promotion = ['code' => 'NEW10', 'discount_cents' => 100];
        $state->promotionQuotedAgainst = 'digest';
        $state->acceptedBumps = ['mask'];
        $state->consents = [['key' => 'terms', 'granted' => true, 'copy_version' => '1', 'copy_shown' => 'x', 'at' => 'now']];
        $state->paymentCredential = ['kind' => 'card', 'number' => '4111111100084444'];
        $state->storeReusableCredential(PaymentCredential::stored(['customer_id' => '1', 'customer_card_id' => '2']));
        $state->upsellQueue = ['wellness-pack'];
        $state->upsellCursor = 1;
        $state->upsellOutcomes = ['wellness-pack' => JourneyState::UPSELL_ACCEPTED];
        $state->recordPlacedOrder('34788');
        $state->receipt = ['references' => ['34788']];
        $state->completedAt = '2026-08-24T12:00:00+00:00';
        $state->sessionRetired = true;

        $state->wipeForCompletion();

        // Kept — [4.15], [4.17]: the receipt and the guard, and the proof there
        // was an order, which is what keeps the receipt reachable.
        self::assertSame(['references' => ['34788']], $state->receipt);
        self::assertSame('2026-08-24T12:00:00+00:00', $state->completedAt);
        self::assertTrue($state->isComplete());
        self::assertSame(['34788'], $state->placedOrders);
        self::assertSame(['wellness-pack' => 'accepted'], $state->upsellOutcomes);
        self::assertTrue($state->sessionRetired, '[4.18]: the retirement signal outlives the wipe');

        // Cleared — [4.16].
        self::assertSame([], $state->cart);
        self::assertFalse($state->cartMirrored);
        self::assertSame([], $state->formAnswers);
        self::assertSame([], $state->formStatus);
        self::assertSame([], $state->buyer);
        self::assertNull($state->promotion);
        self::assertNull($state->promotionQuotedAgainst);
        self::assertSame([], $state->acceptedBumps);
        self::assertSame([], $state->consents);
        self::assertNull($state->paymentCredential);
        self::assertNull($state->paymentHandle);
        self::assertNull($state->reusableCredential());
        self::assertNull($state->attribution);
        self::assertSame([], $state->upsellQueue);
        self::assertSame(0, $state->upsellCursor);
    }

    public function testAWipedJourneyStillSerialisesAndReloads(): void
    {
        $state = new JourneyState();
        $state->recordPlacedOrder('34788');
        $state->receipt = ['references' => ['34788']];
        $state->completedAt = '2026-08-24T12:00:00+00:00';
        $state->wipeForCompletion();

        $restored = JourneyState::fromArray($state->toArray(), null, null);

        self::assertTrue($restored->isComplete());
        self::assertSame(['references' => ['34788']], $restored->receipt);
    }

    public function testTheOutcomeVocabularyIsSpeltInExactlyOnePlace(): void
    {
        // Three collaborators read these values back out of durable state, so a
        // literal spelt in one of them and not the others would silently look
        // like an offer that was never answered.
        self::assertSame('offered', JourneyState::UPSELL_OFFERED);
        self::assertSame('accepted', JourneyState::UPSELL_ACCEPTED);
        self::assertSame('declined', JourneyState::UPSELL_DECLINED);
        self::assertSame('charge_declined', JourneyState::UPSELL_CHARGE_DECLINED);
        self::assertSame('skipped', JourneyState::UPSELL_SKIPPED);
    }
}
