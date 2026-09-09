<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Checkout;

/**
 * Subtotal − discount, clamped, in integer cents (`[13.12]`).
 *
 * There are no shipping or tax lines: this theme prices all-inclusive
 * (`[13.35]` is a declared gap, not an omission), so the total is exactly two
 * numbers. Every place that computes a total -- the order summary, the funnel
 * event, the provider payload, the stored order row -- goes through here, so
 * the clamp cannot be applied in three places and forgotten in a fourth.
 *
 * The clamp caps the *discount*, not just the total, so a summary showing
 * "−$99.99" against a $50.00 subtotal is impossible: the buyer sees the
 * discount that was actually applied.
 *
 * The clamp is the last line rather than the first: it guards what is
 * *rendered* and cannot reach the charge, because the provider payload carries
 * line prices and a code but no order total, so a clamped display sits beside
 * whatever the provider's own arithmetic decides to take.
 * {@see CheckoutService} therefore refuses a quote larger than the cart
 * outright, and this clamp is what still holds for a figure that reached here
 * by some other route — a promotion stored by an older shape, say. A total of
 * zero here means a discount that genuinely met the subtotal, not one that
 * exceeded it and was quietly trimmed.
 *
 * {@see self::dollars()} and {@see self::decimalString()} are the only exits
 * from integer cents in this codebase. Both are named, both are tested, and
 * neither is inlined anywhere -- the EMR accepts a cents integer where it
 * documents a float dollar amount without complaining, so a skipped
 * conversion is invisible everywhere except in a test.
 */
final class Totals
{
    private function __construct(
        public readonly int $subtotalCents,
        public readonly int $discountCents,
        public readonly int $totalCents,
    ) {
    }

    public static function of(int $subtotalCents, ?Promotion $promotion): self
    {
        $subtotal = max(0, $subtotalCents);
        $discount = min($subtotal, max(0, $promotion?->discountCents ?? 0));

        return new self($subtotal, $discount, $subtotal - $discount);
    }

    /** Integer cents → a float dollar amount, for the one API that documents floats. */
    public static function dollars(int $cents): float
    {
        return round($cents / 100, 2);
    }

    /** Integer cents → a fixed two-place decimal string, for the provider payload. */
    public static function decimalString(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
