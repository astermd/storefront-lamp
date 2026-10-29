<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment\Stripe;

use AsterMD\Storefront\Emr\CouponGateway;
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
use AsterMD\Storefront\Payment\ProviderOrder;
use AsterMD\Storefront\Support\CardScrubber;
use AsterMD\Storefront\Support\OperatorLog;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\StripeClient;

/**
 * Stripe, through PaymentIntents this storefront creates and confirms itself.
 *
 * **The card never reaches this process.** Stripe's own fields collect it on
 * the page and mint a confirmation token (`[15.1]`, `[15.4c]`); the checkout
 * posts that token, and this confirms a PaymentIntent with it. What survives
 * to pay for an upsell is the customer and card Stripe saved
 * ({@see StripeOutcome}), never the card.
 *
 * **The EMR reads every payment this creates.** The storefront tells it about
 * an order by the PaymentIntent's id, and the EMR builds the treatment from
 * the PaymentIntent itself -- so the line items, the metadata and the saved
 * card are written for that reader ({@see StripePayload}).
 *
 * **One customer per email**, found before it is created: Stripe's email
 * filter on the customer list is exact and immediately consistent, and the
 * EMR's own checkout uses the same rule, so a buyer who pays through either
 * lands on the same customer and the same saved cards.
 *
 * **Idempotent at Stripe as well as here.** The storefront's key stops a
 * duplicate submit before it is sent (`[13.37]`); Stripe's stops a retried
 * request from creating a second payment. The Stripe key is the storefront's
 * key and the token together, so a retry of one attempt replays its answer and
 * a second attempt with another card is a new payment.
 *
 * **Discount codes are the EMR's** ({@see CouponGateway}): a PaymentIntent has
 * no notion of one, so the EMR prices the code and the order names the coupon
 * by its id. The code is priced once more just before the payment is created
 * ({@see self::repriceCoupon()}), because nothing after this point re-checks it.
 *
 * **Capture is the EMR's too.** A held payment is captured when a doctor
 * completes the case the EMR opened for it, cancelled if the case is
 * rejected, and replaced if the hold expires first -- so this adapter holds
 * funds when asked ({@see StripePayload::settlementFor()}) and refuses to
 * capture them itself ({@see self::capture()}).
 */
final class StripeAdapter implements PaymentAdapter
{
    /** The EMR settles a held payment; capturing it here would charge before the doctor decides. */
    private const string SETTLED_BY_EMR = 'settled_by_emr';

    /** How many PaymentIntents one page of an order search asks for: Stripe's maximum. */
    private const int SEARCH_PAGE_SIZE = 100;

    /** The discount code changed between being applied and being paid for. */
    private const string PROMOTION_CHANGED = 'Your discount code no longer applies as it did, so it has been removed. Please review your total and try again.';

    /** Stripe.js. Stripe requires it be loaded from its own domain, never bundled or self-hosted. */
    private const string STRIPE_JS = 'https://js.stripe.com/v3/';

    public function __construct(
        private readonly StripeCredentials $credentials,
        private readonly StripeApiFactory $apiFactory,
        private readonly OperatorLog $log,
        private readonly CouponGateway $coupons,
        private readonly string $channelId,
        private readonly string $returnUrl,
    ) {
    }

    public function capabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities(
            providerCategory: 'stripe',
            supportsPromotions: $this->coupons->available(),
            discountScope: AdapterCapabilities::SCOPE_ORDER_LEVEL,
            credentialStrategy: AdapterCapabilities::STRATEGY_TOKENIZATION,
            collectionSurface: AdapterCapabilities::SURFACE_PROVIDER_HOSTED,
            requiredConfigKeys: ['api_key', 'publishable_key'],
            routingHintKeys: [],
            pciPosture: 'Tokenized: the card is entered only in Stripe-hosted fields on the checkout page and reaches this storefront as a one-time token, never as card data. Later charges on the same journey name the customer and card Stripe saved. The checkout page hosts those fields, so the page itself stays in scope; nothing on the server handles a card.',
            supportsOrderSearch: true,
            supportsAuthorizeCapture: true,
            // What the page needs to mount Stripe's fields: the publishable
            // key, and Stripe's own script, which must be loaded from Stripe.
            clientConfig: [
                'publishable_key' => $this->credentials->publishableKey,
                'script' => self::STRIPE_JS,
            ],
            supportsClientAction: true,
        );
    }

    public function place(OrderEnvelope $order, PaymentCredential $credential): PlacementOutcome
    {
        if ($order->chargeableLines() === []) {
            $this->log->error('payment.no_chargeable_lines', ['anchor' => $order->anchorSlug]);

            return PlacementOutcome::declined(null, StripeOutcome::GENERIC_DECLINE, 'no_chargeable_lines');
        }

        $payment = StripePayload::paymentFor($credential);
        if ($payment === []) {
            $this->log->error('payment.credential_unusable', ['kind' => $credential->kind, 'anchor' => $order->anchorSlug]);

            return PlacementOutcome::declined(null, StripeOutcome::GENERIC_DECLINE, 'credential_unusable');
        }

        // Refused before anything is sent: an order the EMR cannot map back to
        // its own products is a payment with no treatment behind it.
        $lineItems = StripePayload::lineItemsMetadata($order);
        if ($lineItems === null) {
            $this->log->error('payment.line_unmapped', ['anchor' => $order->anchorSlug]);

            return PlacementOutcome::declined(null, StripeOutcome::GENERIC_DECLINE, 'line_unmapped');
        }

        if (strlen($lineItems) > StripePayload::METADATA_VALUE_MAX_LENGTH) {
            $this->log->error('payment.too_many_lines', ['anchor' => $order->anchorSlug, 'lines' => count($order->lines)]);

            return PlacementOutcome::declined(null, StripeOutcome::GENERIC_DECLINE, 'too_many_lines');
        }

        $settlement = StripePayload::settlementFor($order);
        if ($settlement !== $order->settlement) {
            $this->log->info('payment.settlement_charged_instead', [
                'anchor' => $order->anchorSlug,
                'reason' => $order->followOn ? 'follow_on_order' : 'no_prescription_line',
            ]);
        }

        $dropped = StripePayload::droppedAttribution($order->attribution);
        if ($dropped !== []) {
            $this->log->info('payment.attribution_dropped', ['keys' => $dropped]);
        }

        $repriced = $this->repriceCoupon($order);
        if ($repriced !== null) {
            return $repriced;
        }

        $idempotencyKey = self::idempotencyKey($order, $credential);

        try {
            $stripe = $this->apiFactory->create($this->credentials);
            $customerId = (string) ($payment['customer'] ?? '');
            if ($customerId === '') {
                $customerId = $this->customerFor($stripe, $order, $idempotencyKey);
            }

            $paymentIntent = $stripe->paymentIntents->create(
                StripePayload::forOrder($order, $payment, $customerId, $lineItems, $settlement, $this->channelId, $this->returnUrl),
                ['idempotency_key' => $idempotencyKey . ':payment_intent'],
            );
        } catch (CardException $e) {
            // A refused card: Stripe created the PaymentIntent and failed it,
            // so the reference is recorded against the decline (`[13.26]`).
            $outcome = StripeOutcome::fromCardError(
                self::idOf($e->getError()?->payment_intent ?? null),
                $e->getMessage(),
                $e->getDeclineCode() ?? $e->getStripeCode(),
            );
            $this->log->warning('payment.not_placed', [
                'state' => $outcome->state,
                'reference' => $outcome->reference,
                'raw_status' => $outcome->rawStatus,
            ]);

            return $outcome;
        } catch (\Throwable $e) {
            return $this->unanswered($e);
        }

        $outcome = StripeOutcome::from($paymentIntent->toArray(), $order->totalCents);

        if (!$outcome->isPlaced()) {
            $this->log->warning('payment.not_placed', [
                'state' => $outcome->state,
                'reference' => $outcome->reference,
                'raw_status' => $outcome->rawStatus,
            ]);
        }

        $discrepancy = $outcome->chargeDiscrepancy;
        if ($discrepancy !== null) {
            $this->log->error('payment.total_mismatch', [
                'reference' => $outcome->reference,
                'expected_cents' => $discrepancy->expectedCents,
                'charged_cents' => $discrepancy->chargedCents,
                'difference_cents' => $discrepancy->differenceCents(),
                'anchor' => $order->anchorSlug,
            ]);
        }

        return $outcome;
    }

    /**
     * Reads the PaymentIntent a verification left pending (`[14.22]`).
     *
     * Stripe finishes the payment itself once the buyer verifies -- the
     * PaymentIntent moves on without another confirming call -- so this only
     * has to read what it moved to.
     */
    public function resume(string $reference): PlacementOutcome
    {
        if (!str_starts_with($reference, 'pi_')) {
            return PlacementOutcome::declined($reference, StripeOutcome::GENERIC_DECLINE, 'invalid_reference');
        }

        try {
            $paymentIntent = $this->apiFactory->create($this->credentials)
                ->paymentIntents->retrieve($reference, ['expand' => ['payment_method']]);
        } catch (\Throwable $e) {
            // Unanswered is not "declined": the buyer may have just verified a
            // payment that went through. Still pending, with nothing to run,
            // is the honest answer -- the confirm step asks again.
            $this->log->warning('payment.resume_unanswered', ['reference' => $reference, 'exception' => $e::class]);

            return PlacementOutcome::pendingAction($reference, '', 'resume_unanswered');
        }

        return StripeOutcome::from($paymentIntent->toArray());
    }

    public function quotePromotion(OrderEnvelope $order, string $code): PromotionQuote
    {
        return $this->coupons->quote($code, $order->lines);
    }

    /**
     * The PaymentIntents this channel created inside the window, for the
     * reverse sweep (`[21.9a]`).
     *
     * Stripe's list is newest first. A PaymentIntent another storefront or
     * the EMR's own renewals created on the same account is not this
     * channel's to account for, so it is left out by the channel id every
     * payment from here carries.
     */
    public function searchOrders(OrderSearch $search): OrderSearchResult
    {
        $orders = [];
        $truncated = false;

        try {
            $page = $this->apiFactory->create($this->credentials)->paymentIntents->all([
                'created' => ['gte' => $search->from->getTimestamp(), 'lte' => $search->to->getTimestamp()],
                'limit' => min(self::SEARCH_PAGE_SIZE, $search->limit),
            ]);

            foreach ($page->autoPagingIterator() as $paymentIntent) {
                $row = $paymentIntent->toArray();
                if (($row['metadata']['storefront_channel_id'] ?? null) !== $this->channelId) {
                    continue;
                }

                if (count($orders) >= $search->limit) {
                    $truncated = true;
                    break;
                }

                $orders[] = self::orderFrom($row);
            }
        } catch (ApiConnectionException) {
            $this->log->warning('payment.order_search_unreachable', []);

            return OrderSearchResult::failed('transport_error');
        } catch (\Throwable $e) {
            $this->log->warning('payment.order_search_threw', ['exception' => $e::class, 'status' => $e->getCode()]);

            return OrderSearchResult::failed('exception');
        }

        // Stripe reports no total; one more than was returned is the honest
        // way to say there were more than the limit let through.
        return OrderSearchResult::of($orders, $truncated ? count($orders) + 1 : null);
    }

    /**
     * Refused: the EMR captures Stripe holds, when the doctor completes the
     * case it opened for the order.
     *
     * Capturing here would take the money before that decision, and the EMR
     * would then read the payment as settled and never ask. A failure rather
     * than {@see CaptureOutcome::unsupported()}, because this adapter does hold
     * funds -- the question is who releases them, and the answer is not this
     * command.
     */
    public function capture(CaptureRequest $request): CaptureOutcome
    {
        $this->log->warning('payment.capture_refused', [
            'reference' => $request->reference,
            'reason' => 'Stripe holds are captured by the EMR when the doctor completes the case',
        ]);

        return CaptureOutcome::failed($request->reference, self::SETTLED_BY_EMR);
    }

    /** @return array{ok: bool, detail: string} */
    public function ping(): array
    {
        try {
            // A read every key this adapter can work with is allowed, restricted
            // keys included -- the same permission placing an order needs.
            $this->apiFactory->create($this->credentials)->paymentIntents->all(['limit' => 1]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => sprintf('Stripe refused or was unreachable (%s).', $e::class)];
        }

        $mode = str_starts_with($this->credentials->publishableKey, 'pk_live_') ? 'live' : 'test';

        return ['ok' => true, 'detail' => sprintf('Stripe answered (%s mode).', $mode)];
    }

    /**
     * The buyer's Stripe customer: the newest one with this email, or a new
     * one.
     *
     * Created with the same idempotency key family as the payment, so a retry
     * of the same attempt cannot leave two customers behind.
     */
    private function customerFor(StripeClient $stripe, OrderEnvelope $order, string $idempotencyKey): string
    {
        $email = $order->buyer->email;

        if ($email !== '') {
            $existing = $stripe->customers->all(['email' => $email, 'limit' => 1]);
            $found = $existing->data[0] ?? null;
            if ($found !== null && is_string($found->id ?? null)) {
                return $found->id;
            }
        }

        return (string) $stripe->customers->create(
            StripePayload::forCustomer($order->buyer),
            ['idempotency_key' => $idempotencyKey . ':customer'],
        )->id;
    }

    /**
     * Asks the EMR about the order's discount code once more, just before the
     * payment is created, and refuses the payment when the answer changed.
     *
     * Stripe takes whatever amount it is given, and the EMR records the coupon
     * at sync without refusing it, so this is the last place a code that ran
     * out or was switched off since the buyer applied it can be caught. A code
     * the EMR now refuses, or prices differently, is a total the buyer has not
     * seen: nothing is sent to Stripe, and the checkout drops the stored code
     * (`promotion_repriced`, the raw status its own re-price uses) so the
     * buyer sees the real total. An EMR that cannot be asked right now is not
     * a refusal -- the code was priced when it was applied, and a buyer is not
     * turned away because the coupon service is slow.
     */
    private function repriceCoupon(OrderEnvelope $order): ?PlacementOutcome
    {
        $code = $order->promotionCode;
        if ($code === null || $code === '') {
            return null;
        }

        $quote = $this->coupons->quote($code, $order->lines);

        if (!$quote->valid && $quote->reason === 'unavailable') {
            $this->log->warning('payment.promotion_unconfirmed', ['code' => $code, 'anchor' => $order->anchorSlug]);

            return null;
        }

        if ($quote->valid && $quote->discountCents === $order->discountCents) {
            return null;
        }

        $this->log->info('payment.promotion_repriced', [
            'code' => $code,
            'valid' => $quote->valid,
            'reason' => $quote->reason,
            'was_discount_cents' => $order->discountCents,
            'now_discount_cents' => $quote->discountCents,
        ]);

        return PlacementOutcome::declined(null, $quote->notice ?? self::PROMOTION_CHANGED, 'promotion_repriced');
    }

    /**
     * Stripe answered with an error rather than a payment, or did not answer.
     *
     * Which of the two decides whether a charge can be ruled out. A request
     * Stripe refused as malformed, unauthorised or rate-limited created
     * nothing, so it is a decline and the key goes back. One that never came
     * back, or came back as a server error, may have created a payment: the
     * raw status says so, the attempt stays `sent`, and the buyer is told the
     * payment is being confirmed rather than that it failed.
     */
    private function unanswered(\Throwable $e): PlacementOutcome
    {
        $this->log->error('payment.place_threw', [
            'exception' => $e::class,
            'status' => $e instanceof ApiErrorException ? $e->getHttpStatus() : null,
            'reason' => CardScrubber::scrub($e->getMessage()),
        ]);

        if ($e instanceof ApiConnectionException) {
            return PlacementOutcome::declined(null, StripeOutcome::GENERIC_DECLINE, 'transport_error');
        }

        if ($e instanceof ApiErrorException && ($e->getHttpStatus() ?? 500) < 500) {
            return PlacementOutcome::declined(null, StripeOutcome::GENERIC_DECLINE, 'api_error');
        }

        return PlacementOutcome::declined(null, StripeOutcome::GENERIC_DECLINE, 'exception');
    }

    /**
     * The Stripe idempotency key: this order's storefront key and the
     * instrument paying for it, hashed so it stays inside Stripe's length limit
     * and carries nothing readable.
     */
    private static function idempotencyKey(OrderEnvelope $order, PaymentCredential $credential): string
    {
        $instrument = $credential->kind === PaymentCredential::KIND_TOKEN
            ? $credential->reference
            : (string) ($credential->handle['payment_method'] ?? '');

        return 'astermd-storefront:' . hash('sha256', $order->idempotencyKey . '|' . $instrument);
    }

    /** @param array<string, mixed> $row */
    private static function orderFrom(array $row): ProviderOrder
    {
        $status = is_string($row['status'] ?? null) ? $row['status'] : null;
        $created = $row['created'] ?? null;
        $discount = $row['amount_details']['discount_amount'] ?? 0;

        return new ProviderOrder(
            reference: (string) ($row['id'] ?? ''),
            placedAt: is_int($created) ? gmdate('c', $created) : null,
            isTest: ($row['livemode'] ?? false) !== true,
            isCharged: in_array($status, ['succeeded', 'requires_capture', 'processing'], true),
            rawStatus: $status,
            discountCents: is_int($discount) ? $discount : 0,
        );
    }

    /** An id, whether Stripe answered with the id or the object. */
    private static function idOf(mixed $value): ?string
    {
        if (is_object($value)) {
            $value = $value->id ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
