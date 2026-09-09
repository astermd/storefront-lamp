<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Checkout;

use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Domain\CartLine;

/**
 * One checkout attempt's key (`[13.37]`).
 *
 * Derived from what is being bought and who is buying it, rather than issued
 * as a random token on page render, because the two failure modes it has to
 * separate look identical to a token: a double-click posts the same form twice
 * and must be recognised as one attempt, while a retry after correcting a
 * typo'd address is a *new* attempt and must reach the provider. A token
 * minted per render answers the first badly and the second worse.
 *
 * It doubles as `[13.38]`'s stale-form guard: a cart mutated in another tab
 * between render and submit changes the key, so the second submit cannot be
 * answered with the first one's stored outcome.
 *
 * The key is a hash. It is stored, indexed and logged, and a concatenation of
 * an email address and a postcode is none of those things safely. Nothing
 * about the card is ever among the inputs (`[15.8]`): a digest that lands in a
 * durable column would be one more place a card has been.
 *
 * Two keys are minted, one per thing a journey can charge for: the cart at
 * checkout, and one accepted upsell afterwards. They share a column and a
 * unique index, so each derivation names its own kind in the material and
 * neither can be answered with the other's stored outcome.
 */
final class IdempotencyKey
{
    /** @param array<string, string> $buyer */
    public static function forSubmit(?string $sessionUuid, string $sessionKey, Cart $cart, array $buyer): string
    {
        $lines = [];
        foreach ($cart->lines() as $line) {
            $lines[] = self::lineMaterial($line);
        }

        // Sorted, because adding and removing a bump can reorder the cart
        // without changing what is in it.
        sort($lines);

        ksort($buyer);

        $material = implode('|', [
            $sessionUuid ?? '',
            $sessionKey,
            implode(';', $lines),
            json_encode($buyer, JSON_THROW_ON_ERROR),
        ]);

        return hash('sha1', $material);
    }

    /**
     * The key for one accepted upsell.
     *
     * Keyed on the offer rather than on the cart, because there is no cart --
     * `[13.32]` cleared it when the first order was placed. The upsell's own
     * key, slug and variant are what identify the thing being bought, and the
     * session pair is what identifies the buyer.
     *
     * The offer key is material as well as the slug, because `[16.2]`'s queue
     * is a configuration order and two entries may resolve to the same product.
     * Two offers that derived one key would make the second look like a
     * double-click of the first and never charge.
     *
     * **The price is deliberately not material**, and it used to be. Two
     * clicks on one offer are the same purchase whatever the catalog says in
     * between, so keying on a figure that can move meant a configuration
     * deploy landing between them derived two different keys -- and the
     * provider has no idempotency of its own, so both reached it and both
     * charged. What the price needs is not a place in this digest but a
     * guarantee it has not moved since the buyer saw it, which
     * {@see \AsterMD\Storefront\Upsell\UpsellService::accept()} makes against
     * the quote frozen at render time.
     *
     * **The credential is not an input**, for the reason the checkout's key
     * excludes the card (`[15.8]`): this digest lands in a durable column, and
     * a column derived from a charge authority is one more place that
     * authority has been. It also means a buyer whose handle was refreshed
     * between two attempts at the same offer derives the same key, which is
     * the behaviour that stops a second charge.
     */
    public static function forUpsell(
        ?string $sessionUuid,
        string $sessionKey,
        string $upsellKey,
        string $slug,
        ?string $variantId,
    ): string {
        // The literal separates this digest's space from the checkout's, so an
        // upsell can never be answered with the prescription order's stored
        // outcome -- the two share one column and one unique index.
        $material = implode('|', [
            'upsell',
            $sessionUuid ?? '',
            $sessionKey,
            $upsellKey,
            $slug,
            $variantId ?? '',
        ]);

        return hash('sha1', $material);
    }

    private static function lineMaterial(CartLine $line): string
    {
        return implode(':', [$line->slug, $line->variantId ?? '', (string) $line->quantity, (string) $line->unitPriceCents]);
    }
}
