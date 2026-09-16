<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Payment\CheckoutChamp;

use AsterMD\Storefront\Payment\Buyer;
use AsterMD\Storefront\Payment\CaptureOutcome;
use AsterMD\Storefront\Payment\CheckoutChamp\CheckoutChampAdapter;
use AsterMD\Storefront\Payment\CheckoutChamp\CheckoutChampApiFactory;
use AsterMD\Storefront\Payment\CheckoutChamp\CheckoutChampCredentials;
use AsterMD\Storefront\Payment\OrderEnvelope;
use AsterMD\Storefront\Payment\OrderLine;
use AsterMD\Storefront\Payment\OrderSearch;
use AsterMD\Storefront\Payment\OrderSearchResult;
use AsterMD\Storefront\Payment\PaymentCredential;
use AsterMD\Storefront\Payment\PlacementOutcome;
use AsterMD\Storefront\Payment\SettlementMode;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\FakeCheckoutChampTransport;
use PHPUnit\Framework\TestCase;

final class CheckoutChampAdapterTest extends TestCase
{
    private function credentials(string $campaignId = '9'): CheckoutChampCredentials
    {
        return new CheckoutChampCredentials('api.checkoutchamp.com', '', 'store_api', 'secret', $campaignId);
    }

    private function adapter(
        FakeCheckoutChampTransport $transport,
        ?CapturedLog $log = null,
        string $campaignId = '9',
    ): CheckoutChampAdapter {
        return new CheckoutChampAdapter(
            $this->credentials($campaignId),
            new CheckoutChampApiFactory($transport),
            ($log ?? new CapturedLog())->log,
        );
    }

    /** @param list<OrderLine>|null $lines */
    private function envelope(?array $lines = null, SettlementMode $settlement = SettlementMode::Capture): OrderEnvelope
    {
        $lines ??= [new OrderLine('nad-500', 'NAD+ (500mg)', '459', '15271', 12000, 1)];
        $subtotal = array_sum(array_map(static fn (OrderLine $l): int => $l->lineTotalCents(), $lines));

        return new OrderEnvelope(
            lines: $lines,
            buyer: new Buyer('Patient', 'Aaad', 'buyer@example.com', '2125551234', '350 5th Avenue', 'New York', 'NY', '10118'),
            subtotalCents: $subtotal,
            discountCents: 0,
            totalCents: $subtotal,
            currency: 'USD',
            promotionCode: null,
            attribution: [],
            sessionUuid: 'sess-1',
            clientIp: '203.0.113.7',
            userAgent: 'probe/1.0',
            idempotencyKey: 'idem-1',
            anchorSlug: 'nad-500',
            settlement: $settlement,
        );
    }

    private function card(): PaymentCredential
    {
        return PaymentCredential::card('4111111100084444', '12', '2030', '123');
    }

    private function transportThatPlaces(): FakeCheckoutChampTransport
    {
        $transport = new FakeCheckoutChampTransport();
        $transport->queueSuccess(['sessionId' => 'sess_abc']);
        $transport->queueSuccess(['orderId' => '881234', 'customerId' => '5501', 'totalAmount' => '120.00']);

        return $transport;
    }

    public function testPlacementOpensASessionBeforeBillingIt(): void
    {
        // The provider has no single-call placement: billing a session that was
        // never opened answers "Customer not found". Two calls, in order, is
        // the shape — and nothing above the adapter learns about either.
        $transport = $this->transportThatPlaces();

        $outcome = $this->adapter($transport)->place($this->envelope(), $this->card());

        self::assertTrue($outcome->isPlaced());
        self::assertSame('881234', $outcome->reference);
        self::assertCount(2, $transport->requests);
        self::assertStringContainsString('/leads/import/', $transport->path(0));
        self::assertStringContainsString('/order/import/', $transport->path(1));
    }

    public function testTheSessionFromTheLeadCallIsWhatTheOrderIsBilledAgainst(): void
    {
        $transport = $this->transportThatPlaces();

        $this->adapter($transport)->place($this->envelope(), $this->card());

        self::assertSame('sess_abc', $transport->params(1)['sessionId'] ?? null);
    }

    public function testTheCartBecomesOneBasedNumberedParameters(): void
    {
        $transport = $this->transportThatPlaces();

        $this->adapter($transport)->place($this->envelope([
            new OrderLine('nad-500', 'NAD+ (500mg)', '459', '15271', 12000, 1),
            new OrderLine('nad-1000', 'NAD+ (1000mg)', '459', '15275', 9000, 2),
        ]), $this->card());

        $params = $transport->params(1);

        self::assertSame('15271', $params['product1_id']);
        self::assertSame('1', $params['product1_qty']);
        self::assertSame('120.00', $params['product1_price']);
        self::assertSame('15275', $params['product2_id']);
        self::assertSame('2', $params['product2_qty']);
        self::assertSame('90.00', $params['product2_price']);
    }

    public function testAnUnmappableLineIsSkippedWithoutLeavingAGapInTheNumbering(): void
    {
        // The provider stops reading at the first missing index, so a hole
        // would silently drop every line after it rather than one.
        $transport = $this->transportThatPlaces();

        $this->adapter($transport)->place($this->envelope([
            new OrderLine('syringe', 'Syringe', null, null, 0, 1),
            new OrderLine('nad-500', 'NAD+ (500mg)', '459', '15271', 12000, 1),
        ]), $this->card());

        $params = $transport->params(1);

        self::assertSame('15271', $params['product1_id']);
        self::assertArrayNotHasKey('product2_id', $params);
    }

    public function testAnAuthorizeOrderIsSentToThePreauthEndpointInstead(): void
    {
        $transport = $this->transportThatPlaces();

        $outcome = $this->adapter($transport)->place(
            $this->envelope(settlement: SettlementMode::Authorize),
            $this->card(),
        );

        self::assertStringContainsString('/order/preauth/', $transport->path(1));
        self::assertTrue($outcome->isAuthorizedOnly());
    }

    public function testAFailedLeadCallNeverPresentsTheCard(): void
    {
        // The card is only sent on the second call, so a lead that cannot be
        // created costs the buyer a decline and nothing else.
        $transport = new FakeCheckoutChampTransport();
        $transport->queueFixture('checkoutchamp-leads-import-bad-campaign.json');

        $outcome = $this->adapter($transport)->place($this->envelope(), $this->card());

        self::assertSame(PlacementOutcome::DECLINED, $outcome->state);
        self::assertSame('lead_not_created', $outcome->rawStatus);
        self::assertCount(1, $transport->requests, 'the order call was never made');
    }

    public function testAnUnconfiguredCampaignIsRefusedBeforeTheWire(): void
    {
        // The provider answers an order with no campaign with "No products
        // exist in the order" — a message about the cart, for a configuration
        // fault, which is the worst possible place to debug it.
        $transport = new FakeCheckoutChampTransport();
        $log = new CapturedLog();

        $outcome = $this->adapter($transport, $log, campaignId: '')->place($this->envelope(), $this->card());

        self::assertSame('campaign_not_configured', $outcome->rawStatus);
        self::assertSame([], $transport->requests);
        self::assertSame('payment.campaign_not_configured', $log->lastError()['event'] ?? null);
    }

    public function testACartWithNothingChargeableNeverReachesTheProvider(): void
    {
        $transport = new FakeCheckoutChampTransport();

        $outcome = $this->adapter($transport)->place(
            $this->envelope([new OrderLine('syringe', 'Syringe', null, null, 0, 1)]),
            $this->card(),
        );

        self::assertSame('no_chargeable_lines', $outcome->rawStatus);
        self::assertSame([], $transport->requests);
    }

    public function testACredentialThisProviderCannotChargeAgainstNeverReachesTheWire(): void
    {
        $transport = new FakeCheckoutChampTransport();

        $outcome = $this->adapter($transport)->place($this->envelope(), PaymentCredential::token('tok_abc'));

        self::assertSame('credential_unusable', $outcome->rawStatus);
        self::assertSame([], $transport->requests);
    }

    public function testAStoredCustomerIsBilledWithoutACardNumber(): void
    {
        // [15.3]: the number stays in the provider's vault and the storefront
        // never holds it.
        $transport = $this->transportThatPlaces();

        $this->adapter($transport)->place(
            $this->envelope(),
            PaymentCredential::stored(['customer_id' => '5501']),
        );

        $params = $transport->params(1);

        self::assertSame('ACCTONFILE', $params['paySource']);
        self::assertSame('5501', $params['customerId']);
        self::assertArrayNotHasKey('cardNumber', $params);
        self::assertArrayNotHasKey('cardSecurityCode', $params);
    }

    public function testAThrownClientErrorIsADeclineAndTheBuyerNeverSeesItsMessage(): void
    {
        // An exception string can carry anything, and for a client that quotes
        // the URL it failed on, that includes the card and the password.
        $transport = new FakeCheckoutChampTransport();
        $transport->queue(0, '', 'Could not resolve host');
        $transport->queue(0, '', 'Could not resolve host');

        $outcome = $this->adapter($transport)->place($this->envelope(), $this->card());

        self::assertSame(PlacementOutcome::DECLINED, $outcome->state);
        self::assertSame(
            \AsterMD\Storefront\Payment\CheckoutChamp\CheckoutChampOutcome::GENERIC_DECLINE,
            $outcome->reason,
        );
    }

    public function testTheAdapterDeclaresNoPromotionSupportSoTheControlIsHidden(): void
    {
        // [14.4]: the storefront adapts rather than offering a box that can
        // only ever reject a code.
        $adapter = $this->adapter(new FakeCheckoutChampTransport());

        self::assertFalse($adapter->capabilities()->supportsPromotions);
        self::assertFalse($adapter->quotePromotion($this->envelope(), 'SAVE10')->valid);
    }

    public function testOrderSearchAnswersUnsupportedRatherThanAnEmptyWindow(): void
    {
        // [21.9a]: "cannot be asked" and "asked and found nothing" mean
        // opposite things, and only one of them is a clean bill of health.
        $result = $this->adapter(new FakeCheckoutChampTransport())->searchOrders(
            OrderSearch::between(new \DateTimeImmutable('-2 days'), new \DateTimeImmutable(), 100),
        );

        self::assertSame(OrderSearchResult::UNSUPPORTED, $result->failureReason);
        self::assertFalse($result->ok);
    }

    public function testCaptureIsDeclaredUnsupportedRatherThanGuessedAt(): void
    {
        // The authorize half is implemented; the settle call is not recorded.
        // Declaring true would let a deployment hold funds it has no proven way
        // to release, and the hold expires on the acquirer's clock.
        $adapter = $this->adapter(new FakeCheckoutChampTransport());

        self::assertFalse($adapter->capabilities()->supportsAuthorizeCapture);
        self::assertSame(CaptureOutcome::UNSUPPORTED, $adapter->capture('881234')->state);
    }

    public function testTheDeploymentKeyThisAdapterNeedsIsDeclaredRatherThanAssumed(): void
    {
        // The EMR channel carries no campaign, so config:validate has to be
        // told to look for one — and told by the adapter, not by a list in the
        // validator naming providers.
        self::assertSame(
            ['campaign_id'],
            $this->adapter(new FakeCheckoutChampTransport())->capabilities()->requiredDeploymentKeys,
        );
    }
}
