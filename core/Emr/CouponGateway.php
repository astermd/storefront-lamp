<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Emr;

use AsterMD\Storefront\Payment\OrderLine;
use AsterMD\Storefront\Payment\PromotionQuote;

/**
 * Discount codes priced by the EMR's own coupons, for a payment provider that
 * has no discount codes of its own (`[13.11]`, `[14.6b]`).
 *
 * A gateway such as Stripe takes an amount and nothing else, so the code a
 * buyer types is the EMR's to price: it knows the coupon's terms, its limits
 * and the plan it may discount, and the order it reads back names the coupon
 * by its id. The adapter for such a provider asks this; an adapter whose
 * provider prices codes itself never does.
 *
 * Like every port here, a failure to reach the EMR is a rejected quote rather
 * than an error (`[13.14]`): a code that cannot be priced right now is one the
 * buyer is told did not apply, never an error page on the way to paying.
 */
interface CouponGateway
{
    /**
     * Prices a code against the lines it would discount.
     *
     * An accepted quote carries the coupon's id as its reference, which is
     * what the order names the discount by.
     *
     * @param list<OrderLine> $lines
     */
    public function quote(string $code, array $lines): PromotionQuote;

    /** Whether codes can be priced at all, so the storefront hides the control rather than refusing every code. */
    public function available(): bool;
}
