<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Payment\Stripe;

use AsterMD\Storefront\Emr\CouponGateway;
use AsterMD\Storefront\Emr\NullCouponGateway;
use AsterMD\Storefront\Payment\AdapterCapabilities;
use AsterMD\Storefront\Payment\Buyer;
use AsterMD\Storefront\Payment\CaptureRequest;
use AsterMD\Storefront\Payment\CardBrand;
use AsterMD\Storefront\Payment\OrderEnvelope;
use AsterMD\Storefront\Payment\OrderLine;
use AsterMD\Storefront\Payment\OrderSearch;
use AsterMD\Storefront\Payment\PaymentCredential;
use AsterMD\Storefront\Payment\PlacementOutcome;
use AsterMD\Storefront\Payment\PromotionQuote;
use AsterMD\Storefront\Payment\SettlementMode;
use AsterMD\Storefront\Payment\Stripe\StripeAdapter;
use AsterMD\Storefront\Payment\Stripe\StripeApiFactory;
use AsterMD\Storefront\Payment\Stripe\StripeCredentials;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\FakeStripeTransport;
use PHPUnit\Framework\TestCase;

/**
 * The Stripe adapter against Stripe's documented response shapes.
 *
 * The bodies below are built from Stripe's API reference rather than recorded
 * from the wire; `docs/INTEGRATION-NOTES.md` says so, and a test-mode recording
 * should replace them the first time one is made.
 */
final class StripeAdapterTest extends TestCase
{
    private const string PRODUCT = '6a6c202cadc82ad3598f48af';

    private const string VARIANT = '6a6c5d5a62f043873a8afe2d';

    private const string PLAN = '6adf4668a103373c607b85a1';

    private const string COUPON = '6a01b2c3d4e5f6a7b8c9d0f9';

    private const string CHANNEL = '6acf1d207e507866cd5c453d';

    private FakeStripeTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new FakeStripeTransport();
    }

    private function adapter(?CapturedLog $log = null, ?CouponGateway $coupons = null): StripeAdapter
    {
        return new StripeAdapter(
            new StripeCredentials('sk_test_secret', 'pk_test_publishable'),
            new StripeApiFactory($this->transport),
            ($log ?? new CapturedLog())->log,
            $coupons ?? new NullCouponGateway(),
            self::CHANNEL,
            'https://shop.example.com/checkout/confirm/',
        );
    }

    /** @param list<OrderLine>|null $lines */
    private function envelope(
        ?array $lines = null,
        SettlementMode $settlement = SettlementMode::Capture,
        bool $followOn = false,
        ?string $code = null,
        ?string $couponId = null,
        int $discountCents = 0,
        array $attribution = [],
    ): OrderEnvelope {
        $lines ??= [self::line()];
        $subtotal = array_sum(array_map(static fn (OrderLine $l): int => $l->lineTotalCents(), $lines));

        return new OrderEnvelope(
            lines: $lines,
            buyer: new Buyer('Ada', 'Lovelace', 'ada@example.com', '2125551234', '350 5th Avenue', 'New York', 'NY', '10118'),
            subtotalCents: $subtotal,
            discountCents: $discountCents,
            totalCents: $subtotal - $discountCents,
            currency: 'USD',
            promotionCode: $code,
            attribution: $attribution,
            sessionUuid: '123e4567-e89b-12d3-a456-426614174000',
            clientIp: '203.0.113.7',
            userAgent: 'probe/1.0',
            idempotencyKey: 'idem-1',
            anchorSlug: 'tirzepatide',
            settlement: $settlement,
            promotionReference: $couponId,
            followOn: $followOn,
        );
    }

    private static function line(string $kind = 'rx', ?string $plan = null, int $price = 12000): OrderLine
    {
        return new OrderLine(
            'tirzepatide',
            'Tirzepatide (5mg/mL)',
            'price_1Tirz',
            'prod_tirz',
            $price,
            1,
            $kind,
            emrProductId: self::PRODUCT,
            emrVariantId: self::VARIANT,
            planId: $plan,
        );
    }

    /** @return array<string, mixed> */
    private static function paymentIntent(string $status, int $amount = 12000, array $extra = []): array
    {
        return $extra + [
            'id' => 'pi_3QxSemaA1b2C3d4E',
            'object' => 'payment_intent',
            'status' => $status,
            'amount' => $amount,
            'currency' => 'usd',
            'livemode' => false,
            'customer' => 'cus_VWTGV3w2nz1PqB',
            'client_secret' => 'pi_3QxSemaA1b2C3d4E_secret_abc',
            'payment_method' => [
                'id' => 'pm_1Card',
                'object' => 'payment_method',
                'type' => 'card',
                'card' => ['brand' => 'visa', 'exp_month' => 4, 'exp_year' => 2031, 'last4' => '4242'],
            ],
        ];
    }

    private function queueNoCustomer(): void
    {
        $this->transport->queue(200, ['object' => 'list', 'data' => [], 'has_more' => false, 'url' => '/v1/customers']);
        $this->transport->queue(200, ['id' => 'cus_New1', 'object' => 'customer', 'email' => 'ada@example.com']);
    }

    public function testTheAdapterTokenizesInProviderHostedFieldsAndPublishesOnlyThePublishableKey(): void
    {
        $capabilities = $this->adapter()->capabilities();

        self::assertSame('stripe', $capabilities->providerCategory);
        self::assertSame(AdapterCapabilities::STRATEGY_TOKENIZATION, $capabilities->credentialStrategy);
        self::assertFalse($capabilities->rendersOwnCardFields());
        self::assertTrue($capabilities->supportsClientAction);
        self::assertTrue($capabilities->supportsAuthorizeCapture);
        self::assertSame('pk_test_publishable', $capabilities->clientConfig['publishable_key']);
        self::assertStringNotContainsString('sk_test_secret', (string) json_encode($capabilities->clientConfig));
    }

    public function testPromotionsAreOfferedOnlyWhenTheEmrCanPriceThem(): void
    {
        self::assertFalse($this->adapter()->capabilities()->supportsPromotions);
        self::assertTrue($this->adapter(coupons: new FixedCoupons())->capabilities()->supportsPromotions);
    }

    public function testASucceededPaymentIsPlacedAndKeepsTheCustomerAndCardForTheNextCharge(): void
    {
        $this->queueNoCustomer();
        $this->transport->queue(200, self::paymentIntent('succeeded'));

        $outcome = $this->adapter()->place($this->envelope(), PaymentCredential::token('ctoken_1Abc'));

        self::assertTrue($outcome->isPlaced());
        self::assertSame('pi_3QxSemaA1b2C3d4E', $outcome->reference);
        self::assertSame(SettlementMode::Capture, $outcome->settlement);
        self::assertSame(CardBrand::Visa, $outcome->providerCardBrand);
        self::assertSame('04/31', $outcome->providerCardExpiry);
        self::assertTrue((bool) $outcome->reusableCredential?->isReusable());
        self::assertSame(
            ['customer' => 'cus_VWTGV3w2nz1PqB', 'payment_method' => 'pm_1Card'],
            $outcome->reusableCredential?->handle,
        );
    }

    public function testTheBuyersExistingCustomerIsReusedRatherThanDuplicated(): void
    {
        $this->transport->queue(200, ['object' => 'list', 'data' => [['id' => 'cus_Existing', 'object' => 'customer']], 'has_more' => false, 'url' => '/v1/customers']);
        $this->transport->queue(200, self::paymentIntent('succeeded'));

        $this->adapter()->place($this->envelope(), PaymentCredential::token('ctoken_1Abc'));

        self::assertSame(['GET /v1/customers', 'POST /v1/payment_intents'], $this->transport->calls());
        self::assertSame(['email' => 'ada@example.com', 'limit' => 1], $this->transport->params(0));
        self::assertSame('cus_Existing', $this->transport->params(1)['customer']);
    }

    public function testANewBuyerGetsACustomerCreatedWithTheirContactDetails(): void
    {
        $this->queueNoCustomer();
        $this->transport->queue(200, self::paymentIntent('succeeded'));

        $this->adapter()->place($this->envelope(), PaymentCredential::token('ctoken_1Abc'));

        self::assertSame(['GET /v1/customers', 'POST /v1/customers', 'POST /v1/payment_intents'], $this->transport->calls());
        $customer = $this->transport->params(1);
        self::assertSame('ada@example.com', $customer['email']);
        self::assertSame('Ada Lovelace', $customer['name']);
        self::assertSame('350 5th Avenue', $customer['address']['line1']);
        self::assertSame('cus_New1', $this->transport->params(2)['customer']);
    }

    public function testThePaymentIntentCarriesTheContractTheEmrReadsBack(): void
    {
        $this->queueNoCustomer();
        $this->transport->queue(200, self::paymentIntent('succeeded'));

        $this->adapter()->place(
            $this->envelope(attribution: ['affiliate_id' => 'aff-9', 'utm_source' => 'google', 'gclid' => 'xyz']),
            PaymentCredential::token('ctoken_1Abc'),
        );

        $body = $this->transport->params(2);
        self::assertSame(12000, $body['amount']);
        self::assertSame('usd', $body['currency']);
        self::assertTrue($body['confirm']);
        self::assertSame('ctoken_1Abc', $body['confirmation_token']);
        self::assertSame('off_session', $body['setup_future_usage']);
        self::assertSame('automatic', $body['capture_method']);
        self::assertSame(['card'], $body['payment_method_types']);
        self::assertSame('https://shop.example.com/checkout/confirm/', $body['return_url']);
        self::assertSame('350 5th Avenue', $body['shipping']['address']['line1']);
        self::assertSame(
            [['product_name' => 'Tirzepatide (5mg/mL)', 'unit_cost' => 12000, 'quantity' => 1]],
            $body['amount_details']['line_items'],
        );

        $metadata = $body['metadata'];
        self::assertSame(json_encode([['product_id' => self::PRODUCT, 'variant_id' => self::VARIANT]]), $metadata['astermd_line_items']);
        self::assertSame('straight_sale', $metadata['astermd_order_type']);
        self::assertSame('automatic', $metadata['capture_method']);
        self::assertSame('123e4567-e89b-12d3-a456-426614174000', $metadata['astermd_session_id']);
        self::assertSame(self::CHANNEL, $metadata['storefront_channel_id']);
        self::assertSame('aff-9', $metadata['astermd_aff']);
        self::assertSame('google', $metadata['astermd_utm_source']);
        self::assertArrayNotHasKey('astermd_payment_mode', $metadata);
        self::assertArrayNotHasKey('coupon', $metadata);
    }

    public function testALineSoldOnAPlanIsNamedByThePlanAndMakesTheOrderACycle(): void
    {
        // The metadata the user's own storefront test writes for a subscription
        // order: the plan alone, a cycle, how it settles, and the code typed.
        $this->queueNoCustomer();
        $this->transport->queue(200, self::paymentIntent('succeeded', 9900));

        $this->adapter()->place(
            $this->envelope([self::line(plan: self::PLAN, price: 10900)], code: 'foobar', couponId: self::COUPON, discountCents: 1000),
            PaymentCredential::token('ctoken_1Abc'),
        );

        $body = $this->transport->params(2);
        self::assertSame('[{"plan_id":"' . self::PLAN . '"}]', $body['metadata']['astermd_line_items']);
        self::assertSame('cycle', $body['metadata']['astermd_order_type']);
        self::assertSame('automatic', $body['metadata']['capture_method']);
        self::assertSame('foobar', $body['metadata']['coupon']);
        self::assertSame(self::COUPON, $body['metadata']['astermd_coupon_id']);
        self::assertSame(9900, $body['amount']);
        self::assertSame(1000, $body['amount_details']['discount_amount']);
    }

    public function testAnAuthorizeOrderWithAPrescriptionIsHeldForTheEmrToCapture(): void
    {
        $this->queueNoCustomer();
        $this->transport->queue(200, self::paymentIntent('requires_capture'));

        $outcome = $this->adapter()->place(
            $this->envelope(settlement: SettlementMode::Authorize),
            PaymentCredential::token('ctoken_1Abc'),
        );

        $body = $this->transport->params(2);
        self::assertSame('manual', $body['capture_method']);
        self::assertSame('pre_auth', $body['metadata']['astermd_payment_mode']);
        self::assertSame('manual', $body['metadata']['capture_method']);
        self::assertTrue($outcome->isPlaced());
        self::assertTrue($outcome->isAuthorizedOnly());
    }

    public function testAHoldNobodyWouldCaptureIsChargedAtOnceInstead(): void
    {
        // No prescription: the EMR opens no case, so nothing would capture it.
        $this->queueNoCustomer();
        $this->transport->queue(200, self::paymentIntent('succeeded'));
        $this->adapter()->place(
            $this->envelope([self::line(kind: 'otc')], SettlementMode::Authorize),
            PaymentCredential::token('ctoken_1Abc'),
        );
        self::assertSame('automatic', $this->transport->params(2)['capture_method']);

        // An upsell: a second hold on a treatment is never captured automatically.
        $transport = $this->transport = new FakeStripeTransport();
        $transport->queue(200, self::paymentIntent('succeeded'));
        $this->adapter()->place(
            $this->envelope(settlement: SettlementMode::Authorize, followOn: true),
            PaymentCredential::stored(['customer' => 'cus_VWTGV3w2nz1PqB', 'payment_method' => 'pm_1Card']),
        );
        $body = $transport->params(0);
        self::assertSame('automatic', $body['capture_method']);
        self::assertSame('1', $body['metadata']['astermd_upsell']);
    }

    public function testAnUpsellChargesTheSavedCardWithTheBuyerAway(): void
    {
        $this->transport->queue(200, self::paymentIntent('succeeded'));

        $this->adapter()->place(
            $this->envelope(followOn: true),
            PaymentCredential::stored(['customer' => 'cus_VWTGV3w2nz1PqB', 'payment_method' => 'pm_1Card']),
        );

        // No customer lookup: the saved pair is charged as it is.
        self::assertSame(['POST /v1/payment_intents'], $this->transport->calls());
        $body = $this->transport->params(0);
        self::assertSame('cus_VWTGV3w2nz1PqB', $body['customer']);
        self::assertSame('pm_1Card', $body['payment_method']);
        self::assertTrue($body['off_session']);
        self::assertArrayNotHasKey('setup_future_usage', $body);
        self::assertArrayNotHasKey('return_url', $body);
    }

    public function testARefusedCardIsDeclinedWithStripesOwnWordsAndTheReference(): void
    {
        $this->queueNoCustomer();
        $this->transport->queue(402, ['error' => [
            'type' => 'card_error',
            'code' => 'card_declined',
            'decline_code' => 'insufficient_funds',
            'message' => 'Your card has insufficient funds.',
            'payment_intent' => self::paymentIntent('requires_payment_method'),
        ]]);

        $outcome = $this->adapter()->place($this->envelope(), PaymentCredential::token('ctoken_1Abc'));

        self::assertSame(PlacementOutcome::DECLINED, $outcome->state);
        self::assertSame('Your card has insufficient funds.', $outcome->reason);
        self::assertSame('insufficient_funds', $outcome->rawStatus);
        self::assertSame('pi_3QxSemaA1b2C3d4E', $outcome->reference);
    }

    public function testAVerificationTheBankAsksForIsPendingWithWhatThePageNeedsToRunIt(): void
    {
        $this->queueNoCustomer();
        $this->transport->queue(200, self::paymentIntent('requires_action', extra: [
            'next_action' => ['type' => 'use_stripe_sdk', 'use_stripe_sdk' => []],
        ]));

        $outcome = $this->adapter()->place($this->envelope(), PaymentCredential::token('ctoken_1Abc'));

        self::assertSame(PlacementOutcome::PENDING_ACTION, $outcome->state);
        self::assertSame('pi_3QxSemaA1b2C3d4E', $outcome->reference);
        self::assertSame('pi_3QxSemaA1b2C3d4E_secret_abc', $outcome->clientAction);
    }

    public function testResumeReadsWhatTheVerificationCameTo(): void
    {
        $this->transport->queue(200, self::paymentIntent('succeeded'));
        $placed = $this->adapter()->resume('pi_3QxSemaA1b2C3d4E');
        self::assertTrue($placed->isPlaced());
        self::assertSame(['GET /v1/payment_intents/pi_3QxSemaA1b2C3d4E'], $this->transport->calls());

        $this->transport->queue(200, self::paymentIntent('requires_payment_method', extra: [
            'last_payment_error' => ['message' => 'We are unable to authenticate your payment method.'],
        ]));
        $declined = $this->adapter()->resume('pi_3QxSemaA1b2C3d4E');
        self::assertSame(PlacementOutcome::DECLINED, $declined->state);
        self::assertSame('We are unable to authenticate your payment method.', $declined->reason);
    }

    public function testResumeThatCannotReachStripeStaysPendingRatherThanDeclining(): void
    {
        $this->transport->queueFailure(new \Stripe\Exception\ApiConnectionException('Could not connect to Stripe.'));

        $outcome = $this->adapter()->resume('pi_3QxSemaA1b2C3d4E');

        self::assertSame(PlacementOutcome::PENDING_ACTION, $outcome->state);
        self::assertNull($outcome->clientAction);
    }

    public function testEachAttemptHasItsOwnStripeIdempotencyKeyAndARetryReusesIt(): void
    {
        $keys = [];
        foreach (['ctoken_A', 'ctoken_A', 'ctoken_B'] as $token) {
            $this->transport = new FakeStripeTransport();
            $this->transport->queue(200, ['object' => 'list', 'data' => [['id' => 'cus_Existing']], 'has_more' => false, 'url' => '/v1/customers']);
            $this->transport->queue(200, self::paymentIntent('succeeded'));
            $this->adapter()->place($this->envelope(), PaymentCredential::token($token));
            $keys[] = $this->transport->header(1, 'Idempotency-Key');
        }

        self::assertNotNull($keys[0]);
        self::assertSame($keys[0], $keys[1]);
        self::assertNotSame($keys[0], $keys[2]);
    }

    public function testACardIsNeverChargedByThisAdapter(): void
    {
        $outcome = $this->adapter()->place($this->envelope(), PaymentCredential::card('4242424242424242', '04', '2031', '123'));

        self::assertSame('credential_unusable', $outcome->rawStatus);
        self::assertSame([], $this->transport->requests);
    }

    public function testAnOrderTheEmrCouldNotMapIsRefusedBeforeStripeIsCalled(): void
    {
        $unmapped = new OrderLine('mask', 'Mask', 'price_1Mask', 'prod_mask', 1500, 1);
        $outcome = $this->adapter()->place($this->envelope([$unmapped]), PaymentCredential::token('ctoken_1Abc'));
        self::assertSame('line_unmapped', $outcome->rawStatus);

        $many = array_fill(0, 8, self::line());
        $outcome = $this->adapter()->place($this->envelope($many), PaymentCredential::token('ctoken_1Abc'));
        self::assertSame('too_many_lines', $outcome->rawStatus);

        self::assertSame([], $this->transport->requests);
    }

    public function testARequestStripeRefusedReleasesTheKeyButAnUnansweredOneDoesNot(): void
    {
        $this->queueNoCustomer();
        $this->transport->queue(400, ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid currency.']]);
        $refused = $this->adapter()->place($this->envelope(), PaymentCredential::token('ctoken_1Abc'));
        self::assertSame('api_error', $refused->rawStatus);

        $this->transport = new FakeStripeTransport();
        $this->transport->queue(200, ['object' => 'list', 'data' => [['id' => 'cus_Existing']], 'has_more' => false, 'url' => '/v1/customers']);
        $this->transport->queueFailure(new \Stripe\Exception\ApiConnectionException('Timed out.'));
        $unanswered = $this->adapter()->place($this->envelope(), PaymentCredential::token('ctoken_1Abc'));
        self::assertSame('transport_error', $unanswered->rawStatus);
    }

    public function testCaptureIsLeftToTheEmr(): void
    {
        $outcome = $this->adapter()->capture(new CaptureRequest('pi_3QxSemaA1b2C3d4E'));

        self::assertFalse($outcome->isCaptured());
        self::assertSame([], $this->transport->requests);
    }

    public function testOrderSearchCountsOnlyThisChannelsPayments(): void
    {
        $this->transport->queue(200, [
            'object' => 'list',
            'url' => '/v1/payment_intents',
            'has_more' => false,
            'data' => [
                self::paymentIntent('succeeded', extra: ['id' => 'pi_Ours', 'created' => 1793170990, 'metadata' => ['storefront_channel_id' => self::CHANNEL]]),
                self::paymentIntent('succeeded', extra: ['id' => 'pi_Renewal', 'created' => 1793170991, 'metadata' => ['astermd_suborder_id' => 'x']]),
            ],
        ]);

        $result = $this->adapter()->searchOrders(OrderSearch::between(
            new \DateTimeImmutable('2026-10-28T00:00:00Z'),
            new \DateTimeImmutable('2026-10-28T23:59:59Z'),
        ));

        self::assertTrue($result->ok);
        self::assertSame(['pi_Ours'], array_map(static fn ($o): string => $o->reference, $result->orders));
        self::assertTrue($result->orders[0]->isCharged);
        self::assertTrue($result->orders[0]->isTest);
    }

    public function testACouponIsPricedByTheEmr(): void
    {
        $quote = $this->adapter(coupons: new FixedCoupons())->quotePromotion($this->envelope(), 'foobar');

        self::assertTrue($quote->valid);
        self::assertSame(1000, $quote->discountCents);
        self::assertSame(self::COUPON, $quote->reference);
    }
}

/** A coupon gateway that prices every code at $10 off. */
final class FixedCoupons implements CouponGateway
{
    public function quote(string $code, array $lines): PromotionQuote
    {
        return PromotionQuote::accepted($code, 1000, '6a01b2c3d4e5f6a7b8c9d0f9');
    }

    public function available(): bool
    {
        return true;
    }
}
