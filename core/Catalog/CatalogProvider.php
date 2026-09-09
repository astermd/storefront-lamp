<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Catalog;

use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Support\Config;

/**
 * The single runtime entry point onto the catalog: layers
 * `config/products.overrides.php` on top of `config/products.generated.php`
 * (via {@see CatalogMerger}) and memoizes the result for the lifetime of the
 * instance, so a container singleton merges once per request no matter how
 * many controllers/templates ask for products.
 *
 * Both config files are read leniently: the sample seed ships with no
 * top-level `channel` key and products that may have no `variants` key at
 * all (single-SKU products) — {@see CatalogMerger} already tolerates both,
 * and this class defaults `products.overrides` to `['products' => []]` when
 * the file is absent or empty, so an empty/missing overrides file is a
 * no-op layer.
 *
 * Also satisfies {@see ProductCatalog}, the domain layer's read port onto
 * the catalog, so {@see \AsterMD\Storefront\Domain\CartRules} can resolve
 * products in production without depending on this class directly.
 */
final class CatalogProvider implements ProductCatalog
{
    /** @var array<string, mixed>|null */
    private ?array $catalog = null;

    /** @var array<int, string> ignored override ids from the merge that produced the memoized catalog; empty until {@see self::catalog()} has run at least once */
    private array $lastIgnoredOverrideIds = [];

    public function __construct(private readonly Config $config)
    {
    }

    /** @return array<string, mixed> the merged catalog (`channel` + `products`, generated shape) */
    public function catalog(): array
    {
        if ($this->catalog === null) {
            $generated = (array) $this->config->get('products.generated', []);
            $overrides = (array) $this->config->get('products.overrides', ['products' => []]);

            $merger = new CatalogMerger();
            $this->catalog = $merger->merge($generated, $overrides);
            $this->lastIgnoredOverrideIds = $merger->lastIgnoredOverrideIds();
        }

        return $this->catalog;
    }

    /**
     * Override ids the merge behind the memoized catalog couldn't apply
     * (see {@see CatalogMerger::lastIgnoredOverrideIds()} for what lands
     * here). Empty before {@see self::catalog()} has been called at least
     * once, since nothing has been merged yet.
     *
     * @return array<int, string>
     */
    public function lastIgnoredOverrideIds(): array
    {
        return $this->lastIgnoredOverrideIds;
    }

    /** @return array<int, array<string, mixed>> every merged product, in catalog order */
    public function products(): array
    {
        $products = $this->catalog()['products'] ?? [];

        return array_values(is_array($products) ? $products : []);
    }

    /**
     * `[6.1] EXISTING` — The product listing shows only `rx` and `otc`
     * products. Lab products and free add-ons are nested/standalone catalog
     * entries created by {@see CatalogBuilder} for cart/checkout purposes —
     * they are never listed on their own and reach the cart only by being
     * attached to a listed product. This is the source every listing surface
     * (treatments grid, home page featured products, footer links) must use
     * instead of {@see self::products()}; {@see self::product()} is
     * unaffected, since a direct-slug detail view of a nested product is
     * harmless.
     *
     * @return array<int, array<string, mixed>> listed products only, in catalog order
     */
    public function listedProducts(): array
    {
        return array_values(array_filter(
            $this->products(),
            static fn (array $product): bool => in_array($product['kind'] ?? null, ['rx', 'otc'], true),
        ));
    }

    /** @return array<string, mixed>|null the merged product for $slug, or null when no product has that slug */
    public function product(string $slug): ?array
    {
        $products = $this->catalog()['products'] ?? [];
        $product = is_array($products) ? ($products[$slug] ?? null) : null;

        return is_array($product) ? $product : null;
    }

    /**
     * @return array<int, string> sorted, deduplicated category names across
     *     every listed product ({@see self::listedProducts()}) — the
     *     treatments page's category filter must never surface a category
     *     that only exists on a lab/free-addon product `[6.1]`.
     */
    public function categories(): array
    {
        $categories = [];
        foreach ($this->listedProducts() as $product) {
            foreach ((array) ($product['categories'] ?? []) as $category) {
                $categories[(string) $category] = true;
            }
        }

        $names = array_keys($categories);
        sort($names);

        return $names;
    }
}
