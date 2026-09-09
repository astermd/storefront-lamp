<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment;

/**
 * The provider's answer about a discount code (`[13.8]`'s two phases in one
 * value): whether it applies to at least one line, and what it is worth
 * across all of them, in integer cents.
 *
 * `$reason` distinguishes an empty code from an invalid one (`[13.9]`). The
 * recorded provider already makes that distinction itself -- an offer-less
 * request answers `offer_invalid` and a bad code answers `discount_invalid` --
 * so the adapter maps rather than invents.
 */
final class PromotionQuote
{
    private function __construct(
        public readonly bool $valid,
        public readonly string $code,
        public readonly int $discountCents,
        public readonly ?string $reason,
    ) {
    }

    public static function accepted(string $code, int $discountCents): self
    {
        return new self(true, $code, max(0, $discountCents), null);
    }

    public static function rejected(string $code, string $reason): self
    {
        return new self(false, $code, 0, $reason);
    }
}
