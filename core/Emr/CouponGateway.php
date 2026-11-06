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
     * Prices a code against the lines it would discount, for the shopper
     * buying them.
     *
     * A coupon can be limited to one patient, or to so many uses per patient,
     * and the EMR checks that against whoever it can tell is buying: the
     * email they gave, or else the opportunity their journey opened. A shopper
     * it cannot name fits only a coupon open to anyone.
     *
     * An accepted quote carries the coupon's id as its reference, which is
     * what the order names the discount by.
     *
     * `$email` names the buyer so a coupon restricted to particular patients
     * can match them. Without it the buyer is anonymous, and only a coupon
     * open to anyone applies — so a code is never refused for want of an
     * email the buyer has not typed yet; it is priced as anyone's.
     *
     * @param list<OrderLine> $lines
     * @param ?string         $email the buyer's email, or null when the journey holds none
     */
    public function quote(string $code, array $lines, ?string $email = null, ?string $opportunityId = null): PromotionQuote;

    /** Whether codes can be priced at all, so the storefront hides the control rather than refusing every code. */
    public function available(): bool;
}
