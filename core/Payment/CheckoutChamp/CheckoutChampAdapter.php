<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment\CheckoutChamp;

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
 * `POST /leads/import/` creates the customer *and a PARTIAL order*, answering
 * the `orderId` everything afterwards is keyed on; `POST /order/import/` bills
 * it. Calling the second alone answers "Customer not found". The cost is a
 * second round trip inside a request the buyer is waiting on, and the risk is a
 * partial order for a purchase that then fails -- which is an unbilled row
 * rather than a charge, and the provider's own model.
 *
 * One consequence is worth having: **the reference exists before the card is
 * presented**, so a refused placement still carries one (`[13.26]`) even though
 * the refusal envelope has no order id in it at all.
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
        private readonly CheckoutChampAuthorizeMode $authorizeMode = CheckoutChampAuthorizeMode::Qa,
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
     * **`supportsAuthorizeCapture` is true**, and this provider has *two*
     * mechanisms for it, both recorded. Which one runs is
     * {@see CheckoutChampAuthorizeMode}, a deployment-wide setting, and the
     * difference is how much money is actually reserved -- see that enum. The
     * capability is the same either way, so nothing above the boundary has to
     * know which is configured.
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
            supportsAuthorizeCapture: true,
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

        $reference = $this->openOrder($order);

        if ($reference === null) {
            return PlacementOutcome::declined(null, CheckoutChampOutcome::GENERIC_DECLINE, 'lead_not_created');
        }

        // Which call authorizes depends on the mechanism, and only one of the
        // three combinations is a plain charge:
        //
        //   capture            -> /order/import/                (bills it)
        //   authorize, preauth -> /order/preauth/               (validates the card)
        //   authorize, QA      -> /order/import/ + forceQA: 1    (holds the amount)
        //
        // The QA mechanism authorizes through the *billing* endpoint, which is
        // why the flag rather than the endpoint carries the distinction there.
        $authorize = $order->settlement->isAuthorize();
        $holdForReview = $authorize && $this->authorizeMode === CheckoutChampAuthorizeMode::Qa;
        $legacyPreauth = $authorize && !$holdForReview;

        $body = CheckoutChampPayload::forOrder($order, $credential, $reference, $holdForReview);

        try {
            $api = $this->apiFactory->create($this->credentials);
            $envelope = ($legacyPreauth ? $api->preauth($body) : $api->importOrder($body))->getInArray()['response'];
        } catch (\Throwable $e) {
            // The buyer's message is ours, never the exception's: an exception
            // string can carry anything, and for a client that quotes the URL
            // it failed on, that includes the card and the account password.
            $this->log->error('payment.place_threw', ['reason' => CardScrubber::scrub($e->getMessage())]);

            return PlacementOutcome::declined(null, CheckoutChampOutcome::GENERIC_DECLINE, 'exception');
        }

        $outcome = CheckoutChampOutcome::from(
            is_array($envelope) ? $envelope : [],
            $reference,
            $order->totalCents,
            $order->settlement,
            $holdForReview,
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
     * The first of the two calls: create the customer and the partial order,
     * and read back the reference everything afterwards is keyed on.
     *
     * A failure here is logged and answered as null rather than thrown, so the
     * caller turns it into a decline like any other — the buyer sees a reason
     * and keeps their cart, and no card has been presented to anything.
     */
    private function openOrder(OrderEnvelope $order): ?string
    {
        try {
            $envelope = $this->apiFactory->create($this->credentials)
                ->importLeads(CheckoutChampPayload::forLead($order, $this->salesUrl))
                ->getInArray()['response'];
        } catch (\Throwable $e) {
            $this->log->error('payment.lead_threw', ['reason' => CardScrubber::scrub($e->getMessage())]);

            return null;
        }

        $reference = CheckoutChampOutcome::referenceFrom(is_array($envelope) ? $envelope : []);

        if ($reference === null) {
            $this->log->error('payment.lead_not_created', [
                'anchor' => $order->anchorSlug,
                'response' => CardScrubber::scrub($envelope),
            ]);
        }

        return $reference;
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
     * Settle an order this adapter authorized.
     *
     * **Two mechanisms, two settle calls.** Under the QA mechanism the amount is
     * already reserved against the order, so settling is `/order/qa/` with a
     * verb and nothing else ({@see self::approveQa()}). Everything below is the
     * older mechanism, where the order holds nothing and the lines have to be
     * resent.
     *
     * **The lines are the whole difficulty, and they cannot be fetched.** A
     * pre-authorized order carries an *empty* `items` array until this call
     * supplies them, so `orderId` alone is answered "No products exist in the
     * order" and the order stays PARTIAL with the funds still held -- a silent
     * failure, since the call reports an error but the money stays reserved.
     * Re-reading the order first would return that same empty projection, which
     * is why {@see CaptureRequest} carries the lines from the storefront's own
     * record.
     *
     * The campaign comes from those lines, exactly as it does at placement.
     *
     * **Nothing throws.** This runs with no buyer in front of it, against money
     * already reserved on somebody's card, so an unrecorded result is a hold
     * that expires a few days later with nobody the wiser.
     */
    public function capture(CaptureRequest $request): CaptureOutcome
    {
        $reference = trim($request->reference);

        if ($reference === '') {
            $this->log->error('payment.capture_without_reference', []);

            return CaptureOutcome::failed(null, 'missing_reference');
        }

        if ($this->authorizeMode === CheckoutChampAuthorizeMode::Qa) {
            return $this->approveQa($reference);
        }

        $lines = $request->chargeableLines();
        $campaignId = CheckoutChampPayload::campaignOf($lines);

        // Refused before the wire, because the provider's own answer for this
        // is "No products exist in the order" -- which describes a storefront
        // bookkeeping gap as a cart problem, against an order whose funds are
        // held.
        if ($lines === [] || $campaignId === null) {
            $this->log->error('payment.capture_lines_unusable', [
                'reference' => $reference,
                'lines' => count($request->lines),
                'chargeable' => count($lines),
            ]);

            return CaptureOutcome::failed($reference, 'lines_unusable');
        }

        try {
            $envelope = $this->apiFactory->create($this->credentials)
                ->importOrder(CheckoutChampPayload::forCapture($reference, $lines, $campaignId))
                ->getInArray()['response'];
        } catch (\Throwable $e) {
            $this->log->error('payment.capture_threw', [
                'reference' => $reference,
                'exception' => $e::class,
                'status' => $e->getCode(),
            ]);

            return CaptureOutcome::failed($reference, 'exception');
        }

        if (!is_array($envelope) || isset($envelope['curlError'])) {
            $this->log->error('payment.capture_unreachable', ['reference' => $reference]);

            return CaptureOutcome::failed($reference, 'transport_error');
        }

        if (!CheckoutChampOutcome::capturedFrom($envelope)) {
            $reason = self::captureFailureReason($envelope);

            // Error level, not warning: money is reserved on somebody's card and
            // the hold expires on the acquirer's clock, so an unsettled capture
            // has a deadline nothing here can extend.
            $this->log->error('payment.capture_refused', ['reference' => $reference, 'reason' => $reason]);

            return CaptureOutcome::failed($reference, $reason);
        }

        $this->log->info('payment.captured', ['reference' => $reference]);

        return CaptureOutcome::captured($reference);
    }

    /**
     * Settle an order the QA mechanism is holding: `POST /order/qa/`.
     *
     * No lines, because there is nothing to restate -- `forceQA` already put the
     * order in PENDING review with the full amount reserved against it, and this
     * call releases that reservation. Recorded: `action: "APPROVE"` answers
     * `SUCCESS` with the string `"Order QA Approved"`, and a read afterwards
     * shows `orderStatus: "COMPLETE"`, `reviewStatus: "APPROVED"`.
     *
     * The provider's other verb is `DECLINE`, which would void the hold. It is
     * deliberately not offered: this storefront has no decline path for an
     * authorized order, and a method that could throw a buyer's reserved funds
     * away needs a caller that has decided to, not a flag on a capture.
     */
    private function approveQa(string $reference): CaptureOutcome
    {
        try {
            $envelope = $this->apiFactory->create($this->credentials)
                ->qa(CheckoutChampPayload::forQaApproval($reference))
                ->getInArray()['response'];
        } catch (\Throwable $e) {
            $this->log->error('payment.capture_threw', [
                'reference' => $reference,
                'exception' => $e::class,
                'status' => $e->getCode(),
            ]);

            return CaptureOutcome::failed($reference, 'exception');
        }

        if (!is_array($envelope) || isset($envelope['curlError'])) {
            $this->log->error('payment.capture_unreachable', ['reference' => $reference]);

            return CaptureOutcome::failed($reference, 'transport_error');
        }

        if (!CheckoutChampOutcome::capturedFrom($envelope)) {
            $reason = self::captureFailureReason($envelope);

            // Error level for the reason the other settle path uses it: money is
            // reserved on somebody's card and the hold expires on the acquirer's
            // clock, so an unsettled capture has a deadline nothing here can
            // extend.
            $this->log->error('payment.capture_refused', ['reference' => $reference, 'reason' => $reason]);

            return CaptureOutcome::failed($reference, $reason);
        }

        $this->log->info('payment.captured', ['reference' => $reference, 'mechanism' => 'qa']);

        return CaptureOutcome::captured($reference);
    }

    /**
     * The provider's own reason for a refused capture, scrubbed.
     *
     * The field-map form is flattened rather than dropped: on this path it is
     * the only useful thing about the failure, and there is no buyer to protect
     * from it.
     *
     * @param array<string, mixed> $envelope
     */
    private static function captureFailureReason(array $envelope): string
    {
        $message = $envelope['message'] ?? null;

        if (is_string($message) && trim($message) !== '') {
            return (string) CardScrubber::scrub(trim($message));
        }

        if (is_array($message) && $message !== []) {
            $parts = [];
            foreach ($message as $field => $problem) {
                $parts[] = sprintf('%s %s', (string) $field, is_scalar($problem) ? (string) $problem : 'is invalid');
            }

            return (string) CardScrubber::scrub(implode('; ', $parts));
        }

        return 'rejected';
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
                'checkout_champ %s — login %s, %d campaign(s) visible, order campaign taken %s, authorize mechanism "%s" (%s)',
                $this->credentials->host,
                $this->credentials->loginId,
                count($campaigns),
                'from the catalog',
                $this->authorizeMode->value,
                $this->authorizeMode->holdsTheOrderAmount() ? 'holds the order amount' : 'does not hold the order amount',
            ),
        ];
    }
}
