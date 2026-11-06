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
 * An adapter whose every placement is challenged by the buyer's bank, and
 * whose challenge the page can run (`[14.22]`).
 *
 * `resume()` answers from a queue, so a case states what the bank decided;
 * with nothing queued the payment is still waiting.
 */
final class ChallengingAdapter implements PaymentAdapter
{
    public const string REFERENCE = 'pi_challenge';

    public const string CLIENT_ACTION = 'pi_challenge_secret_never_stored';

    public int $placements = 0;

    /** The order the last placement was asked for, so a case can read what the checkout assembled. */
    public ?OrderEnvelope $placed = null;

    /** What this adapter settles every order as, standing in for a provider that narrows a hold; null honours the order's own. */
    public ?SettlementMode $settlesAs = null;

    /** @var list<PlacementOutcome> */
    private array $resumes = [];

    public function willResumeAs(PlacementOutcome $outcome): void
    {
        $this->resumes[] = $outcome;
    }

    public function capabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities(
            providerCategory: 'challenging',
            supportsPromotions: false,
            discountScope: AdapterCapabilities::SCOPE_ORDER_LEVEL,
            credentialStrategy: AdapterCapabilities::STRATEGY_TOKENIZATION,
            collectionSurface: AdapterCapabilities::SURFACE_PROVIDER_HOSTED,
            requiredConfigKeys: [],
            routingHintKeys: [],
            pciPosture: 'Test double; reaches nothing.',
            supportsClientAction: true,
        );
    }

    public function place(OrderEnvelope $order, PaymentCredential $credential): PlacementOutcome
    {
        ++$this->placements;
        $this->placed = $order;

        return PlacementOutcome::pendingAction(self::REFERENCE, '', 'requires_action', self::CLIENT_ACTION);
    }

    public function settlementFor(OrderEnvelope $order): SettlementMode
    {
        return $this->settlesAs ?? $order->settlement;
    }

    public function resume(string $reference): PlacementOutcome
    {
        return array_shift($this->resumes)
            ?? PlacementOutcome::pendingAction($reference, '', 'requires_action', self::CLIENT_ACTION);
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
        return CaptureOutcome::unsupported();
    }

    /** @return array{ok: bool, detail: string} */
    public function ping(): array
    {
        return ['ok' => true, 'detail' => 'Test double; reaches nothing.'];
    }
}
