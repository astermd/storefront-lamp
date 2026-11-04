<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Checkout;

use AsterMD\Storefront\Payment\AdapterCapabilities;
use AsterMD\Storefront\Payment\CaptureOutcome;
use AsterMD\Storefront\Payment\CaptureRequest;
use AsterMD\Storefront\Payment\OrderEnvelope;
use AsterMD\Storefront\Payment\OrderSearch;
use AsterMD\Storefront\Payment\OrderSearchResult;
use AsterMD\Storefront\Payment\PaymentAdapter;
use AsterMD\Storefront\Payment\PaymentCredential;
use AsterMD\Storefront\Payment\PlacementOutcome;
use AsterMD\Storefront\Payment\PromotionQuote;
use AsterMD\Storefront\Payment\SettlementMode;

/**
 * An adapter that prices every code at $10 off when it is applied, and then
 * finds at payment that the code no longer applies -- the coupon ran out in
 * between. It keeps the envelopes it was handed for each, so a case can say
 * what the provider was asked about.
 */
final class RepricingAdapter implements PaymentAdapter
{
    public const string NOTICE = 'The coupon has run out.';

    /** @var list<OrderEnvelope> */
    public array $quoted = [];

    /** @var list<OrderEnvelope> */
    public array $placed = [];

    public function capabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities(
            providerCategory: 'repricing',
            supportsPromotions: true,
            discountScope: AdapterCapabilities::SCOPE_ORDER_LEVEL,
            credentialStrategy: AdapterCapabilities::STRATEGY_TOKENIZATION,
            collectionSurface: AdapterCapabilities::SURFACE_PROVIDER_HOSTED,
            requiredConfigKeys: [],
            routingHintKeys: [],
            pciPosture: 'Test double; reaches nothing.',
        );
    }

    public function place(OrderEnvelope $order, PaymentCredential $credential): PlacementOutcome
    {
        $this->placed[] = $order;

        return PlacementOutcome::declined(null, self::NOTICE, 'promotion_repriced');
    }

    public function settlementFor(OrderEnvelope $order): SettlementMode
    {
        return $order->settlement;
    }

    public function resume(string $reference): PlacementOutcome
    {
        return PlacementOutcome::declined($reference, 'Test double; resume is not exercised here.', 'unsupported');
    }

    public function quotePromotion(OrderEnvelope $order, string $code): PromotionQuote
    {
        $this->quoted[] = $order;

        return PromotionQuote::accepted($code, 1000, '6a01b2c3d4e5f6a7b8c9d0f9');
    }

    public function searchOrders(OrderSearch $search): OrderSearchResult
    {
        return OrderSearchResult::unsupported();
    }

    public function capture(CaptureRequest $request): CaptureOutcome
    {
        return CaptureOutcome::unsupported();
    }

    /** @return array{ok: bool, detail: string} */
    public function ping(): array
    {
        return ['ok' => true, 'detail' => 'Test double; reaches nothing.'];
    }
}
