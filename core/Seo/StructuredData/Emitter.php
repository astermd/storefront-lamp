<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Seo\StructuredData;

/**
 * One schema.org node, built from catalog and configuration data (`[24.8]`).
 *
 * There is an emitter per type rather than one class that switches on a type
 * string, because each type answers to a different rule: the offer inside a
 * {@see Product} has to reflect real state (`[24.9]`), a prescription product
 * has to be able to publish nothing at all (`[24.10]`), and the site-level
 * nodes have neither constraint. Keeping them apart is what lets each carry
 * its own reason for existing — and what lets {@see StructuredData} validate
 * the whole set uniformly at deploy time (`[24.11]`).
 */
interface Emitter
{
    /**
     * The node, or null when this deployment publishes nothing for it.
     *
     * Null rather than an empty array because "nothing to say" and "an empty
     * object" are different claims to a crawler, and `[24.10]` requires the
     * first of them to be expressible.
     *
     * @return array<string, mixed>|null
     */
    public function emit(): ?array;
}
