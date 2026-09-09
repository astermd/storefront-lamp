<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment\Vrio;

use AsterMD\Storefront\Payment\AdapterCapabilities;
use AsterMD\Storefront\Payment\OrderEnvelope;
use AsterMD\Storefront\Payment\OrderSearch;
use AsterMD\Storefront\Payment\OrderSearchResult;
use AsterMD\Storefront\Payment\PaymentAdapter;
use AsterMD\Storefront\Payment\PaymentCredential;
use AsterMD\Storefront\Payment\PlacementOutcome;
use AsterMD\Storefront\Payment\PromotionQuote;
use AsterMD\Storefront\Payment\ProviderOrder;
use AsterMD\Storefront\Support\CardScrubber;
use AsterMD\Storefront\Support\OperatorLog;

/**
 * The Vrio adapter.
 *
 * Nothing above this class knows the provider's name, its field names, its
 * status vocabulary or its 20 tracking slots. Everything provider-specific is
 * here or in one of the collaborators beside it (`[14.2]`, `[14.3]`).
 *
 * The credential strategy is reference-order (`[15.3]`, `[15.9]`), verified on
 * the wire rather than inferred: an order was charged against a
 * `customer_id` + `customer_card_id` pair returned by an earlier placement,
 * with no card number, security code or expiry in the request. That is
 * `[15.11]`'s "determined at adapter-implementation time by checking the
 * provider's capability", and it is now determined -- so `[15.10]`'s
 * preference applies and the card is not held at all. The posture the
 * configuration validator reports changes with it (`[15.7]`, `[15.15]`).
 *
 * The handle is a **pair**, and the provider enforces the pairing itself: a
 * card id whose customer does not own it is refused. Card ids are sequential,
 * so that enforcement is the only thing standing between a guessed id and a
 * charge, which is why the pair is minted here from the provider's own
 * response and never accepted from a request.
 */
final class VrioAdapter implements PaymentAdapter
{
    /** The provider's own timestamp format, for both ends of a search window. Recorded on the wire. */
    private const string SEARCH_DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly VrioCredentials $credentials,
        private readonly VrioApiFactory $apiFactory,
        private readonly OperatorLog $log,
        private readonly int $shippingProfileId,
    ) {
    }

    public function capabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities(
            providerCategory: 'vrio',
            supportsPromotions: true,
            discountScope: AdapterCapabilities::SCOPE_LINE_ITEM,
            credentialStrategy: AdapterCapabilities::STRATEGY_ORDER_REFERENCE,
            collectionSurface: AdapterCapabilities::SURFACE_THEME_FIELDS,
            requiredConfigKeys: ['api_endpoint', 'api_key', 'campaign_id', 'connection_id'],
            routingHintKeys: ['campaign_id', 'connection_id'],
            pciPosture: 'Reduced scope via reference-order reuse: the card is collected by the storefront once, at checkout, and is never held afterwards — later charges on the same journey name the instrument the provider already holds in its own vault. The collection surface is still the storefront\'s own, so the checkout request itself remains in scope; nothing beyond it is.',
            supportsOrderSearch: true,
        );
    }

    /**
     * `[21.9a]`: the orders this provider holds inside a window.
     *
     * **Both filters are applied server-side, and that was verified rather
     * than assumed.** Negative controls recorded on 2026-08-25: a 1990 window
     * returns 0 orders and a nonexistent campaign returns 0, while the real
     * campaign over three days returns exactly the known orders out of the
     * 143 the account took in that span. Reading the account and filtering
     * here would page every campaign on a shared merchant to find our own.
     *
     * **The campaign is checked again on the way back.** The filter works
     * today; the cost of it silently becoming inert is that every other
     * campaign's orders are reported as charges this storefront lost, on
     * accounts it does not own. A second comparison against the routing hint
     * we already hold makes that failure mode impossible instead of unlikely.
     *
     * **Nothing here throws.** A search runs offline, and `[20.1]` makes a
     * provider outage a logged non-event -- so a transport failure, a rejected
     * request and a body that never decoded are all a failed result. A failed
     * result is not an empty one: an empty window means no orders were lost,
     * and reading an outage that way is the silent failure this sweep exists
     * to end.
     *
     * **No provider text and no payload reaches the log**, only the reason
     * code and the counts. The wire log already carries verbatim traffic for a
     * deployment that turns it on; this boundary does not add a second copy.
     */
    public function searchOrders(OrderSearch $search): OrderSearchResult
    {
        try {
            $envelope = $this->apiFactory->create($this->credentials)->searchOrder([
                'campaign_id' => $this->credentials->campaignId,
                'date_created_from' => $search->from->format(self::SEARCH_DATE_FORMAT),
                'date_created_to' => $search->to->format(self::SEARCH_DATE_FORMAT),
                'limit' => $search->limit,
            ])->getInArray()['response'];
        } catch (\Throwable $e) {
            $this->log->warning('payment.order_search_threw', [
                'exception' => $e::class,
                'status' => $e->getCode(),
            ]);

            return OrderSearchResult::failed('exception');
        }

        if (!is_array($envelope) || isset($envelope['curlError'])) {
            $this->log->warning('payment.order_search_unreachable', ['host' => $this->credentials->host]);

            return OrderSearchResult::failed('transport_error');
        }

        if (($envelope['success'] ?? false) !== true) {
            $this->log->warning('payment.order_search_rejected', ['campaign' => $this->credentials->campaignId]);

            return OrderSearchResult::failed('rejected');
        }

        // A body that is not JSON at all -- an HTML error page from a proxy --
        // decodes to null, and the client derives `success` from the absence
        // of an error key, so it arrives here as a success with no data. The
        // same trap {@see VrioOutcome} names for a placement, and the same
        // answer: require the shape, do not infer it from the flag.
        $orders = $envelope['data']['orders'] ?? null;
        if (!is_array($orders)) {
            $this->log->warning('payment.order_search_unreadable', ['campaign' => $this->credentials->campaignId]);

            return OrderSearchResult::failed('unreadable');
        }

        $mapped = [];
        $foreign = 0;

        foreach ($orders as $row) {
            if (!is_array($row)) {
                continue;
            }

            if ((int) ($row['campaign_id'] ?? 0) !== $this->credentials->campaignId) {
                ++$foreign;
                continue;
            }

            $order = self::orderFrom($row);
            if ($order !== null) {
                $mapped[] = $order;
            }
        }

        if ($foreign > 0) {
            $this->log->warning('payment.order_search_foreign_campaign', [
                'campaign' => $this->credentials->campaignId,
                'dropped' => $foreign,
            ]);
        }

        // The provider's total counts the window it was asked for, so anything
        // dropped above has to come off it -- otherwise the sweep would report
        // itself truncated by exactly the rows it deliberately discarded.
        $total = $envelope['data']['total'] ?? null;

        return OrderSearchResult::of($mapped, is_int($total) ? max(0, $total - $foreign) : null);
    }

    public function place(OrderEnvelope $order, PaymentCredential $credential): PlacementOutcome
    {
        if ($order->chargeableLines() === []) {
            $this->log->error('payment.no_chargeable_lines', ['anchor' => $order->anchorSlug]);

            return PlacementOutcome::declined(null, VrioOutcome::GENERIC_DECLINE, 'no_chargeable_lines');
        }

        // A line the provider has never heard of cannot be charged for, and
        // the buyer is not told about it because it is free and already on
        // their summary at zero. The operator is (`[27.8]`'s no-silent-
        // truncation principle, applied to the charge).
        foreach ($order->unmappedLines() as $line) {
            $this->log->info('payment.line_not_sent', ['slug' => $line->slug, 'free' => $line->isFree()]);
        }

        // A credential this provider cannot charge against must not reach the
        // wire. An empty payment block is not a partial request: the provider
        // accepts the order and creates it unpaid, which is a container nobody
        // is watching for. Refusing here costs nothing -- the call was never
        // made, so the idempotency key goes back and the buyer keeps a retry.
        if (VrioPayload::paymentFor($credential) === []) {
            $this->log->error('payment.credential_unusable', [
                'kind' => $credential->kind,
                'anchor' => $order->anchorSlug,
            ]);

            return PlacementOutcome::declined(null, VrioOutcome::GENERIC_DECLINE, 'credential_unusable');
        }

        $body = VrioPayload::forOrder($order, $credential, $this->credentials, $this->shippingProfileId);

        try {
            $envelope = $this->apiFactory->create($this->credentials)->addOrder($body)->getInArray()['response'];
        } catch (\Throwable $e) {
            // A thrown error is treated the same as a decline (`[13.31]`); the
            // buyer's message is ours, never the exception's, because an
            // exception string can carry anything -- including, for a client
            // that quotes the request it failed on, the card.
            $this->log->error('payment.place_threw', ['reason' => CardScrubber::scrub($e->getMessage())]);

            return PlacementOutcome::declined(null, VrioOutcome::GENERIC_DECLINE, 'exception');
        }

        $outcome = VrioOutcome::from(is_array($envelope) ? $envelope : [], $order->totalCents);

        // Every non-success response is logged in full for operator review; a
        // successful one is not (`[13.29]`). These logs echo the submitted
        // order and therefore contain personal data (`[20.6]`) -- they go
        // through OperatorLog's redaction, and the response is scrubbed for
        // card numbers first: `gateway_request_text` is the provider's echo of
        // what it handed the acquiring gateway, and that request carried the
        // PAN and the CVV inside one opaque string that no key-based redaction
        // can see into.
        if (!$outcome->isPlaced()) {
            $this->log->warning('payment.not_placed', [
                'state' => $outcome->state,
                'reference' => $outcome->reference,
                'raw_status' => $outcome->rawStatus,
                'response' => CardScrubber::scrub($envelope),
            ]);
        }

        // A charge that does not match what the buyer was shown is an error
        // even though the placement stands: the money has moved, and the only
        // way anyone learns the two figures disagree is this line
        // ({@see PlacementOutcome::isPlaced()} for why it is not a decline).
        $discrepancy = $outcome->chargeDiscrepancy;
        if ($discrepancy !== null) {
            $this->log->error('payment.total_mismatch', [
                'reference' => $outcome->reference,
                'expected_cents' => $discrepancy->expectedCents,
                'charged_cents' => $discrepancy->chargedCents,
                'difference_cents' => $discrepancy->differenceCents(),
                'provider_discount_cents' => $discrepancy->providerDiscountCents,
                'promotion_code' => $order->promotionCode,
                'anchor' => $order->anchorSlug,
            ]);
        }

        return $outcome;
    }

    public function quotePromotion(OrderEnvelope $order, string $code): PromotionQuote
    {
        $code = trim($code);
        if ($code === '') {
            return PromotionQuote::rejected($code, 'empty');
        }

        // The quote and the charge are built by one collaborator, so the lines
        // the buyer is quoted a discount on are the lines the provider is
        // asked to discount ({@see VrioOffers}).
        $lines = VrioOffers::forQuote($order, $code);

        if ($lines === []) {
            return PromotionQuote::rejected($code, 'no_offers');
        }

        try {
            $response = $this->apiFactory->create($this->credentials)
                ->calculateDiscount($lines)
                ->getInArray()['response'];
        } catch (\Throwable $e) {
            // Calculation failures degrade to "invalid code" / "zero discount"
            // rather than erroring (`[13.14]`).
            $this->log->warning('payment.promotion_threw', ['reason' => $e->getMessage()]);

            return PromotionQuote::rejected($code, 'unavailable');
        }

        return self::quoteFrom($code, $response);
    }

    /**
     * Whether the credentials this adapter was built with reach the provider.
     *
     * `getCampaignItems` is the read chosen for it because it is the same call
     * `[27.9]`'s catalog validation makes: an operator running the smoke test
     * learns not only that the host answers but that the campaign behind the
     * credentials has items in it.
     *
     * @return array{ok: bool, detail: string}
     */
    public function ping(): array
    {
        try {
            $response = $this->apiFactory->create($this->credentials)
                ->getCampaignItems((string) $this->credentials->campaignId)
                ->getInArray()['response'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => 'Provider client error: ' . $e->getMessage()];
        }

        if (!is_array($response) || isset($response['curlError'])) {
            return ['ok' => false, 'detail' => 'Could not reach ' . $this->credentials->host];
        }

        if (($response['success'] ?? false) !== true) {
            return ['ok' => false, 'detail' => (string) ($response['message'] ?? 'Provider rejected the request.')];
        }

        $items = $response['data']['campaign_items'] ?? [];

        return [
            'ok' => true,
            'detail' => sprintf(
                'vrio %s — campaign %d, %d items, connection %d',
                $this->credentials->host,
                $this->credentials->campaignId,
                is_array($items) ? count($items) : 0,
                $this->credentials->connectionId,
            ),
        ];
    }

    /**
     * One row of the order-search projection → a provider-neutral order.
     *
     * **`status_type_id === null` means the card was never charged.**
     * `[14.10]` says the opposite -- no status counts as placed -- and the
     * wire disagrees: a null status is an order container with `date_ordered`,
     * `date_authorized` and `date_capture` all null, and it is 55 of the 143
     * orders in the recorded window. {@see VrioOutcome} already refuses to
     * treat one as a placement, and the sweep has to make the same call or it
     * would report an uncharged container as a lost charge.
     *
     * **Both flags fail toward reporting, not toward silence.** A missing or
     * unrecognised `is_test` is read as a live order and a missing
     * `status_type_id` key as charged, because the cost of the two mistakes is
     * not symmetric: over-reporting costs an operator one lookup in the
     * provider's dashboard, under-reporting loses a charge nobody ever hears
     * about again.
     *
     * **The status is not classified further.** {@see VrioOutcome} knows which
     * statuses are terminal at placement time; this projection is not a
     * placement response, and an unmatched order the storefront has no record
     * of cannot be shown from these fields to have been voided or refunded
     * afterwards. So a charged order is reported whatever its status, and the
     * raw value travels along for the operator to read.
     *
     * **The only money in the projection is the discount**, and it arrives as
     * a decimal string. It is converted here, at the boundary, so nothing
     * above this class ever sees provider money encoding.
     *
     * @param array<string, mixed> $row
     */
    private static function orderFrom(array $row): ?ProviderOrder
    {
        $reference = $row['order_id'] ?? null;
        $reference = is_int($reference) ? (string) $reference : (is_string($reference) ? trim($reference) : '');

        if ($reference === '') {
            return null;
        }

        $status = $row['status_type_id'] ?? null;
        $placedAt = $row['date_created'] ?? null;
        $discount = $row['order_discount'] ?? null;

        return new ProviderOrder(
            reference: $reference,
            placedAt: is_string($placedAt) && $placedAt !== '' ? $placedAt : null,
            isTest: ($row['is_test'] ?? null) === true,
            isCharged: !array_key_exists('status_type_id', $row) || $status !== null,
            rawStatus: is_scalar($status) ? (string) $status : null,
            discountCents: is_numeric($discount) ? (int) round(((float) $discount) * 100) : 0,
        );
    }

    /**
     * One calculation response → a quote.
     *
     * `[13.8]` describes applying a code as two phases, validate then
     * calculate, and this provider needs only the second: each line comes back
     * with `discount_details.discount_code_valid` as true, false, or null
     * (null meaning no code was submitted) beside its own amount. A separate
     * validate pass would be one call per line for an answer already in hand.
     *
     * `[13.8]`'s "accepted if it is valid for at least one line" is read off
     * those per-line flags, and the order's discount is the **sum** of the
     * per-line amounts, because the response carries no order-level total.
     *
     * The provider owns the arithmetic (`[14.6b]`) -- two authorities on a
     * discount produce a total that disagrees with the charge.
     */
    private static function quoteFrom(string $code, mixed $response): PromotionQuote
    {
        if (!is_array($response) || isset($response['curlError']) || ($response['success'] ?? false) !== true) {
            return PromotionQuote::rejected($code, 'unavailable');
        }

        $offers = $response['data']['offers'] ?? null;
        if (!is_array($offers) || $offers === []) {
            return PromotionQuote::rejected($code, 'invalid');
        }

        $validSomewhere = false;
        $cents = 0;

        foreach ($offers as $offer) {
            if (!is_array($offer)) {
                continue;
            }

            if (($offer['discount_details']['discount_code_valid'] ?? null) === true) {
                $validSomewhere = true;
            }

            $amount = $offer['offer_total_discount'] ?? null;
            if (is_numeric($amount)) {
                $cents += (int) round(((float) $amount) * 100);
            }
        }

        if (!$validSomewhere) {
            return PromotionQuote::rejected($code, 'invalid');
        }

        return PromotionQuote::accepted($code, $cents);
    }
}
