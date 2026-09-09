<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Upsell;

use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Support\OperatorLog;

/**
 * Which post-purchase upsells this journey earned, in what order (§16).
 *
 * The queue is built **once**, at checkout success, from the slugs that were
 * just purchased (`[16.1]`), and stepped over several later requests. That is
 * the whole reason this class is split in two: {@see self::queueFor()} decides
 * membership from configuration that was live at purchase time, and
 * {@see self::resolve()} turns one entry into an offer at the moment it is
 * about to be rendered. Configuration can change in between, so an entry that
 * no longer resolves is skipped then rather than filtered now (`[16.4]`) --
 * filtering early would make a misconfiguration look like an empty queue.
 *
 * **Ordered by configuration order** (`[16.2]`), which is why the config is an
 * ordered map with no position key: a second authority on order is a second
 * thing that can disagree with the first. This is the deliberate difference
 * from {@see \AsterMD\Storefront\Checkout\OrderBumps}, whose offers *are*
 * position-sorted because several triggers contribute to one page there,
 * while here exactly one offer is presented per step (`[16.3]`).
 *
 * Deduplicated by key, so an upsell triggered by two purchased products
 * appears once (`[16.2]`).
 */
final class Upsells
{
    /** @param array<string, array<string, mixed>> $byKey upsell key → its configured definition, in configuration order */
    private function __construct(
        private readonly array $byKey,
        private readonly ProductCatalog $catalog,
        private readonly OperatorLog $log,
    ) {
    }

    /** @param array<string, mixed> $config `config/upsells.php` */
    public static function fromConfig(array $config, ProductCatalog $catalog, OperatorLog $log): self
    {
        $byKey = [];

        foreach (is_array($config['upsells'] ?? null) ? $config['upsells'] : [] as $key => $row) {
            if (is_array($row)) {
                $byKey[(string) $key] = $row;
            }
        }

        return new self($byKey, $catalog, $log);
    }

    /**
     * The keys this purchase earned, in configuration order (`[16.1]`,
     * `[16.2]`).
     *
     * The catalog is deliberately not consulted here. An entry naming a
     * product that has since disappeared stays in the queue and is skipped
     * when {@see self::resolve()} is asked for it, so the skip is visible in
     * the operator log at the moment it costs a sale rather than looking, from
     * here, like a buyer who was simply owed nothing.
     *
     * @param  list<string> $purchasedSlugs
     * @return list<string> upsell keys, deduplicated, in configuration order
     */
    public function queueFor(array $purchasedSlugs): array
    {
        $queue = [];

        foreach ($this->byKey as $key => $row) {
            // One entry per key is what makes this deduplicated: an upsell
            // earned by two of the purchased products is still one entry, so
            // it is offered once (`[16.2]`).
            foreach (is_array($row['offer_after'] ?? null) ? $row['offer_after'] : [] as $trigger) {
                if (is_string($trigger) && in_array($trigger, $purchasedSlugs, true)) {
                    $queue[] = $key;

                    continue 2;
                }
            }
        }

        return $queue;
    }

    /**
     * One entry as an offer, or null when it can no longer be made.
     *
     * Null rather than an exception, and logged rather than silent: this runs
     * while a buyer is waiting on a page, so the only acceptable outcome of a
     * misconfiguration is that the offer is skipped and the journey continues
     * (`[16.4]`). `bin/console config:validate` is where the same fault is
     * meant to be loud.
     */
    public function resolve(string $key): ?Upsell
    {
        $row = $this->byKey[$key] ?? null;
        if ($row === null) {
            return null;
        }

        $slug = trim((string) ($row['slug'] ?? ''));
        $product = $slug === '' ? null : $this->catalog->product($slug);

        // The product must still exist: everything below reads its name, kind
        // and price, and there is nothing honest to substitute for them.
        if ($product === null) {
            $this->log->warning('upsell.unresolvable', ['key' => $key, 'slug' => $slug === '' ? null : $slug]);

            return null;
        }

        $variant = $row['variant_id'] ?? null;
        $variantId = is_string($variant) && $variant !== '' ? $variant : null;
        $named = $variantId === null ? null : self::variantById($product, $variantId);

        // A named variant the product does not have is unresolvable, not a
        // fallback, for {@see \AsterMD\Storefront\Checkout\OrderBumps::variantPrice()}'s
        // reason: the only prices to fall back to belong to a different plan,
        // and this one would be charged for.
        if ($variantId !== null && $named === null) {
            $this->log->warning('upsell.unresolvable', ['key' => $key, 'slug' => $slug, 'variant_id' => $variantId]);

            return null;
        }

        // The override wins; failing that the named variant's own price;
        // failing that the product's, which mirrors its first variant.
        $override = $row['price_cents_override'] ?? null;
        $priceCents = match (true) {
            is_int($override) => $override,
            $named !== null => (int) ($named['price_cents'] ?? 0),
            default => (int) ($product['price_cents'] ?? 0),
        };

        // The provider identity of the variant this offer *is* -- or of the
        // first one when it names none, which is what the catalog builder
        // synthesises for a single-SKU product. A missing mapping is still
        // returned: whether an unchargeable offer is survivable belongs to the
        // charge, and `config:validate` refuses the configuration outright.
        $provider = self::providerOf($named ?? self::firstVariant($product));

        $bullets = [];
        foreach (is_array($row['bullets'] ?? null) ? $row['bullets'] : [] as $bullet) {
            $bullets[] = (string) $bullet;
        }

        $image = $row['image'] ?? null;
        $name = (string) ($product['name'] ?? $slug);

        return new Upsell(
            key: $key,
            slug: $slug,
            variantId: $variantId,
            eyebrow: (string) ($row['eyebrow'] ?? ''),
            // The catalog name is the fallback headline because a nameless
            // offer is worse than a plain one.
            headline: self::text($row['headline'] ?? null) ?? $name,
            body: (string) ($row['body'] ?? ''),
            bullets: $bullets,
            image: is_string($image) && $image !== '' ? $image : null,
            // The mockup's own wording, defaulted here rather than in the
            // template so a configured upsell can override it.
            acceptLabel: self::text($row['accept_label'] ?? null) ?? 'Add to Order',
            declineLabel: self::text($row['decline_label'] ?? null) ?? 'No Thanks',
            // Defaulted to nothing rather than to the mockup's wording, which is
            // the one piece of its copy this cannot inherit -- see the property.
            footnote: self::text($row['footnote'] ?? null),
            name: $name,
            priceCents: $priceCents,
            providerOffer: self::text($provider['offer_id'] ?? null),
            providerItem: self::text($provider['product_id'] ?? null),
            kind: (string) ($product['kind'] ?? 'otc'),
        );
    }

    /**
     * Every configured key, in configuration order — what the validator
     * sweeps, and what a diagnostic listing reads.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map('strval', array_keys($this->byKey));
    }

    /**
     * @param  array<string, mixed> $product
     * @return array<string, mixed>|null
     */
    private static function variantById(array $product, string $variantId): ?array
    {
        foreach (is_array($product['variants'] ?? null) ? $product['variants'] : [] as $variant) {
            if (is_array($variant) && (string) ($variant['id'] ?? '') === $variantId) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed> $product
     * @return array<string, mixed>|null
     */
    private static function firstVariant(array $product): ?array
    {
        foreach (is_array($product['variants'] ?? null) ? $product['variants'] : [] as $variant) {
            if (is_array($variant)) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null $variant
     * @return array<string, mixed>
     */
    private static function providerOf(?array $variant): array
    {
        $provider = $variant === null ? null : ($variant['provider'] ?? null);

        return is_array($provider) ? $provider : [];
    }

    /** A configured string, or null when it is absent or blank — so a blank field falls back rather than rendering empty. */
    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
