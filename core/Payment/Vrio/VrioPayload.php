<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment\Vrio;

use AsterMD\Storefront\Payment\OrderEnvelope;
use AsterMD\Storefront\Payment\PaymentCredential;

/**
 * Capability 4: a neutral order envelope → this provider's `POST /orders` body.
 *
 * Every field name here was taken from a request the provider actually
 * accepted, not from its documentation, because the two disagree in three
 * places: the docs' recipe calls the billing flag `same_address` where the
 * working payload uses `shipping_same`; the docs describe `offers` entries as
 * JSON-encoded strings where objects are equally accepted; and the docs'
 * `offers[]` schema names `item_id` without `offer_id` where the provider in
 * fact demands both ("Item id required for offer 337").
 *
 * The `offers` array is built by {@see VrioOffers}, which the discount quote
 * shares, so a line is described to the provider the same way whether it is
 * being priced or charged.
 *
 * Placement is single-step -- `action: "process"` creates and charges in one
 * call. The multi-step alternative doubles the round trips inside a request
 * the buyer is waiting on and buys nothing, because the provider offers no
 * idempotency either way: an identical payload posted twice creates two orders
 * and charges both, and `connection_order_id` is ignored on create. The
 * duplicate guard is therefore entirely the storefront's, and this payload
 * deliberately does not carry the idempotency key -- sending it would imply a
 * provider-side guarantee that does not exist.
 */
final class VrioPayload
{
    /** The provider's payment-method code for a credit card. */
    private const int PAYMENT_METHOD_CARD = 1;

    /**
     * @return array<string, mixed> the JSON body
     */
    public static function forOrder(
        OrderEnvelope $order,
        PaymentCredential $credential,
        VrioCredentials $credentials,
        int $shippingProfileId,
    ): array {
        [$slots] = VrioAttribution::map($order->attribution);

        $body = [
            'campaign_id' => $credentials->campaignId,
            'connection_id' => $credentials->connectionId,
            'force_campaign_id' => true,
            'offers_restrict' => true,
            'shipping_profile_id' => $shippingProfileId,
            'action' => 'process',

            'email' => $order->buyer->email,
            'phone' => $order->buyer->phone,

            'ship_fname' => $order->buyer->firstName,
            'ship_lname' => $order->buyer->lastName,
            'ship_address1' => $order->buyer->addressLine,
            'ship_city' => $order->buyer->city,
            'ship_state' => $order->buyer->territory,
            'ship_zipcode' => $order->buyer->postalCode,
            'ship_country' => $order->buyer->country,

            // Billing is the shipping address (`[13.2]`). The flag and the
            // full set are both sent because the payload known to work sends
            // both, and the provider is silent about which it honours.
            'shipping_same' => true,
            'bill_fname' => $order->buyer->firstName,
            'bill_lname' => $order->buyer->lastName,
            'bill_address1' => $order->buyer->addressLine,
            'bill_city' => $order->buyer->city,
            'bill_state' => $order->buyer->territory,
            'bill_zipcode' => $order->buyer->postalCode,
            'bill_country' => $order->buyer->country,

            'offers' => VrioOffers::forCharge($order),

            'ip_address' => $order->clientIp ?? '',
            'user_agent' => $order->userAgent ?? '',
        ];

        // A journey with no analytics session is still allowed to buy
        // (`[20.1]`), and inventing an identifier is forbidden (`[20.8]`), so
        // the field is absent rather than empty.
        if ($order->sessionUuid !== null && $order->sessionUuid !== '') {
            $body['session_id'] = $order->sessionUuid;
        }

        return $body + $slots + self::paymentFor($credential);
    }

    /**
     * The payment block for one credential, or **an empty array when this
     * provider cannot charge against it**.
     *
     * Public because the adapter has to be able to ask *before* it posts. An
     * empty block merged into an order body is not a harmless omission: the
     * provider accepts the order and creates it with no payment identification
     * at all, which is an unpaid order container the buyer never sees and the
     * operator has to reconcile by hand. {@see VrioAdapter::place()} refuses
     * on an empty answer rather than sending one.
     *
     * A stored instrument sends the pair and **no card fields**, which is the
     * whole point of `[15.3]`: the number stays in the provider's vault and
     * the storefront never holds it. Half a pair is refused by the provider
     * ("Customer Card ID does not belong to Customer"), so half a pair is
     * treated here as no credential at all.
     *
     * @return array<string, mixed>
     */
    public static function paymentFor(PaymentCredential $credential): array
    {
        if ($credential->kind === PaymentCredential::KIND_CARD) {
            return [
                'payment_method_id' => self::PAYMENT_METHOD_CARD,
                'card_type_id' => CardScheme::codeFor($credential->number),
                'card_number' => $credential->number,
                'card_exp_month' => $credential->expiryMonth,
                'card_exp_year' => $credential->expiryYear,
                'card_cvv' => $credential->securityCode,
            ];
        }

        if ($credential->kind !== PaymentCredential::KIND_STORED_INSTRUMENT) {
            return [];
        }

        $customerId = $credential->handle['customer_id'] ?? '';
        $cardId = $credential->handle['customer_card_id'] ?? '';

        if ($customerId === '' || $cardId === '') {
            return [];
        }

        return [
            'payment_method_id' => self::PAYMENT_METHOD_CARD,
            'customer_id' => $customerId,
            'customer_card_id' => $cardId,
        ];
    }
}
