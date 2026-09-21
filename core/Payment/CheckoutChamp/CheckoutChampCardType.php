<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment\CheckoutChamp;

use AsterMD\Storefront\Payment\CardBrand;

/**
 * This provider's `message.cardType` → a brand.
 *
 * Unlike the other adapter's numeric code, this is the provider's own reading
 * of the card rather than an echo of what the storefront sent: it arrives
 * beside `cardBin`, `cardLast4` and `cardExpiryDate`, none of which the
 * storefront supplied in that form. That is what makes it usable as the answer
 * when the number's prefix is one the storefront cannot place.
 *
 * **It answers one question and is asked no others.** The bin and the expiry
 * beside it are ignored: the buyer typed both into the checkout form, so the
 * storefront already holds them, and a value supplied directly is not
 * re-sourced from a system that changes its response shape and sometimes omits
 * a field it documents.
 *
 * A value naming no scheme answers null rather than a default. The sandbox
 * sends `TESTCARD`, which is not a brand, and reporting one for it would put a
 * fabrication on a payload a clinician reads.
 */
final class CheckoutChampCardType
{
    /** @var array<string, CardBrand> the provider's spellings, lowercased */
    private const array BRANDS = [
        'visa' => CardBrand::Visa,
        'mastercard' => CardBrand::Mastercard,
        'master card' => CardBrand::Mastercard,
        'amex' => CardBrand::Amex,
        'american express' => CardBrand::Amex,
        'discover' => CardBrand::Discover,
        'diners' => CardBrand::DinersClub,
        'diners club' => CardBrand::DinersClub,
        'jcb' => CardBrand::Jcb,
    ];

    public static function brandFor(mixed $cardType): ?CardBrand
    {
        if (!is_string($cardType)) {
            return null;
        }

        return self::BRANDS[strtolower(trim($cardType))] ?? null;
    }
}
