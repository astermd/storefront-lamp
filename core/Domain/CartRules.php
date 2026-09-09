<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Domain;

/**
 * Every rule §7 states about a cart, as pure code over a {@see Cart} and a
 * {@see ProductCatalog}. No I/O, no framework types, no persistence — which
 * is what makes the whole rule set cheap to cover exhaustively, and what
 * keeps the rules identical whether the mutation arrived from the mini-cart
 * drawer, from a checkout order bump (`[27.11]`), or from a product page
 * whose cart view was bypassed entirely (`[8.0c]`: removing a view must never
 * remove a rule).
 *
 * Three rules here are the ones that cost money when they are wrong. A
 * `free-addon` line is priced at zero whatever the catalog says its
 * standalone price is (`[7.14a]`), because a supply appearing as a charge on
 * a receipt is a refund request. A second prescription replaces the first
 * rather than joining it (`[8.0f]`, `[8.0h]`), because a cart holding two
 * prescriptions collects one intake and produces a paid order no clinician
 * assessed. And an attachment that cannot be added blocks its parent with a
 * stated reason rather than dropping quietly (`[7.20]`) — shipping an
 * injectable with no syringe is the failure this rule exists to prevent.
 */
final class CartRules
{
    public const string UNKNOWN_PRODUCT = 'That product is not available.';

    public const string GEO_BLOCKED = 'This product is not available in your region.';

    public const string UNKNOWN_VARIANT = 'Choose a treatment plan before continuing.';

    /** sprintf: parent name, unavailable child slug. */
    public const string CHILD_UNAVAILABLE = '%s cannot be added right now: a required item (%s) is unavailable.';

    /** sprintf: replaced product name, new product name. */
    public const string RX_REPLACED = '%s was replaced with %s — one prescription per order.';

    /** sprintf: child name, parent name. */
    public const string CHILD_LOCKED = '%s is included with %s and cannot be changed on its own.';

    public const string RX_QTY_FIXED = 'Prescription quantities are fixed at one per order.';

    /** sprintf: cap, product name. */
    public const string QTY_CAPPED = 'You can order up to %d of %s.';

    /** sprintf: comma-joined product names. */
    public const string TERRITORY_BLOCKED = 'These products are not available in your region: %s.';

    public function __construct(private readonly ProductCatalog $catalog)
    {
    }

    /**
     * `[7.1]`–`[7.7]`, `[7.13]`, `[7.14a]`, `[7.20]`, `[8.0h]`, `[12.4]`,
     * `[12.7]`. Every gate is evaluated before anything is mutated, so a
     * rejected add leaves the cart byte-identical to what it was.
     *
     * `[7.8]` establishes that a bundled line is not the buyer's to change;
     * this guard applies that same rule to `add()` when a request names the
     * child's slug directly, checked before the existing-line lookup is used
     * for anything else — in particular before the prescription-replacement
     * branch, so posting a child's slug can never drop an unrelated
     * prescription on its way to being refused.
     */
    public function add(Cart $cart, string $slug, ?string $variantId = null, int $quantity = 1): CartOutcome
    {
        $product = $this->catalog->product($slug);
        if ($product === null) {
            return CartOutcome::rejected(self::UNKNOWN_PRODUCT);
        }

        if (self::blocksTerritory($product, $cart->shippingTerritory)) {
            return CartOutcome::rejected(self::GEO_BLOCKED);
        }

        if ($variantId !== null && self::variant($product, $variantId) === null) {
            return CartOutcome::rejected(self::UNKNOWN_VARIANT);
        }

        $unattachable = $this->unattachableDescendant($slug, $cart->shippingTerritory);
        if ($unattachable !== null) {
            return CartOutcome::rejected(sprintf(
                self::CHILD_UNAVAILABLE,
                (string) ($product['name'] ?? $product['slug']),
                $unattachable,
            ));
        }

        $line = $cart->line($slug);
        if ($line !== null && $line->isChild()) {
            $parent = $cart->line($line->parentSlug ?? '');

            return CartOutcome::rejected(sprintf(
                self::CHILD_LOCKED,
                $line->name,
                $parent?->name ?? (string) $line->parentSlug,
            ));
        }

        $notice = null;
        if (($product['kind'] ?? null) === 'rx') {
            $existing = $cart->rxLine();
            if ($existing !== null && $existing->slug !== $slug) {
                $cart->forget($existing->slug);
                $this->reattachRequiredChildren($cart);
                $notice = sprintf(
                    self::RX_REPLACED,
                    $existing->name,
                    (string) ($product['name'] ?? $product['slug']),
                );
            }
        }

        if ($line === null) {
            $line = self::newLine($product, null);
            $line->quantity = 0;
            $cart->put($line);
        }

        if ($variantId !== null) {
            $line->variantId = $variantId;
        }
        $line->unitPriceCents = self::priceFor($product, $line->variantId);
        $line->quantity = self::clampQuantity($product, $line->quantity + max(1, $quantity));

        $this->attachChildren($cart, $slug);

        return CartOutcome::accepted($notice);
    }

    /** `[7.8]`, `[7.9]`. */
    public function remove(Cart $cart, string $slug): CartOutcome
    {
        $line = $cart->line($slug);
        if ($line === null) {
            return CartOutcome::rejected(self::UNKNOWN_PRODUCT);
        }

        if ($line->isChild()) {
            $parent = $cart->line($line->parentSlug ?? '');

            return CartOutcome::rejected(sprintf(
                self::CHILD_LOCKED,
                $line->name,
                $parent?->name ?? (string) $line->parentSlug,
            ));
        }

        $cart->forget($slug);
        $this->reattachRequiredChildren($cart);

        return CartOutcome::accepted();
    }

    /**
     * `[7.16]`: the explicit quantity operation the cart never had, bounded by
     * a per-product maximum and re-running the geo and bundle rules on every
     * change — which is what closes `[7.13]`, where an increment used to slip
     * past both.
     *
     * Zero routes through {@see self::remove()} rather than deleting the line
     * here, so a bundled child gets the same refusal whether the buyer pressed
     * the bin or stepped the quantity down to nothing.
     *
     * The descendant check (`[7.20]`) runs before the quantity is assigned,
     * the same discipline {@see self::add()} follows, so a rejected call
     * leaves the line byte-identical — a required attachment that is
     * currently blocked in this territory must refuse the change rather than
     * let the quantity move while the bundle it depends on stays unshippable.
     */
    public function setQuantity(Cart $cart, string $slug, int $quantity): CartOutcome
    {
        $line = $cart->line($slug);
        if ($line === null) {
            return CartOutcome::rejected(self::UNKNOWN_PRODUCT);
        }

        if ($line->isChild()) {
            $parent = $cart->line($line->parentSlug ?? '');

            return CartOutcome::rejected(sprintf(
                self::CHILD_LOCKED,
                $line->name,
                $parent?->name ?? (string) $line->parentSlug,
            ));
        }

        if ($quantity <= 0) {
            return $this->remove($cart, $slug);
        }

        if ($line->kind === 'rx') {
            return CartOutcome::rejected(self::RX_QTY_FIXED);
        }

        $product = $this->catalog->product($slug);
        if ($product === null) {
            return CartOutcome::rejected(self::UNKNOWN_PRODUCT);
        }

        if (self::blocksTerritory($product, $cart->shippingTerritory)) {
            return CartOutcome::rejected(self::GEO_BLOCKED);
        }

        $unattachable = $this->unattachableDescendant($slug, $cart->shippingTerritory);
        if ($unattachable !== null) {
            return CartOutcome::rejected(sprintf(self::CHILD_UNAVAILABLE, $line->name, $unattachable));
        }

        $clamped = self::clampQuantity($product, $quantity);
        $line->quantity = $clamped;
        $this->attachChildren($cart, $slug);

        return $clamped < $quantity
            ? CartOutcome::accepted(sprintf(self::QTY_CAPPED, $clamped, $line->name))
            : CartOutcome::accepted();
    }

    /**
     * `[12.4]`, `[12.7]`: a variant is a plan, so choosing one is the moment an
     * Rx line stops being zero-priced. An identifier that does not belong to
     * that product is refused rather than falling back to the first variant,
     * because silently substituting a plan the buyer did not choose makes the
     * charge and the fulfilment disagree with what was displayed (`[12.9]`).
     */
    public function chooseVariant(Cart $cart, string $slug, string $variantId): CartOutcome
    {
        $line = $cart->line($slug);
        $product = $this->catalog->product($slug);
        if ($line === null || $product === null) {
            return CartOutcome::rejected(self::UNKNOWN_PRODUCT);
        }

        if (self::variant($product, $variantId) === null) {
            return CartOutcome::rejected(self::UNKNOWN_VARIANT);
        }

        $line->variantId = $variantId;
        $line->unitPriceCents = self::priceFor($product, $variantId);

        return CartOutcome::accepted();
    }

    /**
     * `[13.6]`, `[13.7]`: the gate is re-run against the freshly submitted
     * territory across every line, not just against whatever was known when
     * each was added. A block leaves the cart completely intact and names the
     * products — the buyer decides what to drop, not the storefront.
     *
     * Each line is also checked for an unattachable descendant (`[7.20]`),
     * not merely its own `geo_blocks`: a shared child cascaded away when its
     * first parent departed leaves a surviving parent that still requires
     * it, and that requirement is invisible to a check that only looks at
     * lines presently in the cart — which is exactly the gap that let a
     * later `setQuantity()` or `remove()` silently restore a line this
     * territory cannot ship.
     */
    public function applyTerritory(Cart $cart, string $territory): CartOutcome
    {
        $blocked = [];
        foreach ($cart->lines() as $line) {
            $product = $this->catalog->product($line->slug);
            if ($product === null) {
                continue;
            }

            $blockedHere = self::blocksTerritory($product, $territory)
                || $this->unattachableDescendant($line->slug, $territory) !== null;

            if ($blockedHere) {
                $blocked[] = $line->name;
            }
        }

        if ($blocked !== []) {
            return CartOutcome::rejected(sprintf(self::TERRITORY_BLOCKED, implode(', ', $blocked)));
        }

        $cart->shippingTerritory = $territory;

        return CartOutcome::accepted();
    }

    /**
     * Re-establishes the invariant that a child line exists exactly while some
     * surviving line still requires it.
     *
     * A child records one parent, but two products may legitimately require the
     * same lab test or the same supply ([7.7]), so removing the parent that
     * happened to attach it first would otherwise take a line the surviving
     * parent still needs — a required supply dropping in silence, which is the
     * failure [7.20] exists to prevent. Re-running the attachment over every
     * surviving top-level line restores it and re-parents it to a requirer that
     * is actually still in the cart.
     */
    private function reattachRequiredChildren(Cart $cart): void
    {
        foreach ($cart->lines() as $line) {
            if (!$line->isChild()) {
                $this->attachChildren($cart, $line->slug);
            }
        }
    }

    /**
     * Adds every mandatory bundle and free attachment declared on $slug, and
     * recurses into theirs (`[7.5]`, `[7.6]`). An item already in the cart is
     * left alone, so two parents needing the same lab test produce one line
     * (`[7.7]`); the visited set is what stops a catalog that references
     * itself from recursing forever.
     *
     * @param array<string, bool> $visited
     */
    private function attachChildren(Cart $cart, string $parentSlug, array $visited = []): void
    {
        if (isset($visited[$parentSlug])) {
            return;
        }
        $visited[$parentSlug] = true;

        $parent = $this->catalog->product($parentSlug);
        if ($parent === null) {
            return;
        }

        foreach (self::childSlugs($parent) as $childSlug) {
            $child = $this->catalog->product($childSlug);
            if ($child === null) {
                continue;
            }

            if (!$cart->has($childSlug)) {
                $line = self::newLine($child, $parentSlug);
                $line->unitPriceCents = self::priceFor($child, null);
                $cart->put($line);
            }

            $this->attachChildren($cart, $childSlug, $visited);
        }
    }

    /**
     * The first bundle or attachment beneath $slug that cannot be added —
     * missing from the catalog, or blocked in this territory. `[7.20]`.
     *
     * @param array<string, bool> $visited
     */
    private function unattachableDescendant(string $slug, ?string $territory, array $visited = []): ?string
    {
        if (isset($visited[$slug])) {
            return null;
        }
        $visited[$slug] = true;

        $product = $this->catalog->product($slug);
        if ($product === null) {
            return null;
        }

        foreach (self::childSlugs($product) as $childSlug) {
            $child = $this->catalog->product($childSlug);
            if ($child === null || self::blocksTerritory($child, $territory)) {
                return $childSlug;
            }

            $deeper = $this->unattachableDescendant($childSlug, $territory, $visited);
            if ($deeper !== null) {
                return $deeper;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $product
     *
     * @return list<string>
     */
    private static function childSlugs(array $product): array
    {
        $slugs = [];
        foreach (['bundles', 'attachments'] as $key) {
            foreach ((array) ($product[$key] ?? []) as $slug) {
                if (is_string($slug) && $slug !== '') {
                    $slugs[] = $slug;
                }
            }
        }

        return $slugs;
    }

    /** @param array<string, mixed> $product */
    private static function newLine(array $product, ?string $parentSlug): CartLine
    {
        return new CartLine(
            slug: (string) $product['slug'],
            name: (string) ($product['name'] ?? $product['slug']),
            kind: (string) ($product['kind'] ?? 'otc'),
            emrProductId: isset($product['emr_product_id']) && is_scalar($product['emr_product_id'])
                ? (string) $product['emr_product_id']
                : null,
            parentSlug: $parentSlug,
        );
    }

    /**
     * `[7.4]` with `[7.14a]` on top: a `free-addon` is always zero, an `rx`
     * with no plan chosen yet is zero because its price is not knowable until
     * the variant is (`[12.4]`), and everything else takes the chosen
     * variant's price or the first variant's.
     *
     * @param array<string, mixed> $product
     */
    private static function priceFor(array $product, ?string $variantId): int
    {
        if (($product['kind'] ?? null) === 'free-addon') {
            return 0;
        }

        if ($variantId !== null) {
            $variant = self::variant($product, $variantId);
            if ($variant !== null) {
                return (int) ($variant['price_cents'] ?? 0);
            }
        }

        if (($product['kind'] ?? null) === 'rx') {
            return 0;
        }

        $variants = self::variants($product);

        return (int) ($variants[0]['price_cents'] ?? $product['price_cents'] ?? 0);
    }

    /**
     * @param array<string, mixed> $product
     *
     * @return array<string, mixed>|null
     */
    private static function variant(array $product, string $variantId): ?array
    {
        foreach (self::variants($product) as $variant) {
            if ((string) ($variant['id'] ?? '') === $variantId) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $product
     *
     * @return list<array<string, mixed>>
     */
    private static function variants(array $product): array
    {
        $variants = is_array($product['variants'] ?? null) ? $product['variants'] : [];

        return array_values(array_filter($variants, 'is_array'));
    }

    /**
     * A prescription is always one per order (`[8.0f]`); everything else is
     * capped by the catalog's own `max_buy_qty` when it declares one, and by
     * {@see self::DEFAULT_MAX_QUANTITY} when it does not, because `[7.16]`
     * requires a ceiling and an uncapped quantity field is an invitation.
     *
     * @param array<string, mixed> $product
     */
    private static function clampQuantity(array $product, int $quantity): int
    {
        if (($product['kind'] ?? null) === 'rx') {
            return 1;
        }

        $max = isset($product['max_buy_qty']) && is_numeric($product['max_buy_qty'])
            ? max(1, (int) $product['max_buy_qty'])
            : self::DEFAULT_MAX_QUANTITY;

        return max(1, min($quantity, $max));
    }

    /** @param array<string, mixed> $product */
    private static function blocksTerritory(array $product, ?string $territory): bool
    {
        if ($territory === null || $territory === '') {
            return false;
        }

        foreach ((array) ($product['geo_blocks'] ?? []) as $blocked) {
            if (is_string($blocked) && strcasecmp($blocked, $territory) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The ceiling for a product whose catalog entry declares no `max_buy_qty`.
     * Deliberately low: this is an accessory stepper in a mini-cart, not a
     * wholesale channel, and there is no stock concept behind it (`[7.15]`).
     */
    public const int DEFAULT_MAX_QUANTITY = 10;
}
