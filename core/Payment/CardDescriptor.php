<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment;

/**
 * The card sub-object of the API's payment block: what the storefront may say
 * about the instrument, and no more.
 *
 * **Never the number.** The last four digits are the whole of the number that
 * leaves the storefront — the same four a receipt prints and the `orders` row
 * already holds. `[15.8]` is why there is a value object here at all rather
 * than an array built at a call site: an array can grow a key.
 *
 * **Everything here comes off the form the buyer filled in**, and only the
 * brand may be missing. The last four are the tail of the number they typed
 * and the expiry is the month and year they typed, so both are known whatever
 * scheme the card belongs to. The payment provider's own reading of the card
 * is consulted for the brand alone: a CRM changes its response shape and
 * sometimes omits a field it documents, so it answers the one question the
 * storefront genuinely cannot and is never asked for a value already in hand.
 * The one exception is a token collected in the provider's own fields: the
 * storefront never saw that card, so it takes the last four and the expiry
 * from the provider.
 *
 * **An underivable brand omits `type` and keeps the rest.** The API documents
 * `type` as required inside `card`, and this sends the object without it rather
 * than discarding a last four and an expiry that are not in doubt. If a live
 * call rejects that, the refusal belongs in `docs/INTEGRATION-NOTES.md` and the
 * answer is to omit the whole `card` object — not to name a brand nothing
 * established.
 */
final class CardDescriptor
{
    /** The most of the account number a receipt, an order row or the API may carry. */
    private const int LAST_FOUR_LENGTH = 4;

    private function __construct(
        public readonly ?CardBrand $brand,
        public readonly ?string $lastFour,
        public readonly string $expiry,
    ) {
    }

    /**
     * @param ?CardBrand $fallback         the payment provider's own reading of the card's
     *                                     scheme, used only where the number's prefix has
     *                                     no answer; it is never consulted for the last
     *                                     four or the expiry, which the buyer supplied
     * @param ?string    $fallbackLastFour the provider's last four, used only where the
     *                                     credential carries no number of its own
     */
    public static function fromCredential(
        PaymentCredential $credential,
        ?CardBrand $fallback = null,
        ?string $fallbackExpiry = null,
        ?string $fallbackLastFour = null,
    ): self {
        $digits = preg_replace('/\D/', '', $credential->number) ?? '';

        // A token collected in the provider's own fields carries no expiry, and
        // rendering it as `00/00` would assert a date nobody supplied. The
        // provider's reading is the only one there is.
        $expiry = $credential->expiryMonth === '' && $fallbackExpiry !== null && $fallbackExpiry !== ''
            ? $fallbackExpiry
            : self::expiry($credential);

        return new self(
            CardBrand::fromNumber($credential->number) ?? $fallback,
            strlen($digits) >= self::LAST_FOUR_LENGTH
                ? substr($digits, -self::LAST_FOUR_LENGTH)
                : self::lastFour($fallbackLastFour),
            $expiry,
        );
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        $card = [];

        if ($this->brand !== null) {
            $card['type'] = $this->brand->value;
        }

        if ($this->lastFour !== null) {
            $card['last_4'] = $this->lastFour;
        }

        $card['exp'] = $this->expiry;

        return $card;
    }

    /**
     * Exactly four digits or nothing: a provider answer of any other shape is
     * not a last four, and sending it would put a guess beside the brand.
     */
    private static function lastFour(?string $candidate): ?string
    {
        return $candidate !== null && preg_match('/^\d{4}$/', $candidate) === 1 ? $candidate : null;
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
