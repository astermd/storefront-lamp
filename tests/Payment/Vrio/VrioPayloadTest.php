<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Payment\Vrio;

use AsterMD\Storefront\Payment\Buyer;
use AsterMD\Storefront\Payment\OrderEnvelope;
use AsterMD\Storefront\Payment\OrderLine;
use AsterMD\Storefront\Payment\PaymentCredential;
use AsterMD\Storefront\Payment\Vrio\CardScheme;
use AsterMD\Storefront\Payment\Vrio\VrioCredentials;
use AsterMD\Storefront\Payment\Vrio\VrioPayload;
use PHPUnit\Framework\TestCase;

final class VrioPayloadTest extends TestCase
{
    private function credentials(): VrioCredentials
    {
        return new VrioCredentials('api.vrio.app', '', 'test-key', 147, 1);
    }

    /** @param list<OrderLine>|null $lines */
    private function envelope(?array $lines = null, int $discountCents = 0, ?string $code = null, ?string $session = 'sess-uuid-1'): OrderEnvelope
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
            attribution: ['utm_source' => 'fb'],
            sessionUuid: $session,
            clientIp: '203.0.113.7',
            userAgent: 'Mozilla/5.0 (probe)',
            idempotencyKey: 'idem-1',
            anchorSlug: 'tirzepatide-5mg',
        );
    }

    private function card(): PaymentCredential
    {
        return PaymentCredential::card('4111111100084444', '12', '2030', '123');
    }

    public function testThePayloadCarriesTheRoutingHintsAndForcesTheCampaign(): void
    {
        // [14.13]: orders are placed against a channel-level campaign, with
        // campaign forcing and offer restriction enabled.
        $body = VrioPayload::forOrder($this->envelope(), $this->card(), $this->credentials(), 1);

        self::assertSame(147, $body['campaign_id']);
        self::assertSame(1, $body['connection_id']);
        self::assertTrue($body['force_campaign_id']);
        self::assertTrue($body['offers_restrict']);
        self::assertSame(1, $body['shipping_profile_id']);
    }

    public function testPlacementIsSingleStep(): void
    {
        $body = VrioPayload::forOrder($this->envelope(), $this->card(), $this->credentials(), 1);

        self::assertSame('process', $body['action']);
    }

    public function testEachLineCarriesBothItsOfferAndItsItem(): void
    {
        // Recorded live: sending an offer without an item is refused outright
        // with "Item id required for offer 337", because one offer id is
        // shared across every product on this channel.
        $body = VrioPayload::forOrder($this->envelope(), $this->card(), $this->credentials(), 1);

        self::assertCount(1, $body['offers']);
        self::assertSame('337', $body['offers'][0]['offer_id']);
        self::assertSame('3414', $body['offers'][0]['item_id']);
        self::assertSame('1', $body['offers'][0]['order_offer_quantity']);
    }

    public function testTheLinePriceIsSentAsATwoPlaceDecimalString(): void
    {
        // The provider will price the line from the item if we omit this, and
        // its price and ours can differ -- the channel prices Tirzepatide 10mg
        // at one figure and the provider's item catalog at another. Sending
        // ours is what makes the displayed total and the charge the same
        // number.
        $body = VrioPayload::forOrder($this->envelope(), $this->card(), $this->credentials(), 1);

        self::assertSame('120.00', $body['offers'][0]['order_offer_price']);
    }

    public function testTheDiscountCodeAttachesToEveryChargeableLine(): void
    {
        // [13.16] / [14.6a]: line-item scope means each line carries its own
        // code, not that the code is attached once. Recorded against the live
        // sandbox with a read-only calculateDiscount probe: with the code on
        // every line offers 337 and 338 return 12.00 and 27.00; with it on the
        // first line only, 338 returns 0.00 and discount_code_valid null. The
        // quote is what the buyer agreed to, so the charge has to carry the
        // code exactly where the quote carried it.
        $lines = [
            new OrderLine('tirzepatide-5mg', 'Tirzepatide', '337', '3414', 12000, 1),
            new OrderLine('nad-500', 'NAD+', '337', '3411', 10000, 1),
        ];

        $body = VrioPayload::forOrder($this->envelope($lines, 1200, 'SAVE10'), $this->card(), $this->credentials(), 1);

        self::assertSame('SAVE10', $body['offers'][0]['discount_code']);
        self::assertSame('SAVE10', $body['offers'][1]['discount_code']);
    }

    public function testAnOrderWithNoPromotionSendsAnEmptyCodeOnEveryLine(): void
    {
        $lines = [
            new OrderLine('tirzepatide-5mg', 'Tirzepatide', '337', '3414', 12000, 1),
            new OrderLine('nad-500', 'NAD+', '337', '3411', 10000, 1),
        ];

        $body = VrioPayload::forOrder($this->envelope($lines), $this->card(), $this->credentials(), 1);

        self::assertSame(['', ''], array_column($body['offers'], 'discount_code'));
    }

    public function testALineWithNoProviderMappingIsNotSent(): void
    {
        // The channel gives the free Syringe add-on no mapping at all, and the
        // provider refuses an offer with no item, so there is nothing to send.
        $lines = [
            new OrderLine('tirzepatide-5mg', 'Tirzepatide', '337', '3414', 12000, 1),
            new OrderLine('syringe', 'Syringe', null, null, 0, 1),
        ];

        $body = VrioPayload::forOrder($this->envelope($lines), $this->card(), $this->credentials(), 1);

        self::assertCount(1, $body['offers']);
    }

    public function testBillingRepeatsTheShippingAddressAndSaysSo(): void
    {
        // [13.2]. The owner's working payload sends both the flag and the full
        // bill_* set, so this sends both rather than choosing.
        $body = VrioPayload::forOrder($this->envelope(), $this->card(), $this->credentials(), 1);

        self::assertTrue($body['shipping_same']);
        self::assertSame('350 5th Avenue', $body['ship_address1']);
        self::assertSame('350 5th Avenue', $body['bill_address1']);
        self::assertSame('NY', $body['ship_state']);
        self::assertSame('NY', $body['bill_state']);
        self::assertSame('US', $body['ship_country']);
    }

    public function testTheRequestContextTravelsWithTheOrder(): void
    {
        // [13.22]: the visitor's IP, user agent and analytics session are what
        // let the provider and the EMR correlate a charge to a browsing session.
        $body = VrioPayload::forOrder($this->envelope(), $this->card(), $this->credentials(), 1);

        self::assertSame('203.0.113.7', $body['ip_address']);
        self::assertSame('Mozilla/5.0 (probe)', $body['user_agent']);
        self::assertSame('sess-uuid-1', $body['session_id']);
    }

    public function testAnOrderWithNoAnalyticsSessionOmitsTheSessionFieldEntirely(): void
    {
        // A synthetic identifier is forbidden ([20.8]), and an analytics-off
        // deployment must still be able to take an order ([20.1]).
        $body = VrioPayload::forOrder($this->envelope(session: null), $this->card(), $this->credentials(), 1);

        self::assertArrayNotHasKey('session_id', $body);
    }

    public function testAttributionIsMappedIntoTheProvidersSlots(): void
    {
        $body = VrioPayload::forOrder($this->envelope(), $this->card(), $this->credentials(), 1);

        self::assertSame('fb', $body['tracking15']);
    }

    public function testTheCardTravelsWithItsDerivedSchemeCode(): void
    {
        $body = VrioPayload::forOrder($this->envelope(), $this->card(), $this->credentials(), 1);

        self::assertSame(1, $body['payment_method_id'], 'payment_method_id 1 is Credit Card');
        self::assertSame(CardScheme::VISA, $body['card_type_id']);
        self::assertSame('4111111100084444', $body['card_number']);
        self::assertSame('12', $body['card_exp_month']);
        self::assertSame('2030', $body['card_exp_year']);
        self::assertSame('123', $body['card_cvv']);
    }

    public function testAStoredInstrumentSendsTheHandleAndNoCardFields(): void
    {
        // [15.3]: the number stays in the provider's vault, so a reference
        // charge names the instrument and carries nothing else.
        $payment = VrioPayload::paymentFor(
            PaymentCredential::stored(['customer_id' => '13996', 'customer_card_id' => '16815']),
        );

        self::assertSame([
            'payment_method_id' => 1,
            'customer_id' => '13996',
            'customer_card_id' => '16815',
        ], $payment);

        foreach (['card_number', 'card_cvv', 'card_exp_month', 'card_exp_year', 'card_type_id'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $payment);
        }
    }

    public function testACredentialWithNoUsablePaymentBlockYieldsNothing(): void
    {
        self::assertSame([], VrioPayload::paymentFor(PaymentCredential::orderReference('34788')));
    }

    public function testAStoredInstrumentMissingHalfItsHandleYieldsNothing(): void
    {
        // Recorded: the provider refuses a card id whose customer does not own
        // it, so half a pair is no credential at all.
        self::assertSame([], VrioPayload::paymentFor(PaymentCredential::stored(['customer_id' => '13996'])));
    }

    public function testTheCardSchemeCodeIsDerivedFromTheNumbersPrefix(): void
    {
        self::assertSame(CardScheme::VISA, CardScheme::codeFor('4111111111111111'));
        self::assertSame(CardScheme::MASTERCARD, CardScheme::codeFor('5555555555554444'));
        self::assertSame(CardScheme::MASTERCARD, CardScheme::codeFor('2223003122003222'));
        self::assertSame(CardScheme::AMEX, CardScheme::codeFor('378282246310005'));
        self::assertSame(CardScheme::DISCOVER, CardScheme::codeFor('6011111111111117'));
        self::assertSame(CardScheme::VISA, CardScheme::codeFor('9999999999999999'), 'an unknown prefix must not block the order');
        self::assertSame(CardScheme::VISA, CardScheme::codeFor(''));
    }

    public function testTheProvidersOwnTestCardsResolveToVisa(): void
    {
        // 4111111100084444 (approve) and 4111111100005555 (decline) are the
        // provider's documented test cards, and both were accepted live with
        // card_type_id 2.
        self::assertSame(2, CardScheme::codeFor('4111111100084444'));
        self::assertSame(2, CardScheme::codeFor('4111111100005555'));
    }

    public function testTheIdempotencyKeyIsNotSentToTheProvider(): void
    {
        // Recorded: the provider ignores connection_order_id on create and
        // echoes back its own order id, and posting an identical payload twice
        // charges twice. Sending our key would imply a guarantee that does not
        // exist; the guard is entirely storefront-side.
        $body = VrioPayload::forOrder($this->envelope(), $this->card(), $this->credentials(), 1);

        self::assertArrayNotHasKey('connection_order_id', $body);
        self::assertStringNotContainsString('idem-1', json_encode($body, JSON_THROW_ON_ERROR));
    }
}
