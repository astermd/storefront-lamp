<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Checkout;

use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Support\OperatorLog;

/**
 * Which bumps this cart earns, in what order (§27).
 *
 * Deciding is pure: this class answers "what should the page offer", and
 * accepting an offer goes through {@see \AsterMD\Storefront\Domain\CartRules}
 * like any other add (`[27.11]`), so a bump's own supply attachments come along
 * and the geo gate applies to it. A bump is a cart line, not a special case
 * (`[27.15]`), which is also why nothing here needs to re-implement pricing,
 * bundling or removal.
 *
 * Bumps re-evaluate every time the page renders (`[27.14]`). Removing the
 * product that triggered one therefore stops offering it -- but an accepted
 * bump is by then an ordinary line and is unaffected, because this class never
 * sees accepted lines, only offers.
 *
 * A configured maximum renders and the remainder are dropped in position order
 * (`[27.8]`), and the drop is logged: silent truncation reads as "we showed
 * everything" when we did not.
 */
final class OrderBumps
{
    /** @param array<string, list<array<string, mixed>>> $byTrigger triggering slug → its configured bumps */
    private function __construct(
        private readonly array $byTrigger,
        private readonly int $maxOnPage,
        private readonly ProductCatalog $catalog,
        private readonly OperatorLog $log,
    ) {
    }

    /** @param array<string, mixed> $config `config/cross-sells.php` */
    public static function fromConfig(array $config, ProductCatalog $catalog, OperatorLog $log): self
    {
        /** @var array<string, list<array<string, mixed>>> $bumps */
        $bumps = is_array($config['bumps'] ?? null) ? $config['bumps'] : [];
        $max = is_int($config['max_on_page'] ?? null) ? $config['max_on_page'] : 3;

        return new self($bumps, max(0, $max), $catalog, $log);
    }

    /** @return list<OrderBump> */
    public function offeredFor(Cart $cart, string $territory): array
    {
        $candidates = [];

        foreach ($cart->lines() as $line) {
            foreach ($this->byTrigger[$line->slug] ?? [] as $row) {
                $bump = $this->resolve($row);
                if ($bump === null) {
                    continue;
                }

                // Dedupe by key, keeping the first-seen definition: two
                // products triggering the same offer show it once (`[27.6]`).
                if (isset($candidates[$bump->key])) {
                    continue;
                }

                // An offer for something already in the cart is not shown and
                // not ignorable later -- it simply is not an offer (`[27.7]`).
                if ($cart->has($bump->slug)) {
                    continue;
                }

                if ($this->blockedIn($bump->slug, $territory)) {
                    continue;
                }

                $candidates[$bump->key] = $bump;
            }
        }

        $ordered = array_values($candidates);
        usort($ordered, static fn (OrderBump $a, OrderBump $b): int => $a->position <=> $b->position);

        if (count($ordered) <= $this->maxOnPage) {
            return $ordered;
        }

        $kept = array_slice($ordered, 0, $this->maxOnPage);
        $dropped = array_slice($ordered, $this->maxOnPage);

        $this->log->info('checkout.bumps_truncated', [
            'max' => $this->maxOnPage,
            'dropped' => array_map(static fn (OrderBump $b): string => $b->slug, $dropped),
        ]);

        return $kept;
    }

    /** @param array<string, mixed> $row */
    private function resolve(array $row): ?OrderBump
    {
        $slug = trim((string) ($row['slug'] ?? ''));
        $product = $slug === '' ? null : $this->catalog->product($slug);

        if ($product === null) {
            $this->log->warning('checkout.bump_unresolvable', ['slug' => $slug === '' ? null : $slug]);

            return null;
        }

        $override = $row['price_cents_override'] ?? null;
        $variant = $row['variant_id'] ?? null;
        $variantId = is_string($variant) && $variant !== '' ? $variant : null;

        // A bump that names a variant is an offer for *that* variant, so its
        // catalog price is that variant's. The product's own `price_cents`
        // mirrors the first variant, which for a multi-plan product is a
        // different plan at a different price -- and because the card page,
        // the provider payload and the order line all take their number from
        // here, nothing downstream could notice the mismatch.
        $catalogPrice = $variantId === null
            ? (int) ($product['price_cents'] ?? 0)
            : self::variantPrice($product, $variantId);

        if ($catalogPrice === null) {
            $this->log->warning('checkout.bump_unresolvable', ['slug' => $slug, 'variant_id' => $variantId]);

            return null;
        }

        return new OrderBump(
            key: trim((string) ($row['key'] ?? $slug)),
            slug: $slug,
            variantId: $variantId,
            headline: (string) ($row['headline'] ?? ($product['name'] ?? $slug)),
            body: (string) ($row['body'] ?? ''),
            position: is_int($row['position'] ?? null) ? $row['position'] : PHP_INT_MAX,
            priceCentsOverride: is_int($override) ? $override : null,
            name: (string) ($product['name'] ?? $slug),
            catalogPriceCents: $catalogPrice,
        );
    }

    /**
     * The named variant's price, or null when the product has no such variant.
     *
     * Null rather than a fallback on purpose: a bump pointing at a variant
     * this product does not have is a misconfiguration, and the only prices
     * available to fall back to belong to a different plan. It is dropped the
     * way an unresolvable slug is (`[27.9]`) -- which is also the only honest
     * outcome, since {@see \AsterMD\Storefront\Domain\CartRules::add()} would
     * refuse that variant if the buyer accepted the offer.
     *
     * @param array<string, mixed> $product
     */
    private static function variantPrice(array $product, string $variantId): ?int
    {
        $variants = is_array($product['variants'] ?? null) ? $product['variants'] : [];

        foreach ($variants as $variant) {
            if (is_array($variant) && (string) ($variant['id'] ?? '') === $variantId) {
                return (int) ($variant['price_cents'] ?? 0);
            }
        }

        return null;
    }

    private function blockedIn(string $slug, string $territory): bool
    {
        $product = $this->catalog->product($slug);
        $blocks = is_array($product['geo_blocks'] ?? null) ? $product['geo_blocks'] : [];

        return in_array(strtoupper($territory), array_map(
            static fn ($t): string => strtoupper((string) $t),
            $blocks,
        ), true);
    }
}
