<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Payment\CheckoutChamp;

use AsterMD\Storefront\Payment\CheckoutChamp\CheckoutChampOutcome;
use AsterMD\Storefront\Payment\PlacementOutcome;
use AsterMD\Storefront\Payment\SettlementMode;
use PHPUnit\Framework\TestCase;

final class CheckoutChampOutcomeTest extends TestCase
{
    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        $raw = (string) file_get_contents(__DIR__ . '/../../fixtures/' . $name);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public function testASuccessIsPlacedAndKeepsItsOrderReference(): void
    {
        $outcome = CheckoutChampOutcome::from(['result' => 'SUCCESS', 'message' => ['orderId' => '881234']]);

        self::assertTrue($outcome->isPlaced());
        self::assertSame('881234', $outcome->reference);
        self::assertNull($outcome->reason);
    }

    public function testARecordedRefusalIsShownToTheBuyerVerbatim(): void
    {
        // [13.28]. The four recorded refusals are all one sentence in `message`
        // with `result` ERROR, and that sentence is the only thing the provider
        // offers a buyer by way of a reason.
        $outcome = CheckoutChampOutcome::from($this->fixture('checkoutchamp-order-import-empty.json'));

        self::assertSame(PlacementOutcome::DECLINED, $outcome->state);
        self::assertSame('No products exist in the order', $outcome->reason);
    }

    public function testAPreauthAgainstNoCustomerIsARefusalLikeAnyOther(): void
    {
        // The recording that established the two-call sequence: billing a
        // session that was never opened answers "Customer not found".
        $outcome = CheckoutChampOutcome::from($this->fixture('checkoutchamp-order-preauth-empty.json'));

        self::assertSame(PlacementOutcome::DECLINED, $outcome->state);
        self::assertSame('Customer not found', $outcome->reason);
    }

    public function testMessageIsOnlyReadAsAReasonWhenItIsAString(): void
    {
        // `message` is a sentence on failure and the data object on success.
        // Casting the object would render the word "Array" and show it to a
        // buyer, so the string case is checked rather than assumed.
        $outcome = CheckoutChampOutcome::from(['result' => 'ERROR', 'message' => ['orderId' => '1']]);

        self::assertSame(CheckoutChampOutcome::GENERIC_DECLINE, $outcome->reason);
    }

    public function testASuccessWithNoOrderIdIsADeclineRatherThanAPlacement(): void
    {
        // A placement nobody can file, reconcile or capture is worse than a
        // decline: the buyer would be sent to a receipt for an order the
        // storefront cannot name.
        $outcome = CheckoutChampOutcome::from(['result' => 'SUCCESS', 'message' => []]);

        self::assertSame(PlacementOutcome::DECLINED, $outcome->state);
        self::assertSame('no_reference', $outcome->rawStatus);
    }

    public function testABodyThatNeverDecodedIsADecline(): void
    {
        // An HTML error page from a proxy decodes to nothing and carries no
        // `result`, so checking for the literal SUCCESS catches it where
        // checking for the absence of ERROR would not.
        self::assertSame(PlacementOutcome::DECLINED, CheckoutChampOutcome::from([])->state);
    }

    public function testATransportFailureIsADeclineAndNotAnException(): void
    {
        $outcome = CheckoutChampOutcome::from(['curlError' => 'Could not resolve host']);

        self::assertSame(PlacementOutcome::DECLINED, $outcome->state);
        self::assertSame('transport_error', $outcome->rawStatus);
    }

    public function testAChargeThatDoesNotMatchWhatTheBuyerSawIsStillPlacedAndFlagged(): void
    {
        // The money has already moved by the time this can be measured, so the
        // discrepancy hangs off a placed outcome rather than turning a
        // recoverable overcharge into an unrecoverable lost record.
        $outcome = CheckoutChampOutcome::from(
            ['result' => 'SUCCESS', 'message' => ['orderId' => '881234', 'totalAmount' => '149.00']],
            12000,
        );

        self::assertTrue($outcome->isPlaced());
        self::assertTrue($outcome->hasChargeDiscrepancy());
        self::assertSame(14900, $outcome->chargeDiscrepancy?->chargedCents);
    }

    public function testAMatchingTotalRaisesNoDiscrepancy(): void
    {
        $outcome = CheckoutChampOutcome::from(
            ['result' => 'SUCCESS', 'message' => ['orderId' => '881234', 'totalAmount' => '120.00']],
            12000,
        );

        self::assertFalse($outcome->hasChargeDiscrepancy());
    }

    public function testAPlacementHandsBackTheCustomerALaterChargeCanReuse(): void
    {
        // One identifier rather than the other provider's pair, because this
        // provider bills a stored instrument by naming the customer it holds.
        $outcome = CheckoutChampOutcome::from(
            ['result' => 'SUCCESS', 'message' => ['orderId' => '881234', 'customerId' => '5501']],
        );

        self::assertSame(['customer_id' => '5501'], $outcome->reusableCredential?->handle);
    }

    public function testADeclineHandsBackNoReusableCredential(): void
    {
        $outcome = CheckoutChampOutcome::from(['result' => 'ERROR', 'message' => 'Declined']);

        self::assertNull($outcome->reusableCredential);
    }

    public function testAnAuthorizePlacementReportsThatTheMoneyHasNotMoved(): void
    {
        $outcome = CheckoutChampOutcome::from(
            ['result' => 'SUCCESS', 'message' => ['orderId' => '881234']],
            null,
            SettlementMode::Authorize,
        );

        self::assertTrue($outcome->isPlaced());
        self::assertTrue($outcome->isAuthorizedOnly());
    }

    public function testTheSessionIsReadBackFromTheLeadCall(): void
    {
        self::assertSame(
            'sess_abc',
            CheckoutChampOutcome::sessionFrom(['result' => 'SUCCESS', 'message' => ['sessionId' => 'sess_abc']]),
        );
    }

    public function testARefusedLeadCallYieldsNoSession(): void
    {
        // The recorded refusal: a lead with no name is rejected outright, and
        // there is no session to bill an order against.
        self::assertNull(
            CheckoutChampOutcome::sessionFrom($this->fixture('checkoutchamp-leads-import-bad-campaign.json')),
        );
    }
}
