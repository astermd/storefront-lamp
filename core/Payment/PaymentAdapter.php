<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment;

/**
 * The whole provider boundary (`[14.1]`).
 *
 * Every provider-specific fact lives behind this: payload field names, status
 * vocabularies, attribution slot maps, credential handling. Nothing above this
 * interface may name a provider (`[14.3]`), and adding one must be an adapter
 * plus a registry entry and nothing else (`[14.2]`).
 *
 * Capabilities 9 (refund/void) and 10 (recurring) are declared on
 * {@see AdapterCapabilities} and deliberately have no methods this revision --
 * a declared slot with no caller is honest; a method nobody implements is not.
 *
 * Order search is the other side of that same rule: it is declared on
 * {@see AdapterCapabilities::$supportsOrderSearch} **and** gets a method,
 * because it has a caller. `[21.9a]`'s reverse sweep has to ask a provider
 * what orders it holds, and `[14.3]` forbids anything above this interface
 * naming one -- so without a method here the sweep could only exist by
 * reaching past the boundary into an adapter's own client.
 */
interface PaymentAdapter
{
    /** Capabilities 1, 2, 6, 7, 8, 9, 10 — everything the storefront asks before it renders anything. */
    public function capabilities(): AdapterCapabilities;

    /**
     * Capability 4 and 5: submit the order and say what happened.
     *
     * Must never throw for a provider-side outcome. A refused card, a missing
     * reference, a malformed response and a network failure are all
     * {@see PlacementOutcome::declined()} with a buyer-safe reason
     * (`[13.25]`, `[13.31]`) -- the visitor has to be able to see a reason and
     * retry, not hit an error page.
     */
    public function place(OrderEnvelope $order, PaymentCredential $credential): PlacementOutcome;

    /**
     * Capability 6: validate a code against the order's lines and price it.
     *
     * Only called when {@see AdapterCapabilities::$supportsPromotions} is true;
     * the storefront hides the promo control otherwise (`[13.18]`, `[14.4]`).
     * Failures degrade to a rejected quote rather than an error (`[13.14]`).
     */
    public function quotePromotion(OrderEnvelope $order, string $code): PromotionQuote;

    /**
     * The orders this provider holds inside a window, for `[21.9a]`'s reverse
     * reconciliation.
     *
     * Only meaningful when {@see AdapterCapabilities::$supportsOrderSearch} is
     * true; an adapter that declares it false answers
     * {@see OrderSearchResult::unsupported()} rather than throwing, so a
     * deployment with no provider configured produces no sweep instead of a
     * crashed job (`[20.1]`).
     *
     * **Must never throw for a provider-side outcome**, on the same terms as
     * {@see self::place()}. A transport failure, a rejected request and a body
     * that will not decode are all a failed {@see OrderSearchResult} -- and a
     * failed result is explicitly not an empty one, because a sweep that read
     * an outage as "no orders were lost" is the silent failure reconciliation
     * exists to end.
     *
     * The window is provider-neutral. Which account it is read against is a
     * routing hint the adapter already holds (`[14.6c]`) and never a parameter
     * the caller supplies, so the sweep cannot learn how this provider
     * partitions its orders.
     */
    public function searchOrders(OrderSearch $search): OrderSearchResult;

    /**
     * Whether the credentials this adapter was built with reach the provider.
     *
     * Backs `bin/console provider:ping`. Returns a human-readable line on
     * success and throws nothing -- a failure is a message, not an exception,
     * because an operator running a smoke test wants the reason.
     *
     * @return array{ok: bool, detail: string}
     */
    public function ping(): array;
}
