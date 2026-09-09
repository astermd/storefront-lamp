<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Catalog;

/**
 * Pure transform: layers a client-owned overrides file on top of the
 * generated catalog (see {@see CatalogBuilder}). No I/O — `merge()` is a
 * deterministic function of its two array arguments, which makes it
 * exhaustively unit-testable.
 *
 * Override file shape (`config/products.overrides.php`):
 * `['products' => ['<emr_product_id>' => [ ...fields to override..., 'slug' => '<new-slug>', 'variants' => ['<variant_id>' => [...fields]] ]]]`.
 *
 * Matching rule [2.2]: products are matched by `emr_product_id`, NEVER by
 * slug or name — slugs are allowed to change (see the `slug` re-key below),
 * so they can't be the join key. A generated product whose `emr_product_id`
 * is null (e.g. the sample seed) can never be matched by an override.
 *
 * Field semantics: every field in an override (other than `variants`)
 * replaces the generated field wholesale — no deep merge, e.g. an overridden
 * `categories` list replaces the whole list rather than merging entries,
 * which keeps the result predictable. An explicit `slug` field both re-keys
 * the product in the returned map AND updates its own `slug` field; every
 * other product is left untouched [2.4].
 *
 * `variants` overrides [2.5] apply PER variant id: fields merge into the
 * matching variant, variants not mentioned are left untouched, and none are
 * dropped or reordered.
 *
 * An override id that matches no generated product's `emr_product_id` is
 * either:
 *  - a full net-new product definition (has at minimum `slug`, `name`,
 *    `kind`, and `variants`) -> added to the catalog as-is; or
 *  - anything else (a partial override with no home) -> silently skipped
 *    here (no exception, no error field on the return value — validation
 *    reports data-integrity problems elsewhere, later in the sync
 *    pipeline). The signature stays `merge(): array` returning the merged
 *    catalog ONLY (not a tuple/wrapper), so instead of smuggling a second
 *    return channel through the return type, this is an instance class
 *    (not static, unlike {@see CatalogBuilder}) that remembers what it
 *    ignored: call {@see self::lastIgnoredOverrideIds()} right after
 *    `merge()` to retrieve them. The same channel also collects override
 *    variant ids that don't exist on their product, formatted as
 *    `'<emr_product_id>:<variant_id>'`.
 *
 * Slug collisions are NOT silently swallowed like unmatched partial
 * overrides are: every write into the merged product map goes through
 * {@see self::insertProduct()}, which throws `\RuntimeException` (naming the
 * slug and both colliding EMR product ids) the moment a re-key or a net-new
 * product's `slug` lands on a slug some other product already occupies —
 * mirroring {@see CatalogBuilder}'s own slug-collision guard convention,
 * since silently overwriting one product with another here would be the
 * same class of catalog data loss.
 */
final class CatalogMerger
{
    /** @var array<int, string> */
    private array $lastIgnoredOverrideIds = [];

    /**
     * @param array<string, mixed> $generated the `products.generated` config (channel + products)
     * @param array<string, mixed> $overrides the `products.overrides` config (`['products' => [...]]`)
     * @return array<string, mixed> the merged catalog, same top-level shape as $generated
     */
    public function merge(array $generated, array $overrides): array
    {
        $this->lastIgnoredOverrideIds = [];

        $products = is_array($generated['products'] ?? null) ? $generated['products'] : [];
        $overrideProducts = is_array($overrides['products'] ?? null) ? $overrides['products'] : [];

        /** @var array<string, true> $matchedOverrideIds tracks which override entries were consumed by an existing product */
        $matchedOverrideIds = [];
        $merged = [];

        foreach ($products as $slug => $product) {
            $slug = (string) $slug;

            if (!is_array($product)) {
                $merged[$slug] = $product;
                continue;
            }

            $emrId = $product['emr_product_id'] ?? null;
            $overrideId = $emrId !== null ? (string) $emrId : null;

            if ($overrideId === null || !is_array($overrideProducts[$overrideId] ?? null)) {
                $this->insertProduct($merged, $slug, $product, $emrId);
                continue;
            }

            $matchedOverrideIds[$overrideId] = true;
            $updated = $this->applyProductOverride($product, $overrideProducts[$overrideId], $overrideId);
            $newSlug = isset($updated['slug']) ? (string) $updated['slug'] : $slug;
            $this->insertProduct($merged, $newSlug, $updated, $overrideId);
        }

        foreach ($overrideProducts as $overrideId => $override) {
            $overrideId = (string) $overrideId;

            if (isset($matchedOverrideIds[$overrideId])) {
                continue;
            }

            if (is_array($override) && self::isFullProductDefinition($override)) {
                $this->insertProduct($merged, (string) $override['slug'], $override, $overrideId);
                continue;
            }

            $this->lastIgnoredOverrideIds[] = $overrideId;
        }

        $result = $generated;
        $result['products'] = $merged;

        return $result;
    }

    /**
     * Override ids (product ids and `'<productId>:<variantId>'` pairs) that
     * the most recent {@see self::merge()} call could not apply — either a
     * product override that matched no generated `emr_product_id` and wasn't
     * a full product definition, or a variant override whose variant id
     * doesn't exist on its (matched) product. Reset at the start of every
     * `merge()` call.
     *
     * @return array<int, string>
     */
    public function lastIgnoredOverrideIds(): array
    {
        return $this->lastIgnoredOverrideIds;
    }

    /**
     * Writes $product into $merged under $slug — unless $slug is already
     * occupied, in which case it's a collision between two DIFFERENT
     * products (the plain pass-through path only ever writes each generated
     * product's own already-unique slug once, so a pre-existing entry here
     * can only be the result of an override re-key or net-new `slug`
     * landing on a slug something else already claimed). Silently
     * overwriting would discard the earlier product with no trace, so this
     * fails loudly instead, naming the slug and both colliding EMR product
     * ids — the same convention {@see CatalogBuilder} uses for its own
     * slug-collision guard.
     *
     * @param array<string, mixed> $merged
     * @param array<string, mixed> $product
     */
    private function insertProduct(array &$merged, string $slug, array $product, mixed $emrProductId): void
    {
        if (isset($merged[$slug])) {
            throw new \RuntimeException(sprintf(
                'CatalogMerger: slug collision "%s" between EMR product ids "%s" and "%s"',
                $slug,
                (string) ($merged[$slug]['emr_product_id'] ?? ''),
                (string) $emrProductId,
            ));
        }

        $merged[$slug] = $product;
    }

    /**
     * Applies one matched product's override: every field but `variants`
     * replaces the generated field wholesale; `variants` (if present) is
     * merged per-variant-id via {@see self::mergeVariants()}.
     *
     * @param array<string, mixed> $product
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function applyProductOverride(array $product, array $override, string $overrideId): array
    {
        $variantOverrides = is_array($override['variants'] ?? null) ? $override['variants'] : null;
        unset($override['variants']);

        $merged = array_replace($product, $override);

        if ($variantOverrides !== null) {
            $variants = is_array($product['variants'] ?? null) ? $product['variants'] : [];
            $merged['variants'] = $this->mergeVariants($variants, $variantOverrides, $overrideId);
        }

        return $merged;
    }

    /**
     * Merges `variants` overrides (keyed by variant id) into $variants
     * (a list, in generated order). Every variant not mentioned is copied
     * through unchanged; order and count are always preserved. A variant id
     * in $overrides with no matching entry in $variants is recorded in
     * {@see self::$lastIgnoredOverrideIds} as `'<productOverrideId>:<variantId>'`.
     *
     * Note: $overrides is expected to be keyed BY VARIANT ID (a map), per the
     * documented override shape. If a caller instead passes a plain list
     * (sequential int keys `0, 1, 2, ...`), those integers are treated as
     * the "variant ids" to match against — which will essentially never hit
     * a real variant id, so every entry in a list-shaped `variants` override
     * typically ends up ignored, reported as `'<productOverrideId>:0'`,
     * `'<productOverrideId>:1'`, etc. This isn't special-cased because it's
     * not a distinct failure mode: it's the same "unmatched variant id"
     * path, just with numeric ids, so the existing ignored-ids channel
     * already surfaces the mistake without extra code.
     *
     * @param array<int, mixed> $variants
     * @param array<string, mixed> $overrides
     * @return array<int, mixed>
     */
    private function mergeVariants(array $variants, array $overrides, string $productOverrideId): array
    {
        $indexByVariantId = [];
        foreach ($variants as $index => $variant) {
            if (is_array($variant) && isset($variant['id'])) {
                $indexByVariantId[(string) $variant['id']] = $index;
            }
        }

        $result = $variants;

        foreach ($overrides as $variantId => $fields) {
            $variantId = (string) $variantId;

            if (!isset($indexByVariantId[$variantId]) || !is_array($fields)) {
                $this->lastIgnoredOverrideIds[] = $productOverrideId . ':' . $variantId;
                continue;
            }

            $index = $indexByVariantId[$variantId];
            $result[$index] = array_replace($result[$index], $fields);
        }

        return $result;
    }

    /**
     * An unmatched override is only safe to add as a net-new product if it
     * carries a full product definition, i.e. at minimum a `slug`, `name`,
     * `kind`, and `variants` list — anything less is a partial override with
     * no product to attach to.
     *
     * @param array<string, mixed> $override
     */
    private static function isFullProductDefinition(array $override): bool
    {
        return isset($override['slug'], $override['name'], $override['kind'])
            && is_array($override['variants'] ?? null);
    }
}
