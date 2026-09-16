<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment\CheckoutChamp;

use AsterMD\Storefront\Payment\AdapterCapabilities;
use AsterMD\Storefront\Payment\CaptureOutcome;
use AsterMD\Storefront\Payment\OrderEnvelope;
use AsterMD\Storefront\Payment\OrderSearch;
use AsterMD\Storefront\Payment\OrderSearchResult;
use AsterMD\Storefront\Payment\PaymentAdapter;
use AsterMD\Storefront\Payment\PaymentCredential;
use AsterMD\Storefront\Payment\PlacementOutcome;
use AsterMD\Storefront\Payment\PromotionQuote;
use AsterMD\Storefront\Support\CardScrubber;
use AsterMD\Storefront\Support\OperatorLog;

/**
 * The CheckoutChamp adapter.
 *
 * The second implementation of `[14.1]`'s boundary, and the one that tests
 * whether the boundary was drawn in the right place. It is not shaped like the
 * first: placement is two calls rather than one, lines are numbered parameters
 * rather than an array, there is no promotion endpoint, and the credential is a
 * single customer id rather than a pair. None of that reaches anything above
 * this class — which is the whole claim `[14.2]` and `[14.3]` make.
 *
 * **Placement is two calls, because the provider has no single one.**
 * `POST /leads/import/` creates the customer and answers a `sessionId`;
 * `POST /order/import/` bills it. Calling the second alone answers "Customer
 * not found". The cost is a second round trip inside a request the buyer is
 * waiting on, and the risk is a lead created for an order that then fails —
 * which is a CRM row rather than a charge, and the provider's own model.
 *
 * **There is no idempotency here either.** The client offers no request key and
 * the provider deduplicates nothing, so a duplicate submit is caught before the
 * call by {@see \AsterMD\Storefront\Checkout\CheckoutAttempt}, exactly as it is
 * for the other provider. This adapter deliberately sends no idempotency key,
 * because sending one would imply a provider-side guarantee that does not
 * exist.
 *
 * **The campaign comes from the catalog, not from configuration.** A variant's
 * provider mapping carries `offer_id` and `product_id`, and for this provider
 * those are the campaign and the campaign-scoped product -- the same two opaque
 * slots the other provider fills with its own vocabulary. So a second provider
 * needed no new catalog field, which is the strongest evidence that `[14.1]`
 * capability 3 was drawn in the right place. It also means an order can fail to
 * name a campaign in a way a configured one could not, and
 * {@see CheckoutChampPayload::campaignFor()} is where that is decided.
 *
 * **What is recorded and what is not.** The refusal envelope is recorded
 * against the live sandbox: `{"result": "ERROR", "message": "<sentence>"}`, in
 * four variants. The success envelope is the provider's documented shape and
 * has not been exercised — no order has been placed through this adapter. Every
 * unverified assumption is arranged to fail as a *stated decline* rather than
 * as a charge nobody recorded; `docs/INTEGRATION-NOTES.md` carries the list.
 */
final class CheckoutChampAdapter implements PaymentAdapter
{
    public function __construct(
        private readonly CheckoutChampCredentials $credentials,
        private readonly CheckoutChampApiFactory $apiFactory,
        private readonly OperatorLog $log,
        private readonly string $salesUrl = '',
    ) {
    }

    /**
     * What this adapter can do, and the two things it deliberately will not
     * claim yet.
     *
     * **`supportsPromotions` is false.** The client exposes no discount-quote
     * endpoint, and `[13.14]`/`[14.4]` make that a UI decision rather than a
     * runtime surprise: the storefront hides the promo control entirely instead
     * of offering a box that can only ever reject a code.
     *
     * **`supportsOrderSearch` is false, although `orderQuery` exists.**
     * `[21.9a]`'s reverse sweep reports orders the provider took that no local
     * row records, and an adapter that mapped an unrecorded projection would
     * produce a figure that looks measured and is not. Declaring false produces
     * *no sweep*, which the sweep is explicitly built to distinguish from a
     * clean bill of health.
     *
     * **`supportsAuthorizeCapture` is false, and this is the interesting one.**
     * The authorize half is implemented and reachable — the provider offers
     * `POST /order/preauth/` and {@see self::place()} sends it. What is missing
     * is the settle half: the client exposes no capture method, and how a
     * pre-authorized order is converted into a billed one is not recorded.
     * Declaring true would let a deployment hold a buyer's funds with no proven
     * way to release them, and an authorization nobody can capture expires
     * silently on the acquirer's clock a few days later. So the capability
     * stays off until capture is recorded, `CheckoutService` refuses an
     * authorize order before the wire, and `config:validate` says so. Turning
     * it on is this flag and {@see self::capture()}, nothing else.
     */
    public function capabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities(
            providerCategory: 'checkout_champ',
            supportsPromotions: false,
            discountScope: AdapterCapabilities::SCOPE_ORDER_LEVEL,
            credentialStrategy: AdapterCapabilities::STRATEGY_ORDER_REFERENCE,
            collectionSurface: AdapterCapabilities::SURFACE_THEME_FIELDS,
            requiredConfigKeys: ['api_endpoint', 'api_username', 'api_password'],
            routingHintKeys: ['offer_id', 'product_id'],
            pciPosture: 'Reduced scope via stored-customer reuse: the card is collected by the storefront once, at checkout, and is never held afterwards — later charges name the customer the provider already holds. NOTE that this provider authenticates and takes every parameter in the QUERY STRING, including the card number and security code, so anything on the egress path that records request URLs (a forward proxy, an egress gateway, an APM agent, a TLS-inspecting appliance) records cardholder data and this deployment\'s provider password in clear text. Audit that path before going live; it is a larger obligation than the collection surface itself.',
            supportsOrderSearch: false,
            supportsAuthorizeCapture: false,
            requiredDeploymentKeys: [],
        );
    }

    public function place(OrderEnvelope $order, PaymentCredential $credential): PlacementOutcome
    {
        if ($order->chargeableLines() === []) {
            $this->log->error('payment.no_chargeable_lines', ['anchor' => $order->anchorSlug]);

            return PlacementOutcome::declined(null, CheckoutChampOutcome::GENERIC_DECLINE, 'no_chargeable_lines');
        }

        foreach ($order->unmappedLines() as $line) {
            $this->log->info('payment.line_not_sent', ['slug' => $line->slug, 'free' => $line->isFree()]);
        }

        // A credential this provider cannot charge against must not reach the
        // wire: an order body with no payment identification is not a partial
        // request, it is an order the provider may create and nobody watches.
        if (CheckoutChampPayload::paymentFor($credential) === []) {
            $this->log->error('payment.credential_unusable', [
                'kind' => $credential->kind,
                'anchor' => $order->anchorSlug,
            ]);

            return PlacementOutcome::declined(null, CheckoutChampOutcome::GENERIC_DECLINE, 'credential_unusable');
        }

        // The campaign is a line's own routing hint, so it can be missing or
        // contradictory in a way a configured value could not. Refused here
        // rather than sent empty, because the provider answers an order with no
        // campaign with "No products exist in the order" -- a message about the
        // cart, for a catalog fault, which is the worst possible place to debug
        // one.
        if (CheckoutChampPayload::campaignFor($order) === null) {
            $this->log->error('payment.campaign_unresolved', [
                'anchor' => $order->anchorSlug,
                'offers' => array_values(array_unique(array_map(
                    static fn ($line): ?string => $line->providerOffer,
                    $order->chargeableLines(),
                ))),
            ]);

            return PlacementOutcome::declined(null, CheckoutChampOutcome::GENERIC_DECLINE, 'campaign_unresolved');
        }

        $sessionId = $this->openSession($order);

        if ($sessionId === null) {
            return PlacementOutcome::declined(null, CheckoutChampOutcome::GENERIC_DECLINE, 'lead_not_created');
        }

        $body = CheckoutChampPayload::forOrder($order, $credential, $sessionId);
        $authorize = $order->settlement->isAuthorize();

        try {
            $api = $this->apiFactory->create($this->credentials);
            $envelope = ($authorize ? $api->preauth($body) : $api->importOrder($body))->getInArray()['response'];
        } catch (\Throwable $e) {
            // The buyer's message is ours, never the exception's: an exception
            // string can carry anything, and for a client that quotes the URL
            // it failed on, that includes the card and the account password.
            $this->log->error('payment.place_threw', ['reason' => CardScrubber::scrub($e->getMessage())]);

            return PlacementOutcome::declined(null, CheckoutChampOutcome::GENERIC_DECLINE, 'exception');
        }

        $outcome = CheckoutChampOutcome::from(
            is_array($envelope) ? $envelope : [],
            $order->totalCents,
            $order->settlement,
        );

        if (!$outcome->isPlaced()) {
            $this->log->warning('payment.not_placed', [
                'state' => $outcome->state,
                'reference' => $outcome->reference,
                'raw_status' => $outcome->rawStatus,
                'response' => CardScrubber::scrub($envelope),
            ]);
        }

        $discrepancy = $outcome->chargeDiscrepancy;
        if ($discrepancy !== null) {
            $this->log->error('payment.total_mismatch', [
                'reference' => $outcome->reference,
                'expected_cents' => $discrepancy->expectedCents,
                'charged_cents' => $discrepancy->chargedCents,
                'difference_cents' => $discrepancy->differenceCents(),
                'provider_discount_cents' => $discrepancy->providerDiscountCents,
                'anchor' => $order->anchorSlug,
            ]);
        }

        return $outcome;
    }

    /**
     * The first of the two calls: create the customer, and read back the
     * session an order is billed against.
     *
     * A failure here is logged and answered as null rather than thrown, so the
     * caller turns it into a decline like any other — the buyer sees a reason
     * and keeps their cart, and no card has been presented to anything.
     */
    private function openSession(OrderEnvelope $order): ?string
    {
        try {
            $envelope = $this->apiFactory->create($this->credentials)
                ->importLeads(CheckoutChampPayload::forLead($order, $this->salesUrl))
                ->getInArray()['response'];
        } catch (\Throwable $e) {
            $this->log->error('payment.lead_threw', ['reason' => CardScrubber::scrub($e->getMessage())]);

            return null;
        }

        $sessionId = CheckoutChampOutcome::sessionFrom(is_array($envelope) ? $envelope : []);

        if ($sessionId === null) {
            $this->log->error('payment.lead_not_created', [
                'anchor' => $order->anchorSlug,
                'response' => CardScrubber::scrub($envelope),
            ]);
        }

        return $sessionId;
    }

    /**
     * Not offered: this provider has no promotion endpoint in the client, and
     * `supportsPromotions` is false so the storefront never renders the control
     * that would call this (`[13.18]`, `[14.4]`).
     */
    public function quotePromotion(OrderEnvelope $order, string $code): PromotionQuote
    {
        return PromotionQuote::rejected($code, 'unsupported');
    }

    /**
     * Declared unsupported, and answered as such rather than as an empty
     * window.
     *
     * `orderQuery` exists and could be called; what does not exist is a
     * recording of what its projection contains, and `[21.9a]`'s sweep turns
     * "this provider holds an order we have no row for" into an alert about
     * money. A mapping guessed from documentation would make that alert fire on
     * fields that may not be there — or, worse, stay silent because a field it
     * read was always absent, which is the clean-bill-of-health failure the
     * sweep exists to end.
     */
    public function searchOrders(OrderSearch $search): OrderSearchResult
    {
        return OrderSearchResult::unsupported();
    }

    /**
     * Not implemented, and declared so.
     *
     * The provider pre-authorizes through `POST /order/preauth/` — which
     * {@see self::place()} sends — but the call that settles a pre-authorized
     * order is not exposed by the client and is not recorded. An implementation
     * written from documentation would be a guess about the one operation that
     * moves money on an order a buyer has already left.
     *
     * `supportsAuthorizeCapture` is false because of this, so nothing reaches
     * here in normal operation: `CheckoutService` refuses an authorize order
     * before the wire, and `config:validate` reports the combination. This
     * answers `unsupported` rather than `failed` for the reason
     * {@see \AsterMD\Storefront\Payment\NullPaymentAdapter::capture()} does — a
     * failed capture invites a retry that could never succeed.
     */
    public function capture(string $reference): CaptureOutcome
    {
        $this->log->error('payment.capture_unimplemented', ['reference' => $reference]);

        return CaptureOutcome::unsupported();
    }

    /**
     * Whether the credentials reach the provider.
     *
     * `campaignQuery` is the read chosen for it because it is the closest thing
     * this provider has to the other's campaign-items call: it proves the host
     * answers, that the login and password are accepted, and that the account
     * has campaigns — which is what an operator running a smoke test wants to
     * know before pointing a storefront at it.
     *
     * @return array{ok: bool, detail: string}
     */
    public function ping(): array
    {
        try {
            $envelope = $this->apiFactory->create($this->credentials)->campaignQuery([])->getInArray()['response'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => sprintf('checkout_champ %s — %s', $this->credentials->host, $e->getMessage())];
        }

        if (!is_array($envelope) || isset($envelope['curlError'])) {
            return ['ok' => false, 'detail' => sprintf('checkout_champ %s — unreachable', $this->credentials->host)];
        }

        if (($envelope['result'] ?? null) !== 'SUCCESS') {
            $message = $envelope['message'] ?? null;

            return [
                'ok' => false,
                'detail' => sprintf(
                    'checkout_champ %s — rejected: %s',
                    $this->credentials->host,
                    is_string($message) ? $message : 'no reason given',
                ),
            ];
        }

        $campaigns = is_array($envelope['message']['data'] ?? null) ? $envelope['message']['data'] : [];

        return [
            'ok' => true,
            'detail' => sprintf(
                'checkout_champ %s — login %s, %d campaign(s) visible, order campaign taken %s',
                $this->credentials->host,
                $this->credentials->loginId,
                count($campaigns),
                'from the catalog',
            ),
        ];
    }
}
