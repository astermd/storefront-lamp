<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Domain;

/**
 * One line of the cart: a product, a quantity, a resolved price, optionally
 * a chosen variant and a parent link (`[1.1]`, `[1.5]`).
 *
 * Identity is readonly and the three mutable fields are the three the rules
 * are allowed to change, which is what lets {@see CartRules} re-price a line
 * when a plan is chosen (`[12.4]`) without rebuilding it and losing its
 * parent link. `name` and `emrProductId` are snapshots taken from the
 * catalog at add time because the mirrored payload needs them (`[7.11]`) and
 * a re-sync must not silently rewrite what a buyer already put in a cart.
 */
final class CartLine
{
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $kind,
        public readonly ?string $emrProductId,
        public readonly ?string $parentSlug,
        public int $quantity = 1,
        public int $unitPriceCents = 0,
        public ?string $variantId = null,
    ) {
    }

    public function isChild(): bool
    {
        return $this->parentSlug !== null;
    }

    public function lineTotalCents(): int
    {
        return $this->quantity * $this->unitPriceCents;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'kind' => $this->kind,
            'emr_product_id' => $this->emrProductId,
            'parent_slug' => $this->parentSlug,
            'quantity' => $this->quantity,
            'unit_price_cents' => $this->unitPriceCents,
            'variant_id' => $this->variantId,
        ];
    }

    /** @param array<string, mixed> $data as produced by {@see self::toArray()} */
    public static function fromArray(array $data): self
    {
        return new self(
            slug: (string) ($data['slug'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            kind: (string) ($data['kind'] ?? 'otc'),
            emrProductId: isset($data['emr_product_id']) && is_scalar($data['emr_product_id'])
                ? (string) $data['emr_product_id']
                : null,
            parentSlug: isset($data['parent_slug']) && is_string($data['parent_slug']) && $data['parent_slug'] !== ''
                ? $data['parent_slug']
                : null,
            quantity: max(1, (int) ($data['quantity'] ?? 1)),
            unitPriceCents: (int) ($data['unit_price_cents'] ?? 0),
            variantId: isset($data['variant_id']) && is_string($data['variant_id']) && $data['variant_id'] !== ''
                ? $data['variant_id']
                : null,
        );
    }
}
