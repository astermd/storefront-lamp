<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Observability;

use AsterMD\Storefront\Payment\AdapterCapabilities;
use AsterMD\Storefront\Payment\OrderEnvelope;
use AsterMD\Storefront\Payment\OrderSearch;
use AsterMD\Storefront\Payment\OrderSearchResult;
use AsterMD\Storefront\Payment\PaymentAdapter;
use AsterMD\Storefront\Payment\PaymentCredential;
use AsterMD\Storefront\Payment\PromotionQuote;
use AsterMD\Storefront\Payment\PlacementOutcome;

/**
 * `[20.9]` over the provider boundary: placement, promotion, and the reverse
 * sweep's order search.
 *
 * This is the boundary where a rising latency is a business fact rather than
 * an engineering one — a provider that has slowed to eight seconds converts
 * worse than one that declines outright, and nothing else in this application
 * would notice.
 *
 * **Three things are deliberately not instrumented.**
 * {@see PaymentAdapter::capabilities()} answers from the adapter's own
 * construction and reaches nothing. {@see PaymentAdapter::ping()} is an
 * operator smoke test that already prints its own result, and timing it would
 * file a console command's output in the request log. Both pass straight
 * through.
 *
 * **What is recorded about a placement is its state and the provider's own
 * status vocabulary — never the decline reason.** The reason is text the
 * provider composed and is shown to the buyer (`[13.28]`); `[20.6]` and
 * `[20.14]` keep somebody else's sentence about our order out of the log,
 * where neither the key-based redaction nor the Luhn-based scrubber can see
 * inside it. `rawStatus` is the provider's fixed code and carries no free
 * text, which is the property that lets it be reported at all — the same
 * distinction {@see \AsterMD\Storefront\Support\FailureDigest} draws.
 */
final class InstrumentedPaymentAdapter implements PaymentAdapter
{
    public function __construct(
        private readonly PaymentAdapter $inner,
        private readonly BoundaryTimer $timer,
    ) {
    }

    public function capabilities(): AdapterCapabilities
    {
        return $this->inner->capabilities();
    }

    public function place(OrderEnvelope $order, PaymentCredential $credential): PlacementOutcome
    {
        return $this->timer->measure(
            Boundary::ProviderPlacement,
            fn (): PlacementOutcome => $this->inner->place($order, $credential),
            static fn (PlacementOutcome $outcome): array => [
                'outcome' => $outcome->state,
                'raw_status' => $outcome->rawStatus,
                'reference' => $outcome->reference,
                'discrepancy' => $outcome->chargeDiscrepancy !== null,
            ],
            [
                'session' => $order->sessionUuid,
                'lines' => count($order->lines),
                'total_cents' => $order->totalCents,
                'currency' => $order->currency,
            ],
        );
    }

    public function quotePromotion(OrderEnvelope $order, string $code): PromotionQuote
    {
        return $this->timer->measure(
            Boundary::PromotionQuote,
            fn (): PromotionQuote => $this->inner->quotePromotion($order, $code),
            static fn (PromotionQuote $quote): array => [
                'outcome' => $quote->valid ? 'accepted' : 'rejected',
                'discount_cents' => $quote->discountCents,
            ],
            ['session' => $order->sessionUuid, 'code' => $code],
        );
    }

    public function searchOrders(OrderSearch $search): OrderSearchResult
    {
        return $this->timer->measure(
            Boundary::ProviderOrderSearch,
            fn (): OrderSearchResult => $this->inner->searchOrders($search),
            static fn (OrderSearchResult $result): array => [
                // An unanswered search and an empty one are separated here for
                // the reason the result object separates them: a sweep that
                // read an outage as "no orders were lost" is the silent
                // failure reconciliation exists to end (`[21.9a]`).
                'outcome' => match (true) {
                    $result->failureReason === OrderSearchResult::UNSUPPORTED => 'unsupported',
                    !$result->ok => 'failed',
                    default => 'ok',
                },
                'failure_reason' => $result->failureReason,
                'orders' => count($result->orders),
                'reported_total' => $result->reportedTotal,
                'truncated' => $result->truncated(),
            ],
            ['limit' => $search->limit],
        );
    }

    /** @return array{ok: bool, detail: string} */
    public function ping(): array
    {
        return $this->inner->ping();
    }
}
