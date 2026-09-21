<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment;

/**
 * The card sub-object of the API's payment block: what the storefront may say
 * about the instrument, and no more.
 *
 * **Never the number.** The bin is the first six digits, which name the issuer
 * rather than the account, and the last four never leaves the `orders` row it
 * is already written to. `[15.8]` is why there is a value object here at all
 * rather than an array built at a call site: an array can grow a key.
 *
 * **Everything here comes off the form the buyer filled in**, and only the
 * brand may be missing. The bin is the first six digits of the number they
 * typed and the expiry is the month and year they typed, so both are known
 * whatever scheme the card belongs to. The payment provider's own reading of
 * the card is consulted for the brand alone: a CRM changes its response shape
 * and sometimes omits a field it documents, so it answers the one question the
 * storefront genuinely cannot and is never asked for a value already in hand.
 *
 * **An underivable brand omits `type` and keeps the rest.** The API documents
 * `type` as required inside `card`, and this sends the object without it rather
 * than discarding a bin and an expiry that are not in doubt. If a live call
 * rejects that, the refusal belongs in `docs/INTEGRATION-NOTES.md` and the
 * answer is to omit the whole `card` object — not to name a brand nothing
 * established.
 */
final class CardDescriptor
{
    /** The digits that name an issuer rather than an account. */
    private const int BIN_LENGTH = 6;

    private function __construct(
        public readonly ?CardBrand $brand,
        public readonly ?string $bin,
        public readonly string $expiry,
    ) {
    }

    /**
     * @param ?CardBrand $fallback the payment provider's own reading of the card's
     *                             scheme, used only where the number's prefix has no
     *                             answer; it is never consulted for the bin or the
     *                             expiry, which the buyer supplied directly
     */
    public static function fromCredential(PaymentCredential $credential, ?CardBrand $fallback = null): self
    {
        $digits = preg_replace('/\D/', '', $credential->number) ?? '';

        return new self(
            CardBrand::fromNumber($credential->number) ?? $fallback,
            strlen($digits) >= self::BIN_LENGTH ? substr($digits, 0, self::BIN_LENGTH) : null,
            self::expiry($credential),
        );
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        $card = [];

        if ($this->brand !== null) {
            $card['type'] = $this->brand->value;
        }

        if ($this->bin !== null) {
            $card['bin'] = $this->bin;
        }

        $card['exp'] = $this->expiry;

        return $card;
    }

    /**
     * `MM/YY`, which is the form the API's own examples carry and the form one
     * of the two providers already answers in.
     *
     * The year is reduced to its last two digits because both are stored: a
     * credential minted from the checkout form holds four and one read back
     * from a provider handle may hold two.
     */
    private static function expiry(PaymentCredential $credential): string
    {
        $month = str_pad(preg_replace('/\D/', '', $credential->expiryMonth) ?? '', 2, '0', STR_PAD_LEFT);
        $year = preg_replace('/\D/', '', $credential->expiryYear) ?? '';

        return substr($month, -2) . '/' . substr(str_pad($year, 2, '0', STR_PAD_LEFT), -2);
    }
}
