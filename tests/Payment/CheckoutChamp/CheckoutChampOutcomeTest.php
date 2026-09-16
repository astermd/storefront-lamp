<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Payment\CheckoutChamp;

use AsterMD\Storefront\Payment\CheckoutChamp\CheckoutChampOutcome;
use AsterMD\Storefront\Payment\PlacementOutcome;
use AsterMD\Storefront\Payment\SettlementMode;
use PHPUnit\Framework\TestCase;

final class CheckoutChampOutcomeTest extends TestCase
{
    private const string REFERENCE = 'D6C8C390A7';

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        $raw = (string) file_get_contents(__DIR__ . '/../../fixtures/' . $name);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public function testTheRecordedApprovalIsPlacedAndCarriesItsStatus(): void
    {
        $outcome = CheckoutChampOutcome::from($this->fixture('checkoutchamp-order-approved.json'), self::REFERENCE);

        self::assertTrue($outcome->isPlaced());
        self::assertSame(self::REFERENCE, $outcome->reference);
        self::assertSame('COMPLETE', $outcome->rawStatus);
        self::assertNull($outcome->reason);
    }

    public function testTheRecordedDeclineIsShownToTheBuyerVerbatim(): void
    {
        // [13.28]. The provider's own sentence is the only reason it offers.
        $outcome = CheckoutChampOutcome::from($this->fixture('checkoutchamp-order-declined.json'), self::REFERENCE);

        self::assertSame(PlacementOutcome::DECLINED, $outcome->state);
        self::assertSame('Transaction Declined: Card Declined', $outcome->reason);
    }

    public function testADeclineStillCarriesTheReferenceTheLeadCallMinted(): void
    {
        // [13.26]. The refusal envelope has no order id in it at all — the
        // order was created by the earlier lead call and is still there, so a
        // retry that forgot the reference would leave an operator two partial
        // orders and no way to tell which one the buyer saw.
        $envelope = $this->fixture('checkoutchamp-order-declined.json');

        self::assertArrayNotHasKey('orderId', (array) $envelope['message']);
        self::assertSame(self::REFERENCE, CheckoutChampOutcome::from($envelope, self::REFERENCE)->reference);
    }

    public function testAPreauthAnswersSuccessWithAPlainSentenceAndIsStillAPlacement(): void
    {
        // The combination that breaks the obvious rule: `result` is SUCCESS and
        // `message` is the string "Card is preauthorized" — no object, no order
        // id, no total. Requiring the object form here would decline every
        // successful authorization.
        $envelope = $this->fixture('checkoutchamp-preauth-approved.json');
        self::assertIsString($envelope['message']);

        $outcome = CheckoutChampOutcome::from($envelope, self::REFERENCE, null, SettlementMode::Authorize);

        self::assertTrue($outcome->isPlaced());
        self::assertTrue($outcome->isAuthorizedOnly());
        self::assertSame(self::REFERENCE, $outcome->reference);
    }

    public function testAValidationFailureIsAnErrorWhoseMessageIsAnObject(): void
    {
        // The fourth combination: `result` ERROR with a field map rather than a
        // sentence. It is a fault in what the storefront sent, not something a
        // buyer can act on, so the generic line is shown instead of it.
        $envelope = $this->fixture('checkoutchamp-order-missing-shipping.json');
        self::assertIsArray($envelope['message']);

        $outcome = CheckoutChampOutcome::from($envelope, self::REFERENCE);

        self::assertSame(PlacementOutcome::DECLINED, $outcome->state);
        self::assertSame(CheckoutChampOutcome::GENERIC_DECLINE, $outcome->reason);
    }

    public function testABodyThatNeverDecodedIsADecline(): void
    {
        // A proxy's HTML error page has no `result` at all, so checking for the
        // literal SUCCESS catches it where checking for the absence of ERROR
        // would not.
        self::assertSame(PlacementOutcome::DECLINED, CheckoutChampOutcome::from([], self::REFERENCE)->state);
    }

    public function testATransportFailureIsADeclineAndNotAnException(): void
    {
        $outcome = CheckoutChampOutcome::from(['curlError' => 'Could not resolve host'], self::REFERENCE);

        self::assertSame(PlacementOutcome::DECLINED, $outcome->state);
        self::assertSame('transport_error', $outcome->rawStatus);
    }

    public function testTheRecordedApprovalReconcilesAgainstWhatTheBuyerWasShown(): void
    {
        // The recorded order is 0.30; telling it we displayed 1.20 must surface
        // as a discrepancy on a *placed* outcome, because the money has already
        // moved by the time it can be measured.
        $outcome = CheckoutChampOutcome::from($this->fixture('checkoutchamp-order-approved.json'), self::REFERENCE, 120);

        self::assertTrue($outcome->isPlaced());
        self::assertTrue($outcome->hasChargeDiscrepancy());
        self::assertSame(30, $outcome->chargeDiscrepancy?->chargedCents);
    }

    public function testAMatchingTotalRaisesNoDiscrepancy(): void
    {
        $outcome = CheckoutChampOutcome::from($this->fixture('checkoutchamp-order-approved.json'), self::REFERENCE, 30);

        self::assertFalse($outcome->hasChargeDiscrepancy());
    }

    public function testAPlacementHandsBackTheCustomerALaterChargeCanReuse(): void
    {
        // One identifier rather than the other provider's pair, because this
        // provider bills a stored instrument by naming the customer it holds.
        $outcome = CheckoutChampOutcome::from($this->fixture('checkoutchamp-order-approved.json'), self::REFERENCE);

        self::assertSame(['customer_id' => '19241'], $outcome->reusableCredential?->handle);
    }

    public function testADeclineHandsBackNoReusableCredential(): void
    {
        $outcome = CheckoutChampOutcome::from($this->fixture('checkoutchamp-order-declined.json'), self::REFERENCE);

        self::assertNull($outcome->reusableCredential);
    }

    public function testTheOrderReferenceIsReadBackFromTheLeadCall(): void
    {
        // The lead call creates a PARTIAL order and answers its id; everything
        // afterwards is keyed on that.
        $envelope = $this->fixture('checkoutchamp-lead-created.json');

        self::assertSame('PARTIAL', $envelope['message']['orderStatus']);
        self::assertSame('D6C8C390A7', CheckoutChampOutcome::referenceFrom($envelope));
    }

    public function testARefusedLeadCallYieldsNoReference(): void
    {
        self::assertNull(
            CheckoutChampOutcome::referenceFrom($this->fixture('checkoutchamp-leads-import-bad-campaign.json')),
        );
    }

    public function testARecordedSettlementCounts(): void
    {
        self::assertTrue(CheckoutChampOutcome::capturedFrom($this->fixture('checkoutchamp-capture-approved.json')));
    }

    public function testASettleCallWithoutItsLinesDoesNotCount(): void
    {
        // The dangerous one. The provider answers "No products exist in the
        // order" and leaves the order PARTIAL with the funds still held, so
        // reading this as a capture would report money taken that was not.
        $envelope = $this->fixture('checkoutchamp-capture-without-lines.json');

        self::assertSame('No products exist in the order', $envelope['message']);
        self::assertFalse(CheckoutChampOutcome::capturedFrom($envelope));
    }

    public function testATransportFailureIsNotASettlement(): void
    {
        self::assertFalse(CheckoutChampOutcome::capturedFrom(['curlError' => 'timeout']));
    }
}
