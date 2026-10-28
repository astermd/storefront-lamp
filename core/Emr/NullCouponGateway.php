<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Emr;

use AsterMD\Storefront\Payment\PromotionQuote;

/**
 * No coupons: bound under the test suite, so no run reaches the EMR, and the
 * answer a provider that depends on this gives when it cannot price codes.
 *
 * Unavailable rather than refusing each code, so the promo control is hidden
 * instead of shown and then refused (`[13.18]`).
 */
final class NullCouponGateway implements CouponGateway
{
    public function quote(string $code, array $lines): PromotionQuote
    {
        return PromotionQuote::rejected($code, 'unsupported');
    }

    public function available(): bool
    {
        return false;
    }
}
