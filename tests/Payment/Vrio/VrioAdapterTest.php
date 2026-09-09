<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Payment\Vrio;

use AsterMD\Storefront\Checkout\CheckoutAttempt;
use AsterMD\Storefront\Payment\AdapterCapabilities;
use AsterMD\Storefront\Payment\Buyer;
use AsterMD\Storefront\Payment\OrderEnvelope;
use AsterMD\Storefront\Payment\OrderLine;
use AsterMD\Storefront\Payment\PaymentCredential;
use AsterMD\Storefront\Payment\PlacementOutcome;
use AsterMD\Storefront\Payment\Vrio\VrioAdapter;
use AsterMD\Storefront\Payment\Vrio\VrioApiFactory;
use AsterMD\Storefront\Payment\Vrio\VrioCredentials;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\FakeVrioTransport;
use PHPUnit\Framework\TestCase;

final class VrioAdapterTest extends TestCase
{
    private function adapter(FakeVrioTransport $transport, ?CapturedLog $log = null): VrioAdapter
    {
        return new VrioAdapter(
            new VrioCredentials('api.vrio.app', '', 'test-key', 147, 1),
            new VrioApiFactory($transport),
            ($log ?? new CapturedLog())->log,
            shippingProfileId: 1,
        );
    }

    /** @param list<OrderLine>|null $lines */
    private function envelope(?array $lines = null, ?string $code = null, int $discountCents = 0): OrderEnvelope
    {
        $lines ??= [new OrderLine('tirzepatide-5mg', 'Tirzepatide (5mg/mL)', '337', '3414', 12000, 1)];
        $subtotal = array_sum(array_map(static fn (OrderLine $l): int => $l->lineTotalCents(), $lines));

        return new OrderEnvelope(
            lines: $lines,
            buyer: new Buyer('Patient', 'Aaad', 'buyer@example.com', '2125551234', '350 5th Avenue', 'New York', 'NY', '10118'),
            subtotalCents: $subtotal,
            discountCents: $discountCents,
            totalCents: $subtotal - $discountCents,
            currency: 'USD',
            promotionCode: $code,
            attribution: [],
            sessionUuid: 'sess-1',
            clientIp: '203.0.113.7',
            userAgent: 'probe/1.0',
            idempotencyKey: 'idem-1',
            anchorSlug: 'tirzepatide-5mg',
        );
    }

    public function testARecordedApprovalPlacesTheOrder(): void
    {
        $transport = new FakeVrioTransport();
        $transport->queueFixture('vrio-order-approved.json');

        $outcome = $this->adapter($transport)
            ->place($this->envelope(), PaymentCredential::card('4111111100084444', '12', '2030', '123'));

        self::assertTrue($outcome->isPlaced());
        self::assertSame('34660', $outcome->reference);
    }

    public function testThePostedBodyMatchesWhatTheProviderAccepts(): void
    {
        $transport = new FakeVrioTransport();
        $transport->queueFixture('vrio-order-approved.json');

        $this->adapter($transport)
            ->place($this->envelope(), PaymentCredential::card('4111111100084444', '12', '2030', '123'));

        $body = $transport->body(0);

        self::assertSame(147, $body['campaign_id']);
        self::assertTrue($body['force_campaign_id']);
        self::assertSame('337', $body['offers'][0]['offer_id']);
        self::assertSame('3414', $body['offers'][0]['item_id']);
        self::assertSame('process', $body['action']);
    }

    public function testARecordedDeclineIsSurfacedWithItsReasonAndReference(): void
    {
        $transport = new FakeVrioTransport();
        $transport->queueFixture('vrio-order-declined.json');

        $outcome = $this->adapter($transport)
            ->place($this->envelope(), PaymentCredential::card('4111111100005555', '12', '2030', '123'));

        self::assertSame(PlacementOutcome::DECLINED, $outcome->state);
        self::assertSame('34661', $outcome->reference);
        self::assertSame('Failed test transaction', $outcome->reason);
    }

    public function testANonSuccessResponseIsLoggedInFullAndASuccessIsNot(): void
    {
        // [13.29].
        $log = new CapturedLog();
        $transport = new FakeVrioTransport();
        $transport->queueFixture('vrio-order-approved.json');
        $this->adapter($transport, $log)->place($this->envelope(), PaymentCredential::card('4111111100084444', '12', '2030', '123'));

        self::assertNull($log->lastWarning(), 'a placed order is not logged');

        $log2 = new CapturedLog();
        $transport2 = new FakeVrioTransport();
        $transport2->queueFixture('vrio-order-declined.json');
        $this->adapter($transport2, $log2)->place($this->envelope(), PaymentCredential::card('4111111100005555', '12', '2030', '123'));

        self::assertSame('payment.not_placed', $log2->lastWarning()['event'] ?? null);
    }

    public function testTheCardNumberNeverAppearsInAnyLogLine(): void
    {
        $log = new CapturedLog();
        $transport = new FakeVrioTransport();
        $transport->queueFixture('vrio-order-declined.json');

        $this->adapter($transport, $log)->place($this->envelope(), PaymentCredential::card('4111111100005555', '12', '2030', '123'));

        // Asserted against the file the real logger wrote, so the redaction
        // pass is part of what is being tested.
        self::assertStringNotContainsString('4111111100005555', $log->contents());
    }

    public function testAnOrderWithNothingChargeableIsRefusedBeforeTheProviderIsCalled(): void
    {
        $log = new CapturedLog();
        $transport = new FakeVrioTransport();

        $outcome = $this->adapter($transport, $log)->place(
            $this->envelope([new OrderLine('syringe', 'Syringe', null, null, 0, 1)]),
            PaymentCredential::card('4111111100084444', '12', '2030', '123'),
        );

        self::assertSame(PlacementOutcome::DECLINED, $outcome->state);
        self::assertSame([], $transport->requests, 'no provider call is made');
        self::assertSame('payment.no_chargeable_lines', $log->lastError()['event'] ?? null);
    }

    public function testAnUnmappedFreeLineIsLoggedRatherThanSilentlyDropped(): void
    {
        $log = new CapturedLog();
        $transport = new FakeVrioTransport();
        $transport->queueFixture('vrio-order-approved.json');

        $this->adapter($transport, $log)->place(
            $this->envelope([
                new OrderLine('tirzepatide-5mg', 'Tirzepatide', '337', '3414', 12000, 1),
                new OrderLine('syringe', 'Syringe', null, null, 0, 1),
            ]),
            PaymentCredential::card('4111111100084444', '12', '2030', '123'),
        );

        self::assertSame('payment.line_not_sent', $log->lastInfo()['event'] ?? null);
        self::assertSame('syringe', $log->lastInfo()['context']['slug'] ?? null);
    }

    public function testTheAdapterDeclaresLineItemDiscountsAndRendersItsOwnCardFields(): void
    {
        $capabilities = $this->adapter(new FakeVrioTransport())->capabilities();

        self::assertSame('vrio', $capabilities->providerCategory);
        self::assertTrue($capabilities->supportsPromotions);
        self::assertSame(AdapterCapabilities::SCOPE_LINE_ITEM, $capabilities->discountScope);
        self::assertTrue($capabilities->rendersOwnCardFields());
    }

    public function testTheDeclaredStrategyIsReferenceOrderAndThePostureSaysSo(): void
    {
        // Recorded on the wire: an order charged against a customer and card
        // pair with no card number, security code or expiry in the request.
        // [15.11] is answered, so [15.10]'s preference applies.
        $capabilities = $this->adapter(new FakeVrioTransport())->capabilities();

        self::assertSame(AdapterCapabilities::STRATEGY_ORDER_REFERENCE, $capabilities->credentialStrategy);
        self::assertSame(AdapterCapabilities::SURFACE_THEME_FIELDS, $capabilities->collectionSurface);
        self::assertStringNotContainsString('Full PCI scope', $capabilities->pciPosture);
        self::assertStringContainsString('reference', strtolower($capabilities->pciPosture));
    }

    public function testAnUnusableCredentialIsRefusedBeforeTheProviderIsCalled(): void
    {
        // An empty payment block merged into the order body is not a partial
        // request: the provider accepts the order and creates it with no
        // payment identification at all.
        $log = new CapturedLog();
        $transport = new FakeVrioTransport();

        $outcome = $this->adapter($transport, $log)
            ->place($this->envelope(), PaymentCredential::orderReference('34788'));

        self::assertFalse($outcome->isPlaced());
        self::assertSame('credential_unusable', $outcome->rawStatus);
        self::assertNull($outcome->reference);
        self::assertSame([], $transport->requests, 'no provider call is made');
        self::assertSame('payment.credential_unusable', $log->lastError()['event'] ?? null);
        self::assertTrue(CheckoutAttempt::releasesKey($outcome), 'nothing was sent, so the key goes back');
    }

    public function testAnEmptyPromotionCodeIsRejectedWithoutCallingTheProvider(): void
    {
        $transport = new FakeVrioTransport();

        $quote = $this->adapter($transport)->quotePromotion($this->envelope(), '   ');

        self::assertFalse($quote->valid);
        self::assertSame('empty', $quote->reason);
        self::assertSame([], $transport->requests);
    }

    public function testARealCodeIsQuotedAsTheSumOfItsPerLineAmounts(): void
    {
        // Recorded live against NEW10 (10% off, attached to offers 337 and
        // 338): a two-line cart of 120.00 and 270.00 comes back as 12.00 and
        // 27.00. The response carries no order-level total, so the order's
        // discount is the sum.
        $transport = new FakeVrioTransport();
        $transport->queueFixture('vrio-discount-calculated.json');

        $quote = $this->adapter($transport)->quotePromotion(
            $this->envelope([
                new OrderLine('tirzepatide-5mg', 'Tirzepatide', '337', '3414', 12000, 1),
                new OrderLine('tirzepatide-10mg', 'Tirzepatide 10mg', '338', '3415', 27000, 1),
            ]),
            'NEW10',
        );

        self::assertTrue($quote->valid);
        self::assertSame(3900, $quote->discountCents);
    }

    public function testThePromotionCallSendsTheQuantityUnderTheKeyThatEndpointReads(): void
    {
        // The order endpoint calls it order_offer_quantity; this one reads
        // offer_quantity. Sending the order's name makes the provider compute
        // against quantity 1, and the response says nothing about it -- the
        // displayed discount would simply be too small.
        $transport = new FakeVrioTransport();
        $transport->queueFixture('vrio-discount-calculated.json');

        $this->adapter($transport)->quotePromotion(
            $this->envelope([new OrderLine('electrolytes', 'Electrolytes', '337', '2844', 1900, 3)]),
            'NEW10',
        );

        $body = $transport->body(0);

        self::assertSame('3', $body['offers'][0]['offer_quantity']);
        self::assertArrayNotHasKey('order_offer_quantity', $body['offers'][0]);
        self::assertSame('NEW10', $body['offers'][0]['discount_code']);
        self::assertSame('19.00', $body['offers'][0]['order_offer_price']);
    }

    public function testAnUnknownCodeIsRejectedEvenThoughTheRequestItselfSucceeded(): void
    {
        // Recorded: an unknown code returns success: true with a 0.00 discount
        // and discount_code_valid: false. Reading the envelope's success flag
        // as the answer would accept every bad code for nothing off.
        $transport = new FakeVrioTransport();
        $transport->queueFixture('vrio-discount-rejected.json');

        $quote = $this->adapter($transport)->quotePromotion($this->envelope(), 'NOPE');

        self::assertFalse($quote->valid);
        self::assertSame(0, $quote->discountCents);
        self::assertSame('invalid', $quote->reason);
    }

    public function testAProviderOutageDegradesToAnUnavailableQuoteRatherThanAnError(): void
    {
        // [13.14].
        $transport = new FakeVrioTransport();
        $transport->queue(0, '', 'Could not resolve host: api.vrio.app');

        $quote = $this->adapter($transport)->quotePromotion($this->envelope(), 'NEW10');

        self::assertFalse($quote->valid);
        self::assertSame(0, $quote->discountCents);
    }

    public function testTheQuotedAndTheChargedLinesCarryTheCodeInExactlyTheSamePlaces(): void
    {
        // The defect this pins: the quote asked for a discount on every line
        // and the charge asked for one on the first line only, so a two-line
        // cart was shown 39.00 off and charged as if it were 12.00 -- 27.00
        // more than the buyer agreed to. Both payloads now come out of one
        // builder, and this compares them column for column.
        $lines = [
            new OrderLine('tirzepatide-5mg', 'Tirzepatide', '337', '3414', 12000, 1),
            new OrderLine('tirzepatide-10mg', 'Tirzepatide 10mg', '338', '3415', 27000, 1),
        ];

        $quoteTransport = new FakeVrioTransport();
        $quoteTransport->queueFixture('vrio-discount-calculated.json');
        $this->adapter($quoteTransport)->quotePromotion($this->envelope($lines), 'NEW10');

        $chargeTransport = new FakeVrioTransport();
        $chargeTransport->queueFixture('vrio-order-approved.json');
        $this->adapter($chargeTransport)->place(
            $this->envelope($lines, code: 'NEW10', discountCents: 3900),
            PaymentCredential::card('4111111100084444', '12', '2030', '123'),
        );

        $quoted = array_column($quoteTransport->body(0)['offers'], 'discount_code');
        $charged = array_column($chargeTransport->body(0)['offers'], 'discount_code');

        self::assertSame(['NEW10', 'NEW10'], $quoted);
        self::assertSame($quoted, $charged, 'the charge must ask for the discount the quote asked for');
    }

    public function testTheQuoteAndTheChargeStillDisagreeOnlyAboutTheQuantityKey(): void
    {
        // One builder, two endpoints: calculateDiscount reads the quantity from
        // offer_quantity and POST /orders reads it from order_offer_quantity.
        $lines = [new OrderLine('electrolytes', 'Electrolytes', '337', '2844', 1900, 3)];

        $quoteTransport = new FakeVrioTransport();
        $quoteTransport->queueFixture('vrio-discount-calculated.json');
        $this->adapter($quoteTransport)->quotePromotion($this->envelope($lines), 'NEW10');

        $chargeTransport = new FakeVrioTransport();
        $chargeTransport->queueFixture('vrio-order-approved.json');
        $this->adapter($chargeTransport)->place(
            $this->envelope($lines, code: 'NEW10'),
            PaymentCredential::card('4111111100084444', '12', '2030', '123'),
        );

        $quoteOffer = $quoteTransport->body(0)['offers'][0];
        $chargeOffer = $chargeTransport->body(0)['offers'][0];

        self::assertSame('3', $quoteOffer['offer_quantity']);
        self::assertArrayNotHasKey('order_offer_quantity', $quoteOffer);
        self::assertSame('3', $chargeOffer['order_offer_quantity']);
        self::assertArrayNotHasKey('offer_quantity', $chargeOffer);
        self::assertSame($quoteOffer['order_offer_price'], $chargeOffer['order_offer_price']);
    }

    public function testAChargeThatDoesNotMatchTheDisplayedTotalIsRecordedAndLoudlyLogged(): void
    {
        // The card has already been charged by the time we can compare, so the
        // order still has to be recorded and the buyer still has to reach a
        // receipt. What must not happen is the mismatch passing as a plain
        // success with nobody told: the recorded approval charged 120.00 and
        // this cart was shown 108.00.
        $log = new CapturedLog();
        $transport = new FakeVrioTransport();
        $transport->queueFixture('vrio-order-approved.json');

        $outcome = $this->adapter($transport, $log)->place(
            $this->envelope(code: 'NEW10', discountCents: 1200),
            PaymentCredential::card('4111111100084444', '12', '2030', '123'),
        );

        self::assertTrue($outcome->isPlaced(), 'a charge that stands must never be dropped on the floor');
        self::assertSame('34660', $outcome->reference);
        self::assertNotNull($outcome->chargeDiscrepancy);
        self::assertSame(10800, $outcome->chargeDiscrepancy->expectedCents);
        self::assertSame(12000, $outcome->chargeDiscrepancy->chargedCents);
        self::assertSame(1200, $outcome->chargeDiscrepancy->differenceCents());

        $error = $log->lastError();
        self::assertSame('payment.total_mismatch', $error['event'] ?? null);
        self::assertSame(10800, $error['context']['expected_cents'] ?? null);
        self::assertSame(12000, $error['context']['charged_cents'] ?? null);
        self::assertSame('34660', $error['context']['reference'] ?? null);
    }

    public function testAChargeThatMatchesTheDisplayedTotalReconcilesSilently(): void
    {
        $log = new CapturedLog();
        $transport = new FakeVrioTransport();
        $transport->queueFixture('vrio-order-approved.json');

        $outcome = $this->adapter($transport, $log)->place(
            $this->envelope(),
            PaymentCredential::card('4111111100084444', '12', '2030', '123'),
        );

        self::assertTrue($outcome->isPlaced());
        self::assertNull($outcome->chargeDiscrepancy);
        self::assertNull($log->lastError());
    }

    public function testAPanEchoedBackInsideTheGatewayRequestTextNeverReachesTheLog(): void
    {
        // gateway_request_text is, by name, the request handed to the acquiring
        // gateway -- the one that carried the PAN and the CVV. The recorded
        // sandbox stubs it, so this test supplies the value a live gateway
        // would echo. OperatorLog's redaction is key-based and cannot see
        // inside a string, so the scrub has to happen before the log is called.
        $log = new CapturedLog();
        $transport = new FakeVrioTransport();
        $transport->queue(200, json_encode(
            $this->declineEchoing('type=1&card=4111 1111 1111 1111&cvv=737&order=34661'),
            JSON_THROW_ON_ERROR,
        ));

        $this->adapter($transport, $log)->place(
            $this->envelope(),
            PaymentCredential::card('4111111100005555', '12', '2030', '123'),
        );

        self::assertSame('payment.not_placed', $log->lastWarning()['event'] ?? null, 'the envelope is still logged');
        self::assertStringNotContainsString('4111 1111 1111 1111', $log->contents());
        self::assertStringNotContainsString('4111111111111111', $log->contents());
        self::assertStringContainsString('34661', $log->contents(), 'an order id is not a card number');
    }

    public function testAPanInTheGatewaysFreeTextNeverReachesTheBuyerFacingReason(): void
    {
        // The reason falls through to gateway free text and is both rendered to
        // the buyer and persisted against the order.
        $transport = new FakeVrioTransport();
        $transport->queue(200, json_encode(
            $this->declineReading('Declined for card 4111111111111111'),
            JSON_THROW_ON_ERROR,
        ));

        $outcome = $this->adapter($transport)->place(
            $this->envelope(),
            PaymentCredential::card('4111111100005555', '12', '2030', '123'),
        );

        self::assertStringNotContainsString('4111111111111111', (string) $outcome->reason);
    }

    /**
     * The recorded decline's `data` node with its gateway request echo replaced.
     *
     * @return array<string, mixed>
     */
    private function declineEchoing(string $requestText): array
    {
        $fixture = $this->declineFixture();
        $fixture['data']['error']['transaction']['gateway_request_text'] = $requestText;

        /** @var array<string, mixed> $data */
        $data = $fixture['data'];

        return $data;
    }

    /**
     * The recorded decline's `data` node reduced to a gateway text reason.
     *
     * @return array<string, mixed>
     */
    private function declineReading(string $gatewayText): array
    {
        $fixture = $this->declineFixture();
        $fixture['data']['error']['message'] = '';
        $fixture['data']['error']['transaction']['gateway_response_text'] = $gatewayText;

        /** @var array<string, mixed> $data */
        $data = $fixture['data'];

        return $data;
    }

    /** @return array<string, mixed> */
    private function declineFixture(): array
    {
        $raw = (string) file_get_contents(__DIR__ . '/../../fixtures/vrio-order-declined.json');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public function testPingReportsTheCampaignItCanSee(): void
    {
        $transport = new FakeVrioTransport();
        $transport->queueFixture('vrio-campaign-items.json');

        $result = $this->adapter($transport)->ping();

        self::assertTrue($result['ok']);
        self::assertStringContainsString('campaign 147', $result['detail']);
        self::assertStringContainsString('20 items', $result['detail']);
    }

    public function testPingReportsAnUnreachableHostWithoutThrowing(): void
    {
        $transport = new FakeVrioTransport();
        $transport->queue(0, '', 'Could not resolve host: api.vrio.app');

        $result = $this->adapter($transport)->ping();

        self::assertFalse($result['ok']);
        self::assertStringContainsString('api.vrio.app', $result['detail']);
    }
}
