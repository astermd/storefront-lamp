<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment;

/**
 * One line of a neutral order, as the funnel sees it.
 *
 * `$providerOffer` and `$providerItem` are opaque strings the catalog resolved
 * from the channel's variant mapping (`[14.1]` capability 3). Both may be
 * null: the recorded channel gives free attachments no mapping at all, and a
 * product with no variants has no `product_mappings` either. A line with no
 * provider identity is dropped from the charge and logged (`[13.19]` cannot be
 * honoured for something the provider has never heard of) -- but only when it
 * is free. A priced line with no mapping stops checkout, because dropping it
 * would undercharge.
 */
final class OrderLine
{
    /**
     * @param string $kind the catalog's own kind for this line -- `rx` or `otc`. Defaulted
     *                     because a line's kind is the catalog's fact and not every caller
     *                     has one to hand; a line whose kind is unknown is recorded as the
     *                     unrestricted case, which is the reading that never over-claims a
     *                     prescription.
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly ?string $providerOffer,
        public readonly ?string $providerItem,
        public readonly int $unitPriceCents,
        public readonly int $quantity,
        public readonly string $kind = 'otc',
    ) {
    }

    public function lineTotalCents(): int
    {
        return $this->unitPriceCents * $this->quantity;
    }

    public function isFree(): bool
    {
        return $this->unitPriceCents === 0;
    }

    /** Whether the provider can be told about this line at all. */
    public function isChargeable(): bool
    {
        return $this->providerOffer !== null && $this->providerOffer !== ''
            && $this->providerItem !== null && $this->providerItem !== '';
    }
}
