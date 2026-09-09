<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Catalog;

/**
 * Pure transform: the `data` subtree of an EMR Channel Details response ->
 * the generated catalog every later step of the pipeline (rendering, provider
 * resolution, validation) consumes. No I/O, no SDK types — `build()` is a
 * deterministic function of its input, which makes the whole class
 * exhaustively unit-testable against the fixture without any test doubles.
 *
 * A static method is used (rather than an instance) because the class holds
 * no state between calls — every rule below reads only its arguments.
 *
 * Bracketed numbers ([2.10], [2.12]-[2.15], [2.18], ...) are behaviour-rule
 * ids, resolved in `docs/SPEC-REFERENCE.md` under "2. Configuration and the
 * catalog model". Every rule below implements one of them.
 */
final class CatalogBuilder
{
    private const string PLACEHOLDER_IMAGE = '/assets/img/product-placeholder.svg';

    private const string PRICE_UNIT = '/ month';

    /**
     * @param array<string, mixed> $channelData the `data` subtree of a Channel Details response
     * @return array{
     *     channel: array{id: mixed, name: mixed, currency: string},
     *     products: array<string, array<string, mixed>>,
     * }
     */
    public static function build(array $channelData, string $currency = 'USD'): array
    {
        /** @var array<string, array<string, mixed>> $products keyed by slug, in first-seen order */
        $products = [];

        foreach ($channelData['products'] ?? [] as $entry) {
            $product = is_array($entry['product'] ?? null) ? $entry['product'] : [];

            // Rule 11 [visibility gate]: only channel products that are both
            // active (`status === 1`) and public are surfaced in the
            // generated catalog. Nested lab/add-on children are NOT
            // re-checked here — as long as their parent survives, they are
            // kept regardless of their own status/visibility fields.
            if (($product['status'] ?? null) !== 1 || ($product['visibility'] ?? null) !== 'Public') {
                continue;
            }

            $mappings = is_array($entry['product_mappings'] ?? null) ? $entry['product_mappings'] : [];

            // Rule 6 [2.12, 2.14]: every labtest entry becomes a standalone
            // `lab` product; the same nested `_id` referenced by more than
            // one parent is emitted once (checked via slug membership in
            // self::addNestedProduct), and every parent that references it
            // records its slug in `bundles`.
            $bundles = [];
            foreach ($product['labtest'] ?? [] as $lab) {
                if (is_array($lab)) {
                    $bundles[] = self::addNestedProduct($products, $lab, 'lab');
                }
            }

            // Rule 7 [2.13]: every add_ons entry becomes a standalone
            // `free-addon` product, recorded in the parent's `attachments`.
            $attachments = [];
            foreach ($entry['add_ons'] ?? [] as $addOn) {
                if (is_array($addOn)) {
                    $attachments[] = self::addNestedProduct($products, $addOn, 'free-addon');
                }
            }

            $slug = self::makeSlug((string) $product['_id'], (string) $product['name']);

            // Unlike addNestedProduct's intentional dedupe (rule 6/7, same
            // shared lab/add-on referenced by multiple parents -> one
            // product), two DIFFERENT top-level EMR products colliding on
            // the same slug is a data-integrity problem, not a legitimate
            // dedupe: silently overwriting one with the other is catalog
            // data loss. Fail loudly, naming both colliding EMR product ids,
            // so the operator can re-key via overrides at sync time.
            if (isset($products[$slug])) {
                throw new \RuntimeException(sprintf(
                    'CatalogBuilder: slug collision "%s" between EMR product ids "%s" and "%s"',
                    $slug,
                    (string) $products[$slug]['emr_product_id'],
                    (string) $product['_id'],
                ));
            }

            $variants = self::buildVariants($product['variants'] ?? [], $product, $mappings);

            $products[$slug] = [
                'slug' => $slug,
                'name' => $product['name'],
                'subtitle' => null,
                // Rule 2: EMR `type` of `prescription` -> `rx`; every other
                // type (`standard`, etc.) -> `otc`.
                'kind' => self::kindOf($product['type'] ?? null),
                'categories' => self::namesOf($product['categories'] ?? []),
                'condition_treated' => self::namesOf($product['condition_treated'] ?? []),
                'description' => $product['description_long'] ?? $product['description_short'] ?? '',
                // Product-level price mirrors the first variant's price.
                'price_cents' => $variants[0]['price_cents'] ?? 0,
                'price_unit' => self::PRICE_UNIT,
                'image' => self::PLACEHOLDER_IMAGE,
                'gallery' => [self::PLACEHOLDER_IMAGE],
                'badges' => [],
                'emr_product_id' => $product['_id'],
                'remote_image' => $product['image'] ?? null,
                // Rule 8: first teleform's id, or null when none configured.
                'teleform_id' => $product['teleforms'][0]['_id'] ?? null,
                'max_buy_qty' => $product['max_buy_qty'] ?? null,
                'min_buy_qty' => $product['min_buy_qty'] ?? null,
                'restrict_multiple' => (bool) ($product['restrict_multiple'] ?? false),
                'variants' => $variants,
                'bundles' => $bundles,
                'attachments' => $attachments,
            ];
        }

        return [
            'channel' => [
                'id' => $channelData['_id'] ?? null,
                'name' => $channelData['name'] ?? null,
                'currency' => $currency,
            ],
            'products' => $products,
        ];
    }

    /**
     * Builds a nested labtest/add_ons entry into a standalone product and
     * registers it in the shared `$products` map, deduping by slug (which is
     * a deterministic function of the source `_id` + name, so the same
     * entity referenced twice always maps to the same slug) [2.14]. Nested
     * entities have no `product_mappings` of their own, so every one of
     * their variants gets `provider: null`.
     *
     * @param array<string, array<string, mixed>> $products
     * @param array<string, mixed> $item
     */
    private static function addNestedProduct(array &$products, array $item, string $kind): string
    {
        $slug = self::makeSlug((string) $item['_id'], (string) $item['name']);

        if (isset($products[$slug])) {
            return $slug;
        }

        $variants = self::buildVariants($item['variants'] ?? [], $item, []);

        $products[$slug] = [
            'slug' => $slug,
            'name' => $item['name'],
            'subtitle' => null,
            'kind' => $kind,
            'categories' => self::namesOf($item['categories'] ?? []),
            'condition_treated' => self::namesOf($item['condition_treated'] ?? []),
            'description' => $item['description_long'] ?? $item['description_short'] ?? '',
            'price_cents' => $variants[0]['price_cents'] ?? 0,
            'price_unit' => self::PRICE_UNIT,
            'image' => self::PLACEHOLDER_IMAGE,
            'gallery' => [self::PLACEHOLDER_IMAGE],
            'badges' => [],
            'emr_product_id' => $item['_id'],
            'remote_image' => $item['image'] ?? null,
            'teleform_id' => $item['teleforms'][0]['_id'] ?? null,
            'max_buy_qty' => $item['max_buy_qty'] ?? null,
            'min_buy_qty' => $item['min_buy_qty'] ?? null,
            'restrict_multiple' => (bool) ($item['restrict_multiple'] ?? false),
            'variants' => $variants,
            'bundles' => [],
            'attachments' => [],
        ];

        return $slug;
    }

    /**
     * Rule 4 [2.15]: a product/lab/add-on with an empty `variants` list
     * sells as a single SKU; synthesize one variant from the product's own
     * id/name/price so downstream code always sees a uniform, non-empty
     * variants list. A synthesized variant's id equals the product id,
     * which is exactly what the EMR emits as `variant_id` in
     * `product_mappings` for single-SKU products (rule 5).
     *
     * @param array<int, mixed> $variants
     * @param array<string, mixed> $product
     * @param array<int, array<string, mixed>> $mappings
     * @return array<int, array{id: string, name: mixed, price_cents: int, provider: array{offer_id: string, product_id: string}|null}>
     */
    private static function buildVariants(array $variants, array $product, array $mappings): array
    {
        if ($variants === []) {
            $id = (string) ($product['_id'] ?? $product['product_id'] ?? '');

            return [[
                'id' => $id,
                'name' => $product['name'] ?? '',
                'price_cents' => self::toCents(self::defaultDollarPrice($product)),
                'provider' => self::providerFor($id, $mappings),
            ]];
        }

        $out = [];
        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }

            $id = (string) $variant['_id'];
            $out[] = [
                'id' => $id,
                // Rule 3: `default_price` is decimal dollars in the EMR;
                // `price_cents = (int) round($dollars * 100)`.
                'name' => $variant['name'] ?? '',
                'price_cents' => self::toCents(isset($variant['default_price']) ? (float) $variant['default_price'] : null),
                'provider' => self::providerFor($id, $mappings),
            ];
        }

        return $out;
    }

    /**
     * Rule 5: find the first `product_mappings[]` entry whose `variant_id`
     * matches this variant's id and attach its offer/product identifiers.
     * "First" matters because the fixture can carry more than one mapping
     * per variant_id across different payment processors (e.g. a vrio entry
     * and a checkout_champ entry for the same variant) — the earliest one
     * in array order wins. No match -> `null`; validation reports the gap
     * later ([2.18]).
     *
     * @param array<int, array<string, mixed>> $mappings
     * @return array{offer_id: string, product_id: string}|null
     */
    private static function providerFor(string $variantId, array $mappings): ?array
    {
        foreach ($mappings as $mapping) {
            if (is_array($mapping) && (string) ($mapping['variant_id'] ?? '') === $variantId) {
                return [
                    'offer_id' => (string) $mapping['mapping']['offer_id'],
                    'product_id' => (string) $mapping['mapping']['product_id'],
                ];
            }
        }

        return null;
    }

    /**
     * Best-effort decimal-dollar price for a product/lab/add-on that has no
     * explicit variants (rule 4, [2.15]): prefer `single[0].default_price`
     * (standard/add_on shape), fall back to a top-level `price` field (the
     * labtest shape), else null (rendered as 0 cents by
     * {@see self::toCents()} — surfaced here in the PHPDoc since this is
     * pure code with no logging channel).
     *
     * @param array<string, mixed> $item
     */
    private static function defaultDollarPrice(array $item): ?float
    {
        if (isset($item['single'][0]['default_price'])) {
            return (float) $item['single'][0]['default_price'];
        }

        if (isset($item['price'])) {
            return (float) $item['price'];
        }

        return null;
    }

    /** Rule 3: decimal dollars -> integer cents. Missing price -> 0 cents. */
    private static function toCents(?float $dollars): int
    {
        return (int) round(($dollars ?? 0.0) * 100);
    }

    /** Rule 2: EMR `prescription` type -> `rx`; everything else -> `otc`. */
    private static function kindOf(mixed $type): string
    {
        return $type === 'prescription' ? 'rx' : 'otc';
    }

    /**
     * Rule 9: `categories`/`condition_treated` are lists of `{_id, name}`
     * objects in the standard shape, but the add_ons fixture shape stores
     * `condition_treated` as bare id strings with no `name` — those entries
     * are silently skipped rather than raising, since there is no name to
     * surface.
     *
     * @param array<int, mixed> $items
     * @return array<int, string>
     */
    private static function namesOf(array $items): array
    {
        $names = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['name'])) {
                $names[] = (string) $item['name'];
            }
        }

        return $names;
    }

    /**
     * Rule 1 [2.10]: slug = first 6 chars of the EMR `_id` + '-' + slugified
     * name. Deterministic and collision-free for same-name products because
     * the id prefix disambiguates them.
     */
    private static function makeSlug(string $id, string $name): string
    {
        return substr($id, 0, 6) . '-' . self::slugify($name);
    }

    /** Lowercase; any run of non-alphanumeric characters collapses to a single '-'; trim leading/trailing '-'. */
    private static function slugify(string $name): string
    {
        $slug = strtolower($name);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim($slug, '-');
    }
}
