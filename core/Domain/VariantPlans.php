<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Domain;

/**
 * Which subscription plan a cart line is sold on, read off the catalog in
 * the one place every reader asks it.
 *
 * `theme:sync` puts a variant the EMR sells on plans onto all of them:
 * `plans` lists every one, lowest id first, and `plan` is the default the
 * variant is priced on ({@see \AsterMD\Storefront\Catalog\CatalogBuilder}).
 * The cart prices a line on the plan the buyer chose, the order names it, the
 * settlement follows its first charge and the checkout offers the others. If
 * those four each resolved a line's plan their own way, the plan charged
 * could differ from the plan shown, the plan held from the plan named, and
 * nothing would say so.
 *
 * `plan` is what says a variant is sold on a plan at all. An override that
 * sells the variant one-off sets it to null and leaves the generated `plans`
 * beside it, so `plans` is never read without `plan`; and a catalog synced
 * before `plans` existed carries `plan` alone, which is one plan to choose
 * from.
 */
final class VariantPlans
{
    /**
     * The variant an order line is on: the one chosen, or the first when
     * nothing was chosen or the product no longer has the one that was.
     *
     * @param array<string, mixed>|null $product
     * @return array<string, mixed>|null
     */
    public static function variant(?array $product, ?string $variantId): ?array
    {
        $variants = is_array($product['variants'] ?? null) ? array_values(array_filter($product['variants'], 'is_array')) : [];

        if ($variants === []) {
            return null;
        }

        if ($variantId !== null && $variantId !== '') {
            foreach ($variants as $variant) {
                if ((string) ($variant['id'] ?? '') === $variantId) {
                    return $variant;
                }
            }
        }

        return $variants[0];
    }

    /**
     * Every plan the variant is sold on, the default first; empty when it is
     * sold one-off.
     *
     * The default is whatever `plan` says rather than the first of `plans`,
     * so an override that names another plan as the default is obeyed.
     *
     * @param array<string, mixed>|null $variant
     * @return list<array<string, mixed>>
     */
    public static function of(?array $variant): array
    {
        $default = $variant['plan'] ?? null;
        if (!is_array($default) || !self::isPlan($default)) {
            return [];
        }

        $plans = [$default];
        foreach ((array) ($variant['plans'] ?? []) as $plan) {
            if (is_array($plan) && self::isPlan($plan) && $plan['id'] !== $default['id']) {
                $plans[] = $plan;
            }
        }

        return $plans;
    }

    /**
     * The plan the line is sold on: the one with this id when the variant is
     * sold on it, otherwise the default; null when the variant is sold
     * one-off.
     *
     * Falling back rather than refusing is right for the readers that call
     * this -- the order, the settlement, the price -- because an id the
     * variant no longer offers can only come from a re-sync since the buyer
     * chose, and the default is the plan those readers priced the variant on
     * before choosing existed. The buyer's own choice is checked strictly,
     * with {@see self::offers()}, before it is ever stored.
     *
     * @param array<string, mixed>|null $variant
     * @return array<string, mixed>|null
     */
    public static function chosen(?array $variant, ?string $planId): ?array
    {
        $plans = self::of($variant);

        foreach ($plans as $plan) {
            if ($planId !== null && $plan['id'] === $planId) {
                return $plan;
            }
        }

        return $plans[0] ?? null;
    }

    /**
     * Whether the variant is sold on this plan.
     *
     * @param array<string, mixed>|null $variant
     */
    public static function offers(?array $variant, string $planId): bool
    {
        foreach (self::of($variant) as $plan) {
            if ($plan['id'] === $planId) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $plan */
    private static function isPlan(array $plan): bool
    {
        return is_string($plan['id'] ?? null) && $plan['id'] !== '';
    }
}
