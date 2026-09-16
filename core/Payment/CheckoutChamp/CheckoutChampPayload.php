<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment\CheckoutChamp;

use AsterMD\Storefront\Payment\OrderEnvelope;
use AsterMD\Storefront\Payment\OrderLine;
use AsterMD\Storefront\Payment\PaymentCredential;

/**
 * Capability 4: a neutral order envelope → this provider's two request bodies.
 *
 * **Placement here is two calls, not one, and that is the provider's shape
 * rather than a choice.** `POST /leads/import/` creates the customer and
 * answers a `sessionId`; `POST /order/import/` (or `/order/preauth/`) bills
 * that session. Calling the second without the first answers
 * `"Customer not found"`, which is how the sequence was established.
 *
 * That difference from the other adapter is absorbed entirely here and in
 * {@see CheckoutChampAdapter}. Nothing above `[14.1]`'s boundary learns that
 * this provider needs two round trips, any more than it learns that the other
 * one needs a campaign and a connection.
 *
 * **Lines are numbered parameters, not an array.** The provider takes
 * `product1_id`, `product2_id` and so on, one-based, so a cart becomes a flat
 * set of keys rather than a nested list. {@see self::lines()} is the only place
 * that numbering exists, because a second place that counted differently would
 * charge for the wrong thing and look like a pricing bug.
 *
 * **Prices are sent per line.** The campaign carries its own price for a
 * product, and a storefront that let the campaign decide would show one figure
 * and charge another whenever the two drifted — the same class of defect
 * `[13.x]` reconciliation exists to catch after the fact. Sending the figure
 * the buyer agreed to makes the two agree by construction.
 *
 * **Unverified against a live account**: the field names below are the
 * provider's documented ones, and no order has been placed through them. What
 * *is* recorded is the refusal shape — see
 * {@see CheckoutChampOutcome} and `docs/INTEGRATION-NOTES.md` — so a wrong name
 * here fails as a stated provider error rather than as a silent miss.
 */
final class CheckoutChampPayload
{
    /** Create the order and bill it in one call against an existing session. */
    public const string ACTION_IMPORT = 'import';

    /** Reserve the funds against an existing session; a later capture settles it. */
    public const string ACTION_PREAUTH = 'preauth';

    /**
     * `POST /leads/import/` — the customer and address, which must exist before
     * an order can name them.
     *
     * @return array<string, mixed>
     */
    public static function forLead(OrderEnvelope $order, CheckoutChampCredentials $credentials): array
    {
        $buyer = $order->buyer;

        $body = [
            'campaignId' => $credentials->campaignId,
            'firstName' => $buyer->firstName,
            'lastName' => $buyer->lastName,
            'emailAddress' => $buyer->email,
            'phoneNumber' => $buyer->phone,

            'address1' => $buyer->addressLine,
            'city' => $buyer->city,
            'state' => $buyer->territory,
            'postalCode' => $buyer->postalCode,
            'country' => $buyer->country,

            // Billing is the shipping address (`[13.2]`), sent in full rather
            // than as a flag: the provider documents no "same as shipping"
            // switch, and a half-populated billing address is refused.
            'billFirstName' => $buyer->firstName,
            'billLastName' => $buyer->lastName,
            'billAddress1' => $buyer->addressLine,
            'billCity' => $buyer->city,
            'billState' => $buyer->territory,
            'billPostalCode' => $buyer->postalCode,
            'billCountry' => $buyer->country,

            'ipAddress' => $order->clientIp ?? '',
        ];

        // A journey with no analytics session is still allowed to buy
        // (`[20.1]`), and inventing an identifier is forbidden (`[20.8]`), so
        // the field is absent rather than empty.
        if ($order->sessionUuid !== null && $order->sessionUuid !== '') {
            $body['requestUri'] = $order->sessionUuid;
        }

        return $body;
    }

    /**
     * `POST /order/import/` or `/order/preauth/` — the session, the lines and
     * the card.
     *
     * @param string $sessionId the identifier {@see self::forLead()}'s call answered
     *
     * @return array<string, mixed>
     */
    public static function forOrder(
        OrderEnvelope $order,
        PaymentCredential $credential,
        CheckoutChampCredentials $credentials,
        string $sessionId,
    ): array {
        return [
            'campaignId' => $credentials->campaignId,
            'sessionId' => $sessionId,
        ] + self::lines($order) + self::paymentFor($credential);
    }

    /**
     * The cart as this provider's one-based numbered parameters.
     *
     * Only chargeable lines are numbered. A line the provider has never heard
     * of cannot be sent, and skipping it here rather than sending a blank id
     * is what keeps the numbering contiguous — the provider stops reading at
     * the first gap, so a hole would silently drop every line after it.
     *
     * @return array<string, mixed>
     */
    public static function lines(OrderEnvelope $order): array
    {
        $params = [];
        $position = 0;

        foreach ($order->chargeableLines() as $line) {
            ++$position;
            $params['product' . $position . '_id'] = (string) $line->providerItem;
            $params['product' . $position . '_qty'] = $line->quantity;
            $params['product' . $position . '_price'] = self::decimal($line->unitPriceCents);
        }

        return $params;
    }

    /**
     * The payment block for one credential, or **an empty array when this
     * provider cannot charge against it**.
     *
     * Public because the adapter has to be able to ask *before* it posts, for
     * the reason the other adapter's equivalent is: an order body with no
     * payment identification is not a partial request, it is an order the
     * provider may create and nobody is watching.
     *
     * A stored instrument names the customer the provider already holds and
     * sends no card fields (`[15.3]`).
     *
     * @return array<string, mixed>
     */
    public static function paymentFor(PaymentCredential $credential): array
    {
        if ($credential->kind === PaymentCredential::KIND_CARD) {
            return [
                'paySource' => 'CREDITCARD',
                'cardNumber' => $credential->number,
                'cardMonth' => $credential->expiryMonth,
                // The provider takes a four-digit year, which is what the
                // credential holds; no truncation, because a two-digit year is
                // ambiguous at a century boundary and this one is not.
                'cardYear' => $credential->expiryYear,
                'cardSecurityCode' => $credential->securityCode,
            ];
        }

        if ($credential->kind !== PaymentCredential::KIND_STORED_INSTRUMENT) {
            return [];
        }

        $customerId = $credential->handle['customer_id'] ?? '';

        if ($customerId === '') {
            return [];
        }

        return ['paySource' => 'ACCTONFILE', 'customerId' => $customerId];
    }

    /** Integer cents as the two-place decimal string the provider's money fields take. */
    private static function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
