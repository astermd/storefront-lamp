<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment;

/**
 * The provider charged a different number from the one the buyer agreed to.
 *
 * Two authorities compute the total of an order -- the storefront, which
 * displays it, and the provider, which charges it -- and `[14.6b]` gives the
 * provider the arithmetic precisely because two authorities can disagree. What
 * was missing was anyone checking whether they had. A discount asked for on
 * the wrong lines, a price the provider took from its own item catalog, a
 * promotional profile attached campaign-side: each of those charges a figure
 * the buyer never saw, and each of them looked like a plain success.
 *
 * Any provider-side amount the storefront does not display lands here, and
 * that is the point rather than a false positive: a shipping profile that
 * charges, a campaign-level promotional profile, a catalog price that moved.
 * The buyer agreed to the number they were shown, and every route to a
 * different one is worth an operator's attention.
 *
 * This is that check's answer, attached to the outcome so the layer that
 * records the order can see it. It is deliberately not a decline: by the time
 * a discrepancy can be measured the card has been charged, and refusing to
 * record a charge that happened is the one outcome worse than charging the
 * wrong amount.
 */
final class ChargeDiscrepancy
{
    /**
     * @param int      $expectedCents          what the storefront displayed and the buyer agreed to
     * @param int      $chargedCents           what the provider says it took
     * @param int|null $providerDiscountCents  the provider's own order-level discount, when it reported one; the
     *                                         field that usually explains the gap
     */
    public function __construct(
        public readonly int $expectedCents,
        public readonly int $chargedCents,
        public readonly ?int $providerDiscountCents = null,
    ) {
    }

    /** Positive when the buyer was overcharged, negative when they were undercharged. */
    public function differenceCents(): int
    {
        return $this->chargedCents - $this->expectedCents;
    }
}
