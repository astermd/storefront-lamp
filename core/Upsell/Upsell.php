<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Upsell;

/**
 * One post-purchase offer, fully resolved against the catalog (`[16.4]`).
 *
 * Immutable and complete on purpose: it is built at the moment the offer is
 * about to be rendered or charged, and both the page and the provider payload
 * read it without going back to configuration or the catalog. That is what
 * makes an offer the buyer saw and the order that was placed for it agree —
 * `$priceCents` is the *decided* number (the configured override, else the
 * named variant's, else the product's), not a hint to be recomputed later.
 *
 * `$name` and `$kind` come from the catalog rather than from configuration, so
 * the EMR record and the order line describe the same product the catalog
 * does. `$providerOffer` and `$providerItem` may both be null — a product with
 * no variants has no mapping — and an offer that cannot be charged for is
 * still representable here, because refusing it is
 * {@see \AsterMD\Storefront\Console\ValidateCommand}'s job at configuration
 * time and the charge's job at runtime, not this object's.
 */
final class Upsell
{
    /**
     * @param list<string> $bullets
     * @param ?string      $footnote the small print under the two buttons, or null for none.
     *
     * **Configured rather than written into the page, because the storefront cannot know what it
     * says.** Whether an accepted upsell recurs is a property of the provider offer it maps to,
     * not of this application: the offer this channel maps every product to comes back
     * `is_recurring: true` with a `next_recurring_amount`, so a charge through it bills again next
     * month. The design mockup printed "Cancel anytime", which promises a cancellation surface
     * this storefront does not have; the obvious correction to "One-time charge" is worse, being
     * simply false against that offer. Both are billing promises, and only the operator who
     * mapped the offer knows which is true — so the default is silence, and a deployment that
     * owes a disclosure writes the one its own offer earns.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $slug,
        public readonly ?string $variantId,
        public readonly string $eyebrow,
        public readonly string $headline,
        public readonly string $body,
        public readonly array $bullets,
        public readonly ?string $image,
        public readonly string $acceptLabel,
        public readonly string $declineLabel,
        public readonly ?string $footnote,
        public readonly string $name,
        public readonly int $priceCents,
        public readonly ?string $providerOffer,
        public readonly ?string $providerItem,
        public readonly string $kind,
    ) {
    }
}
