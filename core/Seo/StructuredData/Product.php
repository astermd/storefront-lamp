<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Seo\StructuredData;

/**
 * One catalog product as schema.org `Product` with its offer (`[24.8]`).
 *
 * Two rules govern this class and both are the reason it exists at all.
 *
 * **`[24.9]` — the offer reflects real state.** The price is the *resolved
 * variant's*, never the product-level `price_cents`. Variants are the plans a
 * visitor actually buys, so the product-level figure is a summary that no
 * checkout ever charges: the sample catalog's semaglutide carries 4600 at
 * product level and 5000 on the plan that is actually sold, and publishing
 * the first of those in an offer would advertise a price the checkout
 * refuses. The resolved variant is the first one, which is the same variant
 * every listing surface prices from, so the structured offer and the visible
 * "from" price cannot disagree. Availability is derived the same way: a
 * variant with no provider mapping cannot be ordered at all, so it is
 * published as out of stock rather than as an offer nothing can fulfil.
 *
 * **`[24.10]` — a prescription product defaults to publishing nothing.**
 * Claims and availability constraints for an `rx` product vary by
 * jurisdiction and by client, so this emitter returns null for one unless the
 * deployment has decided its own territory allows it. Conservative by
 * default, in the terms the rule uses.
 *
 * Money is integer cents everywhere inside this application; schema.org's
 * `price` is a decimal string. The conversion happens here, at the boundary,
 * in {@see self::decimal()} — by integer division rather than by dividing a
 * float, so no rounding step sits between the cents the checkout charges and
 * the price a crawler reads.
 */
final class Product implements Emitter
{
    public const string IN_STOCK = 'https://schema.org/InStock';

    public const string OUT_OF_STOCK = 'https://schema.org/OutOfStock';

    /**
     * @param array<string, mixed> $product a merged catalog product
     * @param string               $description already resolved through the `[24.4]` chain; blank means "publish no description key"
     * @param ?string              $currency the catalog channel's currency; null means the offer cannot be stated
     */
    public function __construct(
        private readonly array $product,
        private readonly string $canonical,
        private readonly string $baseUrl,
        private readonly string $description,
        private readonly string $brand,
        private readonly ?string $currency,
        private readonly bool $prescriptionPermitted,
    ) {
    }

    public function emit(): ?array
    {
        if ($this->isPrescription() && !$this->prescriptionPermitted) {
            return null;
        }

        $node = [
            '@type' => 'Product',
            '@id' => $this->canonical . '#product',
            'name' => trim((string) ($this->product['name'] ?? '')),
            'url' => $this->canonical,
        ];

        $images = $this->images();
        if ($images !== []) {
            $node['image'] = $images;
        }

        // Never an empty key: every product this EMR syncs carries an empty
        // description, and `<description></description>` in a published node
        // is a worse answer than no description at all.
        if (trim($this->description) !== '') {
            $node['description'] = $this->description;
        }

        if (trim($this->brand) !== '') {
            $node['brand'] = ['@type' => 'Brand', 'name' => trim($this->brand)];
        }

        $category = $this->category();
        if ($category !== null) {
            $node['category'] = $category;
        }

        $offer = $this->offer();
        if ($offer !== null) {
            $node['offers'] = $offer;
        }

        return $node;
    }

    /** Whether this product is dispensed on prescription (`[24.10]`). */
    public function isPrescription(): bool
    {
        return ($this->product['kind'] ?? null) === 'rx';
    }

    /**
     * The offer, or null when this deployment cannot state one truthfully.
     *
     * A missing currency is the only such case: an `Offer` without
     * `priceCurrency` names an amount in no unit, and guessing one would
     * publish a price this storefront never quoted.
     *
     * @return array<string, mixed>|null
     */
    private function offer(): ?array
    {
        if ($this->currency === null) {
            return null;
        }

        $variant = $this->resolvedVariant();
        $cents = $this->priceCents($variant);

        return [
            '@type' => 'Offer',
            'url' => $this->canonical,
            'price' => self::decimal($cents),
            'priceCurrency' => $this->currency,
            'availability' => $this->orderable($variant) ? self::IN_STOCK : self::OUT_OF_STOCK,
        ];
    }

    /**
     * The variant whose price is the one a visitor is quoted: the first, the
     * same one every listing surface prices from (`[24.9]`). Null when the
     * product has no variants at all, which single-SKU catalog entries do.
     *
     * @return array<string, mixed>|null
     */
    private function resolvedVariant(): ?array
    {
        foreach ((array) ($this->product['variants'] ?? []) as $variant) {
            if (is_array($variant)) {
                return $variant;
            }
        }

        return null;
    }

    /** @param array<string, mixed>|null $variant */
    private function priceCents(?array $variant): int
    {
        if ($variant !== null && isset($variant['price_cents']) && is_numeric($variant['price_cents'])) {
            return (int) $variant['price_cents'];
        }

        // Only reachable for a product with no variants, where the
        // product-level figure is the price rather than a summary of one.
        return is_numeric($this->product['price_cents'] ?? null) ? (int) $this->product['price_cents'] : 0;
    }

    /**
     * Whether the resolved variant can actually be bought.
     *
     * A variant carries the provider's offer and product identifiers or it
     * cannot be placed as an order — `config:validate` already counts the
     * ones that do not. Genuine availability (`[24.9]`) means saying so
     * rather than advertising an offer that no checkout could complete.
     *
     * @param array<string, mixed>|null $variant
     */
    private function orderable(?array $variant): bool
    {
        $provider = $variant['provider'] ?? null;

        return is_array($provider)
            && trim((string) ($provider['offer_id'] ?? '')) !== ''
            && trim((string) ($provider['product_id'] ?? '')) !== '';
    }

    /** @return list<string> absolute image URLs, the primary one first */
    private function images(): array
    {
        $paths = [];
        foreach ([$this->product['image'] ?? null, ...(array) ($this->product['gallery'] ?? [])] as $path) {
            if (!is_string($path) || trim($path) === '') {
                continue;
            }

            $paths[$this->absolute(trim($path))] = true;
        }

        return array_keys($paths);
    }

    private function category(): ?string
    {
        foreach ((array) ($this->product['categories'] ?? []) as $category) {
            if (is_string($category) && trim($category) !== '') {
                return trim($category);
            }
        }

        return null;
    }

    private function absolute(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    /**
     * Integer cents as the decimal string schema.org's `price` requires.
     *
     * Integer arithmetic rather than `$cents / 100`: money is integer cents
     * everywhere inside this application precisely so that no float rounding
     * can sit between what is charged and what is published.
     */
    public static function decimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }
}
