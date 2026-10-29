<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment\Stripe;

use AsterMD\Storefront\Payment\Buyer;
use AsterMD\Storefront\Payment\OrderEnvelope;
use AsterMD\Storefront\Payment\OrderLine;
use AsterMD\Storefront\Payment\PaymentCredential;
use AsterMD\Storefront\Payment\SettlementMode;

/**
 * The PaymentIntent this storefront creates for an order, in the shape the
 * EMR reads back when the order is synced.
 *
 * **The EMR is the reader that matters, not Stripe.** Stripe only needs an
 * amount. The EMR turns the PaymentIntent into a treatment: it reads each
 * `amount_details` line item for what was bought and at what price, matches it
 * by index to the `astermd_line_items` metadata entry for the EMR's own ids,
 * reads `astermd_order_type` to decide whether the order renews, checks
 * `astermd_session_id` against the session it is synced under, and keeps the
 * customer and card for renewals. Every key here is that contract
 * (`docs/stripe-integration.md` in the sales service); a key it does not know
 * is ignored, and one it reserves for its own renewals must never be set.
 *
 * **A line sold on a plan is named by the plan alone** -- `{"plan_id"}` with no
 * product or variant -- and makes the order a `cycle`: the EMR takes the
 * product and variant from the plan and renews on it. Every other line is
 * `{"product_id", "variant_id"}`, the variant being the product's own id for a
 * product without variants, and an order with no plan line is a
 * `straight_sale`.
 *
 * **Holding funds is narrower here than the deployment's setting.** The EMR
 * captures a held payment when a doctor completes the case it opens for a
 * prescription, so a hold on a cart with no prescription is one nothing would
 * ever capture, and a hold on an upsell is one the EMR never captures
 * automatically. Both are charged at once instead, and the outcome says so
 * ({@see self::settlementFor()}).
 */
final class StripePayload
{
    /** Stripe's limit on one metadata value, which bounds how many lines one order can name. */
    public const int METADATA_VALUE_MAX_LENGTH = 500;

    /** Stripe's limit on a line item's name. */
    private const int LINE_ITEM_NAME_MAX_LENGTH = 1024;

    /** The kind the catalog gives a prescription line. */
    private const string PRESCRIPTION = 'rx';

    /** Canonical attribution key → the metadata key the EMR reads it from. */
    private const array ATTRIBUTION = [
        'affiliate_id' => 'astermd_aff',
        'c1' => 'astermd_c1',
        'c2' => 'astermd_c2',
        'c3' => 'astermd_c3',
        'c4' => 'astermd_c4',
        'c5' => 'astermd_c5',
        'utm_source' => 'astermd_utm_source',
        'utm_medium' => 'astermd_utm_medium',
        'utm_campaign' => 'astermd_utm_campaign',
    ];

    /** An EMR (DocumentDB) ObjectId: what the EMR refuses anything else for. */
    private const string OBJECT_ID = '/^[a-f\d]{24}$/i';

    /**
     * What this order may do with the money, given what was asked.
     *
     * Authorize only when asked to, only for a cart with a prescription line,
     * and never for a follow-on order -- see the class docblock for why each
     * of the other cases would be a hold nobody captures.
     */
    public static function settlementFor(OrderEnvelope $order): SettlementMode
    {
        if (!$order->settlement->isAuthorize() || $order->followOn) {
            return SettlementMode::Capture;
        }

        foreach ($order->lines as $line) {
            if ($line->kind === self::PRESCRIPTION) {
                return SettlementMode::Authorize;
            }
        }

        return SettlementMode::Capture;
    }

    /**
     * How the credential pays, or an empty array when this provider cannot
     * charge it -- which the adapter refuses before anything is sent.
     *
     * A token is what Stripe's own fields minted on the page (a confirmation
     * token), confirmed here with the buyer present. A stored instrument is the
     * customer and card an earlier payment on the journey saved, charged with
     * the buyer no longer entering anything. A card is never charged here: this
     * storefront does not collect one for this provider.
     *
     * @return array<string, mixed>
     */
    public static function paymentFor(PaymentCredential $credential): array
    {
        if ($credential->kind === PaymentCredential::KIND_TOKEN && $credential->reference !== '') {
            return ['confirmation_token' => $credential->reference];
        }

        if ($credential->isReusable()) {
            $customer = $credential->handle['customer'] ?? '';
            $method = $credential->handle['payment_method'] ?? '';

            if ($customer !== '' && $method !== '') {
                return ['customer' => $customer, 'payment_method' => $method, 'off_session' => true];
            }
        }

        return [];
    }

    /**
     * The `astermd_line_items` value, or null when a line cannot be named the
     * way the EMR names it -- an order the EMR cannot map is refused rather
     * than sent with a gap in it.
     */
    public static function lineItemsMetadata(OrderEnvelope $order): ?string
    {
        $entries = [];

        foreach ($order->lines as $line) {
            $entry = self::lineEntry($line);
            if ($entry === null) {
                return null;
            }

            $entries[] = $entry;
        }

        return $entries === [] ? null : (string) json_encode($entries, JSON_UNESCAPED_SLASHES);
    }

    /** Whether any line is sold on a plan, which is what makes the order renew. */
    public static function orderType(OrderEnvelope $order): string
    {
        foreach ($order->lines as $line) {
            if ($line->planId !== null) {
                return 'cycle';
            }
        }

        return 'straight_sale';
    }

    /**
     * The PaymentIntent for an order, confirmed in the same call.
     *
     * @param array<string, mixed> $payment from {@see self::paymentFor()}
     * @return array<string, mixed>
     */
    public static function forOrder(
        OrderEnvelope $order,
        array $payment,
        string $customerId,
        string $lineItems,
        SettlementMode $settlement,
        string $channelId,
        string $returnUrl,
    ): array {
        $offSession = ($payment['off_session'] ?? false) === true;

        $body = [
            'amount' => $order->totalCents,
            'currency' => strtolower($order->currency),
            'customer' => $customerId,
            'confirm' => true,
            // Cards only: the EMR saves a card for renewals and reads its
            // descriptor, and a method that redirects to a third party is one
            // this checkout has no page to come back to except the confirm step.
            // Stripe only confirms a token with the same kind of list the page's
            // Elements collected it under, so this must stay in step with
            // `allowedPaymentMethodTypes` in theme/js/payment-stripe.js.
            'allowed_payment_method_types' => ['card'],
            'capture_method' => $settlement->isAuthorize() ? 'manual' : 'automatic',
            'amount_details' => self::amountDetails($order),
            'metadata' => self::metadata($order, $lineItems, $settlement, $channelId),
            'expand' => ['payment_method'],
        ] + $payment;

        if (!$offSession) {
            // The card is saved for the renewals the EMR charges and for an
            // upsell on this journey, and the page's Elements were set up the
            // same way -- the two must agree or Stripe refuses the token.
            $body['setup_future_usage'] = 'off_session';
            // Any verification the bank asks for is run on the page by
            // Stripe's script, or by redirect back to the confirm step.
            $body['use_stripe_sdk'] = true;
            $body['return_url'] = $returnUrl;
        }

        $shipping = self::shipping($order->buyer);
        if ($shipping !== null) {
            $body['shipping'] = $shipping;
        }

        return $body;
    }

    /**
     * The Stripe Customer an order's card is saved to.
     *
     * @return array<string, mixed>
     */
    public static function forCustomer(Buyer $buyer): array
    {
        $body = ['email' => $buyer->email];

        $name = self::fullName($buyer);
        if ($name !== '') {
            $body['name'] = $name;
        }

        if ($buyer->phone !== '') {
            $body['phone'] = $buyer->phone;
        }

        $shipping = self::shipping($buyer);
        if ($shipping !== null) {
            $body['address'] = $shipping['address'];
            $body['shipping'] = $shipping;
        }

        return $body;
    }

    /**
     * Canonical attribution keys the EMR has no slot for, so the adapter can
     * say what it dropped (`[14.7]`).
     *
     * @param array<string, string> $attribution
     * @return list<string>
     */
    public static function droppedAttribution(array $attribution): array
    {
        $dropped = [];

        foreach ($attribution as $key => $value) {
            if (trim((string) $value) !== '' && !isset(self::ATTRIBUTION[$key])) {
                $dropped[] = (string) $key;
            }
        }

        return $dropped;
    }

    /** @return array<string, string>|null */
    private static function lineEntry(OrderLine $line): ?array
    {
        if ($line->planId !== null) {
            return preg_match(self::OBJECT_ID, $line->planId) === 1 ? ['plan_id' => $line->planId] : null;
        }

        if ($line->emrProductId === null || $line->emrVariantId === null
            || preg_match(self::OBJECT_ID, $line->emrProductId) !== 1
            || preg_match(self::OBJECT_ID, $line->emrVariantId) !== 1) {
            return null;
        }

        return ['product_id' => $line->emrProductId, 'variant_id' => $line->emrVariantId];
    }

    /**
     * One line item per order line, in the order `astermd_line_items` names
     * them -- the EMR pairs the two by index. Stripe checks the items less the
     * discount add up to the amount, so every line goes, free ones included.
     *
     * @return array<string, mixed>
     */
    private static function amountDetails(OrderEnvelope $order): array
    {
        $items = [];

        foreach ($order->lines as $line) {
            $items[] = [
                'product_name' => mb_substr($line->name !== '' ? $line->name : 'Item', 0, self::LINE_ITEM_NAME_MAX_LENGTH),
                'unit_cost' => $line->unitPriceCents,
                'quantity' => $line->quantity,
            ];
        }

        $details = ['line_items' => $items];
        if ($order->discountCents > 0) {
            $details['discount_amount'] = $order->discountCents;
        }

        return $details;
    }

    /** @return array<string, string> */
    private static function metadata(OrderEnvelope $order, string $lineItems, SettlementMode $settlement, string $channelId): array
    {
        $metadata = [
            'astermd_line_items' => $lineItems,
            'astermd_order_type' => self::orderType($order),
            // Informational: how the payment settles and which code the buyer
            // typed, readable on the payment in Stripe without asking the EMR.
            'capture_method' => $settlement->isAuthorize() ? 'manual' : 'automatic',
        ];

        if ($settlement->isAuthorize()) {
            $metadata['astermd_payment_mode'] = 'pre_auth';
        }

        if ($order->sessionUuid !== null && $order->sessionUuid !== '') {
            $metadata['astermd_session_id'] = $order->sessionUuid;
        }

        if ($order->promotionCode !== null && $order->promotionCode !== '') {
            $metadata['coupon'] = $order->promotionCode;
        }

        if ($order->promotionReference !== null && preg_match(self::OBJECT_ID, $order->promotionReference) === 1) {
            $metadata['astermd_coupon_id'] = $order->promotionReference;
        }

        if ($order->followOn) {
            $metadata['astermd_upsell'] = '1';
        }

        foreach ($order->attribution as $key => $value) {
            $slot = self::ATTRIBUTION[$key] ?? null;
            $value = trim((string) $value);

            if ($slot !== null && $value !== '') {
                $metadata[$slot] = mb_substr($value, 0, self::METADATA_VALUE_MAX_LENGTH);
            }
        }

        // Which storefront channel created the payment, so the reverse sweep
        // can tell this channel's payments from another's on a shared account.
        if ($channelId !== '') {
            $metadata['storefront_channel_id'] = $channelId;
        }

        return $metadata;
    }

    /** @return array{name: string, phone?: string, address: array<string, string>}|null */
    private static function shipping(Buyer $buyer): ?array
    {
        if ($buyer->addressLine === '') {
            return null;
        }

        $shipping = [
            'name' => self::fullName($buyer),
            'address' => [
                'line1' => $buyer->addressLine,
                'city' => $buyer->city,
                'state' => $buyer->territory,
                'postal_code' => $buyer->postalCode,
                'country' => $buyer->country,
            ],
        ];

        if ($buyer->phone !== '') {
            $shipping['phone'] = $buyer->phone;
        }

        return $shipping;
    }

    private static function fullName(Buyer $buyer): string
    {
        return trim($buyer->firstName . ' ' . $buyer->lastName);
    }
}
