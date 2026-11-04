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
 * An adapter whose provider is told the amount rather than the code, so the
 * checkout quotes the code again as the buyer pays -- the shape Stripe has.
 *
 * Quotes are answered in the order given, the last one repeating: the first
 * is the answer when the code is applied, the second the answer at payment.
 * Every envelope it is quoted or placed with is kept, so a case can say what
 * the provider was asked and what it was told to charge.
 */
final class RequotingAdapter implements PaymentAdapter
{
    public const string REFERENCE = 'pi_requoted';

    /** @var list<OrderEnvelope> */
    public array $quoted = [];

    /** @var list<OrderEnvelope> */
    public array $placed = [];

    /** @var list<PromotionQuote> */
    private array $answers;

    public function __construct(PromotionQuote ...$answers)
    {
        $this->answers = array_values($answers);
    }

    public function capabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities(
            providerCategory: 'requoting',
            supportsPromotions: true,
            discountScope: AdapterCapabilities::SCOPE_ORDER_LEVEL,
            credentialStrategy: AdapterCapabilities::STRATEGY_TOKENIZATION,
            collectionSurface: AdapterCapabilities::SURFACE_PROVIDER_HOSTED,
            requiredConfigKeys: [],
            routingHintKeys: [],
            pciPosture: 'Test double; reaches nothing.',
            requotesPromotionAtPayment: true,
        );
    }

    public function place(OrderEnvelope $order, PaymentCredential $credential): PlacementOutcome
    {
        $this->placed[] = $order;

        return PlacementOutcome::placed(self::REFERENCE, 'succeeded');
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
        $answer = $this->answers[min(count($this->quoted), count($this->answers)) - 1];

        return $answer->valid
            ? PromotionQuote::accepted($code, $answer->discountCents, $answer->reference, $answer->lineDiscounts)
            : $answer;
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
