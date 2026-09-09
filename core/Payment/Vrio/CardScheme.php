<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment\Vrio;

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
 */
final class CardScheme
{
    public const int MASTERCARD = 1;

    public const int VISA = 2;

    public const int DISCOVER = 3;

    public const int AMEX = 4;

    public static function codeFor(string $cardNumber): int
    {
        $digits = preg_replace('/\D/', '', $cardNumber) ?? '';

        if ($digits === '') {
            return self::VISA;
        }

        $two = (int) substr($digits, 0, 2);
        $four = (int) substr($digits, 0, 4);

        return match (true) {
            str_starts_with($digits, '4') => self::VISA,
            $two >= 51 && $two <= 55 => self::MASTERCARD,
            $four >= 2221 && $four <= 2720 => self::MASTERCARD,
            $two === 34 || $two === 37 => self::AMEX,
            str_starts_with($digits, '6011') || $two === 65 => self::DISCOVER,
            $four >= 6440 && $four <= 6499 => self::DISCOVER,
            default => self::VISA,
        };
    }
}
