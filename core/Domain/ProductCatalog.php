<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Domain;

/**
 * The single read the cart rules make against the catalog.
 *
 * Declared here rather than depended on directly so the domain layer stays
 * free of I/O and of the config cascade: the rules are exercised against an
 * in-memory implementation in their own tests, and
 * {@see \AsterMD\Storefront\Catalog\CatalogProvider} satisfies it in
 * production without either side knowing about the other.
 */
interface ProductCatalog
{
    /** @return array<string, mixed>|null the merged product for $slug, or null when no product has that slug */
    public function product(string $slug): ?array;
}
