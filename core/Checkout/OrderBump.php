<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Checkout;

/**
 * One offer on the checkout page (`[27.5]`).
 *
 * `$priceCentsOverride` is null when the catalog price stands. When it is set
 * it applies to that line only and does not alter the catalog (`[27.12]`) --
 * the reduced price is the entire persuasive mechanism, and a bump that
 * silently repriced the product everywhere would change the treatments page
 * too.
 */
final class OrderBump
{
    public function __construct(
        public readonly string $key,
        public readonly string $slug,
        public readonly ?string $variantId,
        public readonly string $headline,
        public readonly string $body,
        public readonly int $position,
        public readonly ?int $priceCentsOverride,
        public readonly string $name,
        public readonly int $catalogPriceCents,
    ) {
    }

    /** What the buyer is charged if they accept. */
    public function priceCents(): int
    {
        return $this->priceCentsOverride ?? $this->catalogPriceCents;
    }
}
