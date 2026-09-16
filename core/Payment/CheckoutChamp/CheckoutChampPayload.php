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
 * rather than a choice.** `POST /leads/import/` creates the customer *and a
 * PARTIAL order*, answering the `orderId` everything afterwards is keyed on;
 * `POST /order/import/` (or `/order/preauth/`) bills it. Calling the second
 * without the first answers `"Customer not found"`.
 *
 * **The order reference is minted by the lead call**, not by the billing call.
 * That is worth stating because it is the opposite of the other provider, where
 * the reference arrives with the charge -- and it means a placement that is
 * refused at the billing step still has a reference, which `[13.26]` requires
 * be recorded.
 *
 * **The billing call needs the shipping address again.** The lead call already
 * carried it, and omitting it on the order answers a field map:
 * `{"shipAddress1": "is a required field", ...}`. Sent on both, therefore.
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
 * **The campaign is a line's `provider.offer_id`**, not a deployment setting.
 * That is how the EMR spells it: a variant's provider mapping carries
 * `offer_id` and `product_id`, and for this provider those are the campaign and
 * the campaign-scoped product. The catalog therefore already holds everything an
 * order needs, and {@see OrderLine} keeps both opaque -- which is exactly what
 * `[14.1]` capability 3 asks of it, and why a second provider needed no new
 * catalog field.
 *
 * **`product_id` is the campaign-scoped id, not the bare one.** A campaign
 * lists each product twice over: `campaignProductId` (15271) and `productId`
 * (14015, which also appears in parentheses at the front of `productName`).
 * The EMR maps the first, and the first is what an order is placed with --
 * sending the bare id would name a product the campaign does not offer.
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
    public static function forLead(OrderEnvelope $order, string $salesUrl = ''): array
    {
        $buyer = $order->buyer;

        $body = self::shipping($order) + [
            'campaignId' => (string) self::campaignFor($order),
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

        // Optional, and worth sending: the provider shows it against the order
        // in its own dashboard, so an operator reconciling one by hand can see
        // which storefront page it came from rather than only a campaign
        // number. Absent rather than empty when the deployment has no URL
        // configured, on `[20.8]`'s no-invented-identifier terms.
        if ($salesUrl !== '') {
            $body['salesUrl'] = $salesUrl;
        }

        return $body;
    }

    /**
     * The campaign this order is placed under, or null when its lines do not
     * agree on one.
     *
     * Read from the lines rather than from configuration because that is where
     * the EMR puts it. Null has two causes and the caller refuses on both: no
     * chargeable line carries a campaign, or two of them carry different ones.
     *
     * **Disagreement is refused rather than resolved.** A cart is one order
     * (`[13.19]`) and an order belongs to one campaign, so a cart spanning two
     * has no correct single answer -- and picking the first line's would place
     * the whole order under a campaign that does not offer half of it, which
     * the provider reports as the cart being empty.
     */
    public static function campaignFor(OrderEnvelope $order): ?string
    {
        return self::campaignOf($order->chargeableLines());
    }

    /**
     * The same rule over a bare line list, for the settle call -- which has the
     * lines the order was placed with and no envelope around them.
     *
     * @param list<OrderLine> $lines
     */
    public static function campaignOf(array $lines): ?string
    {
        $campaigns = [];

        foreach ($lines as $line) {
            if ($line->isChargeable()) {
                $campaigns[(string) $line->providerOffer] = true;
            }
        }

        return count($campaigns) === 1 ? (string) array_key_first($campaigns) : null;
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
        string $reference,
    ): array {
        return [
            'campaignId' => (string) self::campaignFor($order),
            'orderId' => $reference,
        ] + self::shipping($order) + self::lines($order) + self::paymentFor($credential);
    }

    /**
     * `POST /order/import/` again, this time to **settle** an order that was
     * pre-authorized -- with the lines and without a card.
     *
     * **The lines are not optional and cannot be recovered from the provider.**
     * A pre-authorized order carries an empty `items` array until this call
     * supplies them, so `orderId` alone is answered "No products exist in the
     * order" and the order stays partial with the funds still held. Re-reading
     * the order first would return that same empty projection, which is why
     * {@see \AsterMD\Storefront\Payment\CaptureRequest} carries them from the
     * storefront's own record instead.
     *
     * No card, no shipping: both were taken at the pre-authorization, and the
     * settle call is accepted without either.
     *
     * @param list<\AsterMD\Storefront\Payment\OrderLine> $lines
     *
     * @return array<string, mixed>
     */
    public static function forCapture(string $reference, array $lines, string $campaignId): array
    {
        return ['campaignId' => $campaignId, 'orderId' => $reference] + self::numbered($lines);
    }

    /**
     * The shipping block, which both the lead call and the billing call want.
     *
     * @return array<string, string>
     */
    private static function shipping(OrderEnvelope $order): array
    {
        $buyer = $order->buyer;

        return [
            'shipFirstName' => $buyer->firstName,
            'shipLastName' => $buyer->lastName,
            'shipAddress1' => $buyer->addressLine,
            'shipCity' => $buyer->city,
            'shipState' => $buyer->territory,
            'shipPostalCode' => $buyer->postalCode,
            'shipCountry' => $buyer->country,
        ];
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
        return self::numbered($order->chargeableLines());
    }

    /**
     * @param  list<OrderLine>     $lines already filtered to the chargeable ones
     * @return array<string, mixed>
     */
    private static function numbered(array $lines): array
    {
        $params = [];
        $position = 0;

        foreach ($lines as $line) {
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
