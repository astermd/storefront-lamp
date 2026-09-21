<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment;

/**
 * A card's scheme, in the vocabulary the AsterMD API names, derived from the
 * number's prefix.
 *
 * **One table, and this is it.** {@see Vrio\CardScheme} maps this onto that
 * provider's integer codes rather than keeping a second copy of the ranges: two
 * tables drift, and the one that drifts is the one nobody is looking at.
 *
 * **An unrecognised prefix is null, not a guess.** A brand is an assertion
 * about the card on a payload a clinician and an operator both read, and every
 * caller can express "we could not tell" — {@see CardDescriptor} omits the
 * field. That is the opposite of what `CardScheme` does with the same null, and
 * deliberately so: there the field is a hint beside a number the provider
 * validates itself, so falling back to Visa costs nothing and refusing would
 * block a legitimate card.
 *
 * The ranges are the schemes' published assignments. Discover's UnionPay
 * co-brand block (622126-622925) is a real assignment and is deliberately
 * absent: it was outside `CardScheme`'s table before this enum existed, and
 * adding it here would silently change what Vrio is sent for those cards.
 */
enum CardBrand: string
{
    case Amex = 'amex';

    case Visa = 'visa';

    case Mastercard = 'mastercard';

    case Discover = 'discover';

    case DinersClub = 'diners_club';

    case Jcb = 'jcb';

    /**
     * The scheme this number belongs to, or null when its prefix is not one
     * this table assigns.
     *
     * Spaces and dashes are stripped first: they are how a card is printed and
     * how people type it, and neither is part of the prefix.
     */
    public static function fromNumber(string $cardNumber): ?self
    {
        $digits = preg_replace('/\D/', '', $cardNumber) ?? '';

        if ($digits === '') {
            return null;
        }

        $two = (int) substr($digits, 0, 2);
        $three = (int) substr($digits, 0, 3);
        $four = (int) substr($digits, 0, 4);

        return match (true) {
            str_starts_with($digits, '4') => self::Visa,
            $two >= 51 && $two <= 55 => self::Mastercard,
            $four >= 2221 && $four <= 2720 => self::Mastercard,
            $two === 34 || $two === 37 => self::Amex,
            // JCB is tested before Diners Club: 3528-3589 sits inside the 35
            // neighbourhood, and Diners Club's own blocks (300-305, 3095, 36,
            // 38-39) do not overlap it.
            $four >= 3528 && $four <= 3589 => self::Jcb,
            $three >= 300 && $three <= 305 => self::DinersClub,
            $four === 3095 => self::DinersClub,
            $two === 36 || $two === 38 || $two === 39 => self::DinersClub,
            str_starts_with($digits, '6011') || $two === 65 => self::Discover,
            $four >= 6440 && $four <= 6499 => self::Discover,
            default => null,
        };
    }
}
