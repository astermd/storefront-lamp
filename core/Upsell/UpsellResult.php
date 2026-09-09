<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Upsell;

use AsterMD\Storefront\Payment\PlacementOutcome;

/**
 * What answering one offer amounts to: somewhere to go, and at most something
 * to say.
 *
 * **`$redirectTo` is not nullable, and that is the type carrying `[16.11]`.**
 * Its counterpart {@see \AsterMD\Storefront\Checkout\CheckoutResult} has a
 * nullable `redirectTo`, because at checkout a decline means the buyer stays on
 * the page and tries again. Here they have already paid for what they came
 * for, so every path — an approval, a decline, a refused handle, a journey with
 * no credential at all — ends somewhere further forward. A future revision that
 * wanted to trap the buyer on an add-on would have to widen this type first.
 *
 * `$notice` is therefore for the two things the buyer can act on — a flood
 * refusal and a charge that may still be in flight — and for nothing else. A
 * declined add-on carries none: the order they completed is unaffected, and
 * telling them a charge failed invites them to go and fix something that is
 * not broken.
 *
 * `$outcome` is what the provider said, for the caller that wants to log or
 * report it. Null on every path that never reached a provider, which is most
 * of them.
 */
final class UpsellResult
{
    public function __construct(
        public readonly string $redirectTo,
        public readonly ?string $notice = null,
        public readonly ?PlacementOutcome $outcome = null,
    ) {
    }
}
