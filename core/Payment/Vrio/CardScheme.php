<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment\Vrio;

use AsterMD\Storefront\Payment\CardBrand;

/**
 * `[14.12]`: the card scheme is derived from the number's prefix and submitted
 * as this provider's own type code.
 *
 * The code values come from the provider's documented table (1 Mastercard,
 * 2 Visa, 3 Discover, 4 American Express, 5 Digital Wallet, 6 ACH, 7 SEPA) and
 * **2 = Visa is confirmed live** -- two sandbox orders and the project owner's
 * own working payload all send `card_type_id: 2` with a 4-prefixed number and
 * are accepted. That confirmation matters because the provider's own
 * single-step example contradicts its table, pairing a Visa number with
 * `card_type_id: 1`.
 *
 * An unrecognised prefix falls back to Visa rather than refusing the order:
 * the provider treats the field as a hint beside the number it will itself
 * validate, and blocking a legitimate card on a prefix table this file cannot
 * keep current would be the worse failure.
 *
 * **The prefixes themselves live in {@see CardBrand} and not here.** This file
 * holds the mapping onto one provider's integers, which is the only part of it
 * that is about this provider. Two copies of a BIN table drift, and the copy
 * that drifts is the one nobody is looking at. Diners Club and JCB have no code
 * in the provider's table, so they reach the same Visa fallback an unknown
 * prefix does.
 */
final class CardScheme
{
    public const int MASTERCARD = 1;

    public const int VISA = 2;

    public const int DISCOVER = 3;

    public const int AMEX = 4;

    public static function codeFor(string $cardNumber): int
    {
        return match (CardBrand::fromNumber($cardNumber)) {
            CardBrand::Mastercard => self::MASTERCARD,
            CardBrand::Amex => self::AMEX,
            CardBrand::Discover => self::DISCOVER,
            // Visa, an unrecognised prefix, an empty string, and the two
            // brands this provider's table has no code for.
            default => self::VISA,
        };
    }

    /**
     * The brand one of this provider's codes stands for, or null when the code
     * is not a card scheme at all.
     *
     * The inverse of {@see self::codeFor()} and lossy in the way that inverse
     * must be: `VISA` is also what an unrecognised prefix is sent as, so a
     * response echoing `2` cannot be read as proof the card is a Visa. The
     * provider's wallet, ACH and SEPA codes answer null because none of them
     * names a scheme.
     */
    public static function brandFor(int $code): ?CardBrand
    {
        return match ($code) {
            self::MASTERCARD => CardBrand::Mastercard,
            self::VISA => CardBrand::Visa,
            self::DISCOVER => CardBrand::Discover,
            self::AMEX => CardBrand::Amex,
            default => null,
        };
    }
}
