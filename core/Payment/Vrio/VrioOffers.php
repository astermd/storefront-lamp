<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment\Vrio;

use AsterMD\Storefront\Checkout\Totals;
use AsterMD\Storefront\Payment\OrderEnvelope;
use AsterMD\Storefront\Payment\OrderLine;

/**
 * The one place a cart becomes this provider's `offers` array.
 *
 * Two endpoints take that array: `calculateDiscount`, which tells the buyer
 * what a code is worth, and `POST /orders`, which charges them. Building it
 * twice is what let them drift, on the one field that decides the price -- the
 * quote asked for the discount on every line, the charge asked for it on the
 * first line only, and a 120.00 + 270.00 cart with a 10% code displayed 39.00
 * off while being charged as though it were 12.00. The buyer paid 27.00 more
 * than they agreed to, and because nothing compared the two payloads, nothing
 * could see it. One builder is what makes that disagreement impossible rather
 * than merely fixed.
 *
 * **The code goes on every chargeable line.** Recorded against the live
 * sandbox with a read-only `calculateDiscount` probe: with the code on all
 * lines, offers 337 and 338 return 12.00 and 27.00, both `discount_code_valid`
 * true; with it on the first line only, 338 returns 0.00 and a null validity.
 * `[14.6a]`'s line-item scope means each line carries its own code -- not that
 * the code is attached once and spreads.
 *
 * **The two endpoints disagree about exactly one field name.**
 * `calculateDiscount` reads the quantity from `offer_quantity`; `POST /orders`
 * reads it from `order_offer_quantity`. Sending the wrong one is not an error:
 * the provider computes against quantity 1 and returns a discount that
 * understates by the quantity factor, silently. That difference is a parameter
 * here rather than a second builder, because a second builder is what caused
 * the defect above.
 *
 * Lines with no resolvable offer are skipped, on both endpoints, because the
 * provider has never heard of them (`[13.10]`, `[13.19]`).
 */
final class VrioOffers
{
    /** The quantity key `POST /orders` reads. */
    private const string CHARGE_QUANTITY_KEY = 'order_offer_quantity';

    /** The quantity key `calculateDiscount` reads. */
    private const string QUOTE_QUANTITY_KEY = 'offer_quantity';

    /**
     * The lines as the order endpoint wants them, priced and ready to charge.
     *
     * The four extra fields are the ones only an order carries: this
     * storefront never posts an upsell or a child offer, and shipping is
     * carried by the campaign's shipping profile rather than per line.
     *
     * @return list<array<string, mixed>>
     */
    public static function forCharge(OrderEnvelope $order): array
    {
        $offers = [];

        foreach ($order->chargeableLines() as $line) {
            $offers[] = self::line($line, self::CHARGE_QUANTITY_KEY, (string) $order->promotionCode) + [
                'order_offer_shipping' => '0.00',
                'order_offer_upsell' => false,
                'parent_offer_id' => null,
                'parent_order_id' => null,
            ];
        }

        return $offers;
    }

    /**
     * The same lines as the discount endpoint wants them, for a code the cart
     * does not carry yet.
     *
     * The code is passed in rather than read off the envelope because a quote
     * is what decides whether the envelope should carry it at all.
     *
     * @return list<array<string, mixed>>
     */
    public static function forQuote(OrderEnvelope $order, string $code): array
    {
        $offers = [];

        foreach ($order->chargeableLines() as $line) {
            $offers[] = self::line($line, self::QUOTE_QUANTITY_KEY, $code);
        }

        return $offers;
    }

    /**
     * The fields both endpoints share, including the one that sets the price.
     *
     * The line price is always sent: the provider will otherwise price the
     * line from its own item catalog, and its figure and the channel's differ.
     *
     * @return array<string, mixed>
     */
    private static function line(OrderLine $line, string $quantityKey, string $code): array
    {
        return [
            'offer_id' => (string) $line->providerOffer,
            'item_id' => (string) $line->providerItem,
            'order_offer_price' => Totals::decimalString($line->unitPriceCents),
            $quantityKey => (string) $line->quantity,
            'discount_code' => $code,
        ];
    }
}
