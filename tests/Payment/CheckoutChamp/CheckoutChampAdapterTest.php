<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Payment\CheckoutChamp;

use AsterMD\Storefront\Payment\Buyer;
use AsterMD\Storefront\Payment\CaptureOutcome;
use AsterMD\Storefront\Payment\CaptureRequest;
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
    private function credentials(): CheckoutChampCredentials
    {
        return new CheckoutChampCredentials('api.checkoutchamp.com', '', 'store_api', 'secret');
    }

    private function adapter(
        FakeCheckoutChampTransport $transport,
        ?CapturedLog $log = null,
        string $salesUrl = 'https://store.example.test/checkout/',
    ): CheckoutChampAdapter {
        return new CheckoutChampAdapter(
            $this->credentials(),
            new CheckoutChampApiFactory($transport),
            ($log ?? new CapturedLog())->log,
            $salesUrl,
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

    /** The lead call mints the reference; the billing call reports what it did with it. */
    private function transportThatPlaces(): FakeCheckoutChampTransport
    {
        $transport = new FakeCheckoutChampTransport();
        $transport->queueSuccess(['orderId' => 'D6C8C390A7', 'orderStatus' => 'PARTIAL']);
        $transport->queueSuccess([
            'orderId' => 'D6C8C390A7',
            'orderStatus' => 'COMPLETE',
            'customerId' => '19241',
            'totalAmount' => '120.00',
        ]);

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
        self::assertSame('D6C8C390A7', $outcome->reference);
        self::assertCount(2, $transport->requests);
        self::assertStringContainsString('/leads/import/', $transport->path(0));
        self::assertStringContainsString('/order/import/', $transport->path(1));
    }

    public function testTheOrderIdFromTheLeadCallIsWhatTheBillingCallNames(): void
    {
        // The reference is minted by the lead call, not by the charge — the
        // opposite of the other provider — which is what lets a refused
        // placement still carry one.
        $transport = $this->transportThatPlaces();

        $this->adapter($transport)->place($this->envelope(), $this->card());

        self::assertSame('D6C8C390A7', $transport->params(1)['orderId'] ?? null);
    }

    public function testTheBillingCallRepeatsTheShippingAddress(): void
    {
        // Omitting it is answered with a field map: {"shipAddress1": "is a
        // required field", ...}. The lead call already carried it; the provider
        // wants it on both.
        $transport = $this->transportThatPlaces();

        $this->adapter($transport)->place($this->envelope(), $this->card());

        foreach (['shipAddress1', 'shipCity', 'shipState', 'shipPostalCode', 'shipCountry'] as $field) {
            self::assertArrayHasKey($field, $transport->params(1));
        }
    }

    public function testARefusedChargeStillReportsTheReferenceTheLeadCallCreated(): void
    {
        // [13.26]: the partial order exists at the provider whatever the card
        // does, and losing its id would leave an operator two of them.
        $transport = new FakeCheckoutChampTransport();
        $transport->queueSuccess(['orderId' => 'D6C8C390A7', 'orderStatus' => 'PARTIAL']);
        $transport->queueFixture('checkoutchamp-order-declined.json');

        $outcome = $this->adapter($transport)->place($this->envelope(), $this->card());

        self::assertSame(PlacementOutcome::DECLINED, $outcome->state);
        self::assertSame('D6C8C390A7', $outcome->reference);
        self::assertSame('Transaction Declined: Card Declined', $outcome->reason);
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

    public function testTheCampaignIsTakenFromTheLinesOwnMapping(): void
    {
        // The EMR spells a CheckoutChamp campaign as a variant's
        // `provider.offer_id` — the same slot the other provider fills with its
        // offer id. So a second provider needed no new catalog field, and the
        // campaign travels with the order rather than with the connection.
        $transport = $this->transportThatPlaces();

        $this->adapter($transport)->place($this->envelope(), $this->card());

        self::assertSame('459', $transport->params(0)['campaignId'] ?? null);
        self::assertSame('459', $transport->params(1)['campaignId'] ?? null);
    }

    public function testACartWhoseLinesDisagreeAboutTheCampaignIsRefusedBeforeTheWire(): void
    {
        // A cart is one order ([13.19]) and an order belongs to one campaign, so
        // a cart spanning two has no correct single answer. Taking the first
        // line's would place the whole order under a campaign that does not
        // offer half of it — which the provider reports as the cart being
        // empty, a catalog fault described as a cart problem.
        $transport = new FakeCheckoutChampTransport();
        $log = new CapturedLog();

        $outcome = $this->adapter($transport, $log)->place($this->envelope([
            new OrderLine('nad-500', 'NAD+ (500mg)', '459', '15271', 12000, 1),
            new OrderLine('other', 'Something Else', '460', '15999', 5000, 1),
        ]), $this->card());

        self::assertSame('campaign_unresolved', $outcome->rawStatus);
        self::assertSame([], $transport->requests);
        self::assertSame('payment.campaign_unresolved', $log->lastError()['event'] ?? null);
    }

    public function testTheCheckoutUrlIsSentSoAnOperatorCanSeeWhereAnOrderCameFrom(): void
    {
        $transport = $this->transportThatPlaces();

        $this->adapter($transport)->place($this->envelope(), $this->card());

        self::assertSame('https://store.example.test/checkout/', $transport->params(0)['salesUrl'] ?? null);
    }

    public function testADeploymentWithNoUrlSendsNoSalesUrlRatherThanAnEmptyOne(): void
    {
        // [20.8]: absent rather than empty, on the same terms as every other
        // identifier this storefront declines to invent.
        $transport = $this->transportThatPlaces();

        $this->adapter($transport, salesUrl: '')->place($this->envelope(), $this->card());

        self::assertArrayNotHasKey('salesUrl', $transport->params(0));
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

    public function testTheAdapterDeclaresItCanAuthorizeAndCapture(): void
    {
        self::assertTrue($this->adapter(new FakeCheckoutChampTransport())->capabilities()->supportsAuthorizeCapture);
    }

    public function testACaptureResendsTheLinesWithoutACard(): void
    {
        // The lines are the whole difficulty: a pre-authorized order carries an
        // empty item list until this call supplies them. The card is not
        // resent — it was taken at the authorization.
        $transport = new FakeCheckoutChampTransport();
        $transport->queueFixture('checkoutchamp-capture-approved.json');

        $outcome = $this->adapter($transport)->capture(new CaptureRequest('FF2BE54DD5', [
            new OrderLine('nad-500', 'NAD+ (500mg)', '459', '15271', 12000, 1),
        ]));

        self::assertTrue($outcome->isCaptured());

        $params = $transport->params(0);
        self::assertSame('FF2BE54DD5', $params['orderId']);
        self::assertSame('459', $params['campaignId']);
        self::assertSame('15271', $params['product1_id']);
        self::assertArrayNotHasKey('cardNumber', $params);
    }

    public function testACaptureWithNoLinesIsRefusedBeforeTheWire(): void
    {
        // The provider's own answer for this is "No products exist in the
        // order", which leaves the order partial with the funds still held —
        // a refusal that reads like a cart problem and costs a round trip.
        $transport = new FakeCheckoutChampTransport();
        $log = new CapturedLog();

        $outcome = $this->adapter($transport, $log)->capture(new CaptureRequest('FF2BE54DD5'));

        self::assertSame(CaptureOutcome::FAILED, $outcome->state);
        self::assertSame('lines_unusable', $outcome->reason);
        self::assertSame([], $transport->requests);
        self::assertSame('payment.capture_lines_unusable', $log->lastError()['event'] ?? null);
    }

    public function testARefusedCaptureIsNotReportedAsSettled(): void
    {
        // The dangerous case: the provider reports an error but the funds stay
        // reserved, so reading this as a capture would report money taken that
        // was not.
        $transport = new FakeCheckoutChampTransport();
        $transport->queueFixture('checkoutchamp-capture-without-lines.json');

        $outcome = $this->adapter($transport)->capture(new CaptureRequest('FF2BE54DD5', [
            new OrderLine('nad-500', 'NAD+ (500mg)', '459', '15271', 12000, 1),
        ]));

        self::assertSame(CaptureOutcome::FAILED, $outcome->state);
        self::assertSame('No products exist in the order', $outcome->reason);
    }

    public function testACaptureThatCannotReachTheProviderFailsRatherThanThrows(): void
    {
        $transport = new FakeCheckoutChampTransport();
        $transport->queue(0, '', 'Could not resolve host');

        $outcome = $this->adapter($transport)->capture(new CaptureRequest('FF2BE54DD5', [
            new OrderLine('nad-500', 'NAD+ (500mg)', '459', '15271', 12000, 1),
        ]));

        self::assertSame(CaptureOutcome::FAILED, $outcome->state);
        self::assertSame('transport_error', $outcome->reason);
    }

    public function testThisAdapterNeedsNoDeploymentKeyOfItsOwn(): void
    {
        // The EMR channel carries no campaign, so config:validate has to be
        // told to look for one — and told by the adapter, not by a list in the
        // validator naming providers.
        // Nothing, as it turns out: the campaign this provider needs on every
        // order is catalog data rather than a deployment setting, so there is
        // no `payment.*` key for an operator to forget. The mechanism still
        // earns its place — the other adapter declares its shipping profile
        // through it.
        self::assertSame(
            [],
            $this->adapter(new FakeCheckoutChampTransport())->capabilities()->requiredDeploymentKeys,
        );
    }
}
