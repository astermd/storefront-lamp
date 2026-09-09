<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Payment;

use AsterMD\Storefront\Payment\AdapterCapabilities;
use AsterMD\Storefront\Payment\OrderEnvelope;
use AsterMD\Storefront\Payment\OrderSearch;
use AsterMD\Storefront\Payment\OrderSearchResult;
use AsterMD\Storefront\Payment\PaymentAdapter;
use AsterMD\Storefront\Payment\PaymentCredential;
use AsterMD\Storefront\Payment\PlacementOutcome;
use AsterMD\Storefront\Payment\PromotionQuote;

/**
 * The shipped adapter with two of its declarations overridden.
 *
 * A decorator rather than a hand-written fake because the point of the cases
 * that use it is that the *storefront* adapts to what an adapter declares
 * (`[14.4]`, `[15.14]`) — so the placement behind the declaration has to stay
 * the real one, or the test would be proving something about a stub.
 */
final class RedeclaredAdapter implements PaymentAdapter
{
    public function __construct(
        private readonly PaymentAdapter $inner,
        private readonly ?string $credentialStrategy,
        private readonly bool $supportsPromotions,
    ) {
    }

    public function capabilities(): AdapterCapabilities
    {
        $inner = $this->inner->capabilities();

        return new AdapterCapabilities(
            providerCategory: $inner->providerCategory,
            supportsPromotions: $this->supportsPromotions,
            discountScope: $inner->discountScope,
            credentialStrategy: $this->credentialStrategy ?? $inner->credentialStrategy,
            collectionSurface: $inner->collectionSurface,
            requiredConfigKeys: $inner->requiredConfigKeys,
            routingHintKeys: $inner->routingHintKeys,
            pciPosture: $inner->pciPosture,
            supportsRefund: $inner->supportsRefund,
            supportsRecurring: $inner->supportsRecurring,
            supportsOrderSearch: $inner->supportsOrderSearch,
        );
    }

    public function place(OrderEnvelope $order, PaymentCredential $credential): PlacementOutcome
    {
        return $this->inner->place($order, $credential);
    }

    public function quotePromotion(OrderEnvelope $order, string $code): PromotionQuote
    {
        return $this->inner->quotePromotion($order, $code);
    }

    public function searchOrders(OrderSearch $search): OrderSearchResult
    {
        return $this->inner->searchOrders($search);
    }

    /** @return array{ok: bool, detail: string} */
    public function ping(): array
    {
        return $this->inner->ping();
    }
}
