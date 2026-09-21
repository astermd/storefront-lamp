<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

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

/**
 * An adapter that records the {@see CaptureRequest} it was handed and reports
 * success.
 *
 * A hand-written fake rather than a decorated real adapter, unlike
 * {@see \AsterMD\Storefront\Tests\Payment\RedeclaredAdapter}, because what is
 * under test here is not the adapter at all — it is whether
 * `bin/console payment:capture` reconstructed the order's lines from the local
 * record before calling one. The only way to see that is to look at the request
 * it built, and a real adapter answers from a transport and says nothing about
 * it.
 *
 * It declares authorize-and-capture support because the command checks that
 * before calling, and a fake that declared false would be refused before the
 * assertion this exists to make.
 */
final class RecordingCaptureAdapter implements PaymentAdapter
{
    public ?CaptureRequest $seen = null;

    public function capabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities(
            providerCategory: 'recording',
            supportsPromotions: false,
            discountScope: AdapterCapabilities::SCOPE_ORDER_LEVEL,
            credentialStrategy: AdapterCapabilities::STRATEGY_ORDER_REFERENCE,
            collectionSurface: AdapterCapabilities::SURFACE_THEME_FIELDS,
            requiredConfigKeys: [],
            routingHintKeys: [],
            pciPosture: 'Test double; reaches nothing.',
            supportsAuthorizeCapture: true,
        );
    }

    public function place(OrderEnvelope $order, PaymentCredential $credential): PlacementOutcome
    {
        return PlacementOutcome::declined(null, 'Test double; placement is not exercised here.', 'unsupported');
    }

    public function quotePromotion(OrderEnvelope $order, string $code): PromotionQuote
    {
        return PromotionQuote::rejected($code, 'unsupported');
    }

    public function searchOrders(OrderSearch $search): OrderSearchResult
    {
        return OrderSearchResult::unsupported();
    }

    public function capture(CaptureRequest $request): CaptureOutcome
    {
        $this->seen = $request;

        return CaptureOutcome::captured($request->reference);
    }

    /** @return array{ok: bool, detail: string} */
    public function ping(): array
    {
        return ['ok' => true, 'detail' => 'Test double; reaches nothing.'];
    }
}
