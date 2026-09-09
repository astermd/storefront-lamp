<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Catalog;

/**
 * Pure structural pass over a merged catalog (the shape {@see CatalogBuilder}
 * and {@see CatalogMerger} produce). No I/O, no SDK types — `validate()` is a
 * deterministic function of its argument, exhaustively unit-testable without
 * test doubles. The live EMR-resolution pass (`--live`) lives in
 * {@see \AsterMD\Storefront\Console\ValidateCommand} instead, since it needs
 * an SDK client.
 *
 * Two input shapes are tolerated on purpose, since both flow through here:
 * a full sync catalog (`['channel' => ..., 'products' => ...]`, the shape
 * {@see CatalogBuilder::build()} emits) and the legacy sample-seed shape
 * (`['products' => ...]` only, no top-level `channel`, and individual
 * products that may have no `variants` key at all — single-SKU products
 * predating the variants shape). A missing `variants` key is tolerated as a
 * legacy-seed product (warning, not error); an explicit `variants` key whose
 * list is empty is NOT tolerated (error) because it means something upstream
 * (builder or override layer) produced a product nobody can actually buy.
 *
 * Bracketed numbers ([2.18], [29.3]) reference rule numbers in the
 * storefront business-logic spec that this validator enforces.
 */
final class CatalogValidator
{
    /** @var list<string> */
    private const array ALLOWED_KINDS = ['rx', 'otc', 'lab', 'free-addon'];

    /**
     * @param array<string, mixed> $catalog
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function validate(array $catalog): array
    {
        $errors = [];
        $warnings = [];

        if (!array_key_exists('channel', $catalog)) {
            $warnings[] = 'channel key absent';
        }

        $this->validateCurrency($catalog, $errors);

        $products = is_array($catalog['products'] ?? null) ? $catalog['products'] : [];
        $knownSlugs = self::knownSlugs($products);

        foreach ($products as $key => $product) {
            if (!is_array($product)) {
                $errors[] = sprintf('product %s: not an array', (string) $key);
                continue;
            }

            $ref = self::ref($product, $key);

            $this->validateRequiredFields($product, $ref, $errors);
            $this->validateKind($product, $ref, $errors);
            $this->validateBundlesAndAttachments($product, $ref, $knownSlugs, $errors);
            $this->validateVariants($product, $ref, $errors, $warnings);
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @param array<string, mixed> $catalog
     * @param list<string> $errors
     */
    private function validateCurrency(array $catalog, array &$errors): void
    {
        $channel = is_array($catalog['channel'] ?? null) ? $catalog['channel'] : [];

        if (!array_key_exists('currency', $channel)) {
            return;
        }

        $currency = $channel['currency'];
        if (!is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $errors[] = sprintf(
                'channel currency "%s" is not a 3-letter uppercase ISO code',
                is_scalar($currency) ? (string) $currency : gettype($currency),
            );
        }
    }

    /**
     * @param array<string, mixed> $product
     * @param list<string> $errors
     */
    private function validateRequiredFields(array $product, string $ref, array &$errors): void
    {
        foreach (['slug', 'name', 'kind'] as $field) {
            $value = $product[$field] ?? null;
            if (!is_string($value) || trim($value) === '') {
                $errors[] = sprintf('product %s: missing/blank or invalid required field "%s"', $ref, $field);
            }
        }
    }

    /**
     * @param array<string, mixed> $product
     * @param list<string> $errors
     */
    private function validateKind(array $product, string $ref, array &$errors): void
    {
        $kind = $product['kind'] ?? null;

        // A missing/blank/non-string kind is already reported by
        // validateRequiredFields(); only a present, non-blank string that
        // isn't one of the allowed values needs a second error here.
        if (is_string($kind) && trim($kind) !== '' && !in_array($kind, self::ALLOWED_KINDS, true)) {
            $errors[] = sprintf('product %s: kind "%s" is not one of %s', $ref, $kind, implode(', ', self::ALLOWED_KINDS));
        }
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, true> $knownSlugs
     * @param list<string> $errors
     */
    private function validateBundlesAndAttachments(array $product, string $ref, array $knownSlugs, array &$errors): void
    {
        foreach (['bundles', 'attachments'] as $field) {
            foreach ((array) ($product[$field] ?? []) as $slugRef) {
                if (!is_string($slugRef) || !isset($knownSlugs[$slugRef])) {
                    $errors[] = sprintf(
                        'product %s: %s slug "%s" resolves to no product',
                        $ref,
                        $field,
                        is_string($slugRef) ? $slugRef : gettype($slugRef),
                    );
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $product
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function validateVariants(array $product, string $ref, array &$errors, array &$warnings): void
    {
        if (!array_key_exists('variants', $product)) {
            $warnings[] = sprintf('product %s: legacy-seed product lacks variants', $ref);

            return;
        }

        $variants = $product['variants'];
        if (!is_array($variants)) {
            $errors[] = sprintf('product %s: variants must be a list', $ref);

            return;
        }

        if ($variants === []) {
            $errors[] = sprintf('product %s: variants list is empty', $ref);

            return;
        }

        $missingProviderNames = [];
        $prices = [];

        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                $errors[] = sprintf('product %s: variant entry is not an array', $ref);
                continue;
            }

            $variantLabel = is_string($variant['name'] ?? null) && $variant['name'] !== ''
                ? $variant['name']
                : (string) ($variant['id'] ?? '?');

            $price = $variant['price_cents'] ?? null;
            if (!is_int($price) || $price < 0) {
                $errors[] = sprintf('product %s: variant "%s" price_cents is not a non-negative int', $ref, $variantLabel);
            } else {
                $prices[] = $price;
            }

            if (($variant['provider'] ?? null) === null) {
                $missingProviderNames[] = $variantLabel;
            }
        }

        if ($missingProviderNames !== []) {
            $warnings[] = sprintf(
                'product %s: %d variant(s) need provider identifiers in the override layer — %s',
                $ref,
                count($missingProviderNames),
                implode(', ', $missingProviderNames),
            );
        }

        if (count(array_unique($prices)) > 1) {
            $warnings[] = sprintf(
                'product %s: variants differ in price — verify prices vary by plan length only, never by dose (spec [29.3])',
                $ref,
            );
        }
    }

    /**
     * The identifier used in messages for a product: its own `slug` field
     * when present and a non-blank string (the normal case for every
     * builder/merger-produced product), else its array key in `products`.
     *
     * @param array<string, mixed> $product
     */
    private static function ref(array $product, int|string $key): string
    {
        $slug = $product['slug'] ?? null;

        return is_string($slug) && $slug !== '' ? $slug : (string) $key;
    }

    /**
     * Every slug a `bundles`/`attachments` entry could legitimately resolve
     * to: each product's own `slug` field, falling back to its array key —
     * mirrors {@see self::ref()} so a bundle/attachment reference is checked
     * against exactly the same identifier space the error messages use.
     *
     * @param array<int|string, mixed> $products
     * @return array<string, true>
     */
    private static function knownSlugs(array $products): array
    {
        $slugs = [];
        foreach ($products as $key => $product) {
            if (is_array($product)) {
                $slugs[self::ref($product, $key)] = true;
            }
        }

        return $slugs;
    }
}
