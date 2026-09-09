<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment;

/**
 * The adapter a deployment gets when no provider is configured.
 *
 * It declares no promotion support so the promo control is hidden, and every
 * placement is a decline carrying a message that names the configuration
 * problem without exposing it to the buyer. This exists so an unconfigured
 * storefront renders and browses normally and fails only at the point money
 * would move -- rather than 500ing on the checkout page, which is the failure
 * mode `[20.1]` exists to prevent.
 */
final class NullPaymentAdapter implements PaymentAdapter
{
    public const string DECLINE_MESSAGE = 'Online payment is temporarily unavailable. Please try again shortly.';

    public function capabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities(
            providerCategory: 'none',
            supportsPromotions: false,
            discountScope: AdapterCapabilities::SCOPE_LINE_ITEM,
            credentialStrategy: AdapterCapabilities::STRATEGY_TOKENIZATION,
            collectionSurface: AdapterCapabilities::SURFACE_THEME_FIELDS,
            requiredConfigKeys: [],
            routingHintKeys: [],
            pciPosture: 'No provider configured; no card data is transmitted or held.',
            supportsOrderSearch: false,
        );
    }

    /**
     * There is no provider to ask, so there is nothing to sweep.
     *
     * Declared false above and answered here as well, and the redundancy is
     * deliberate: `[21.9a]`'s sweep checks the declaration before it calls,
     * and this is what stops a caller that forgets from receiving an empty
     * list it would read as "no orders were lost". Nothing here reaches a
     * network, so nothing here can fail -- but it must not succeed either.
     */
    public function searchOrders(OrderSearch $search): OrderSearchResult
    {
        return OrderSearchResult::unsupported();
    }

    public function place(OrderEnvelope $order, PaymentCredential $credential): PlacementOutcome
    {
        return PlacementOutcome::declined(null, self::DECLINE_MESSAGE, 'no_provider_configured');
    }

    public function quotePromotion(OrderEnvelope $order, string $code): PromotionQuote
    {
        return PromotionQuote::rejected($code, 'unsupported');
    }

    /** @return array{ok: bool, detail: string} */
    public function ping(): array
    {
        return ['ok' => false, 'detail' => 'No payment provider is configured for this channel.'];
    }
}
