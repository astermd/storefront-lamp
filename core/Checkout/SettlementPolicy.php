<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Checkout;

use AsterMD\Storefront\Domain\CartLine;
use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Domain\VariantPlans;
use AsterMD\Storefront\Payment\SettlementMode;
use AsterMD\Storefront\Support\OperatorLog;

/**
 * Whether an order takes the money or reserves it — decided once, here.
 *
 * Three layers, and they are three because they answer different questions.
 * The deployment default in `config/payment.php` answers "how does this
 * storefront settle", which is a commercial arrangement with one payment
 * provider. The per-product `settlement` key in `config/products.overrides.php`
 * answers "does this particular thing get charged before somebody has approved
 * it", which is a clinical or fulfilment fact about the product and varies
 * inside one deployment. And a line sold on a subscription plan settles as the
 * plan's first charge says ({@see self::forLines()}): the plan is the EMR's own
 * statement of whether its first payment is held or taken, so neither of the
 * other two speaks for it.
 *
 * **The per-product key lives in the override layer and nowhere else**, on the
 * same footing as `geo_blocks` and `requires_prequalification`: the EMR's
 * products have no concept of settlement, so `theme:sync` neither writes it nor
 * can overwrite it, and a re-sync cannot silently start charging a product that
 * was marked to hold. The plan's answer is the opposite case — it is the EMR's,
 * written by `theme:sync` onto the plan ({@see self::PLAN_KEY}), and a re-sync
 * is exactly how a changed plan reaches the checkout.
 *
 * **A cart resolves to one mode, and authorize wins** (`[13.19]`: the whole
 * cart is one order, so there is one action to choose). See
 * {@see SettlementMode::strictest()} for why that direction and not the other.
 * The consequence worth stating: adding a hold-until-event product to a cart
 * changes how the *other* products in it settle. That is the intended
 * behaviour — the alternative is splitting one cart across two provider orders,
 * which `[13.19]` forbids and which would give the buyer two charges to
 * reconcile against one receipt.
 *
 * **An unrecognised spelling falls back to the deployment default and is
 * logged**, rather than being guessed at in either direction. `authorize` is
 * opt-in, and an opt-in that activates on a typo is not one; equally, a typo
 * must not turn a deployment that authorizes into one that charges. Falling
 * back to the configured default is the only answer that does neither.
 * `bin/console config:validate` reports the same typo as an error, which is
 * where it is meant to be caught.
 */
final class SettlementPolicy
{
    /** The catalog field a deployment authors to hold funds on one product. */
    public const string PRODUCT_KEY = 'settlement';

    /** The field `theme:sync` writes on each catalog plan from the plan's first charge. */
    public const string PLAN_KEY = 'settlement';

    public function __construct(
        private readonly ProductCatalog $catalog,
        private readonly SettlementMode $default,
        private readonly OperatorLog $log,
    ) {
    }

    /**
     * Read the deployment default off the configured value.
     *
     * Separate from the constructor so the fallback is stated once and both
     * {@see \AsterMD\Storefront\Bootstrap\AppFactory} and the validator can
     * agree about what an unset or unrecognised value means.
     */
    public static function modeFromConfig(mixed $configured): SettlementMode
    {
        return SettlementMode::parse($configured) ?? SettlementMode::Capture;
    }

    /**
     * The mode for an order covering these product slugs.
     *
     * Total over the empty case, which is not hypothetical: an upsell envelope
     * carries exactly one line and a cart can be re-read after it was cleared.
     */
    public function forSlugs(string ...$slugs): SettlementMode
    {
        return SettlementMode::strictest($this->default, ...array_map($this->forSlug(...), $slugs));
    }

    /**
     * The mode for an order made of these cart lines.
     *
     * A line sold on a subscription plan asks for what its plan's first charge
     * says, and nothing else: not the deployment default, not its product's own
     * key. Nor does the default reach the attachments it brought into the cart
     * — a free supply asks for no settlement of its own, and letting the
     * default answer for it would overrule the plan by the back door. An
     * attachment whose product authors the key still asks for it.
     *
     * Every other line asks for what it would from {@see self::forSlugs()},
     * default folded in, so a cart with no plan line settles exactly as it
     * always has. The cart is still one order, and authorize still wins it
     * (`[13.19]`): a plan that takes the money beside a product marked to hold
     * makes the order hold.
     *
     * A plan synced before plans said how they settle has no answer to give,
     * and its line settles as it did then.
     */
    public function forLines(CartLine ...$lines): SettlementMode
    {
        $bySlug = [];
        foreach ($lines as $line) {
            $bySlug[$line->slug] = $line;
        }

        $modes = [];
        $planned = [];
        foreach ($lines as $line) {
            if ($line->isChild()) {
                continue;
            }

            $mode = $this->planMode($line);
            if ($mode !== null) {
                $modes[] = $mode;
                $planned[$line->slug] = true;
                continue;
            }

            $modes[] = $this->default;
            $modes[] = $this->forSlug($line->slug);
        }

        foreach ($lines as $line) {
            if (!$line->isChild()) {
                continue;
            }

            if (isset($planned[self::rootSlug($line, $bySlug)]) && !$this->authors($line->slug)) {
                continue;
            }

            $modes[] = $this->forSlug($line->slug);
        }

        return $modes === [] ? $this->default : SettlementMode::strictest(...$modes);
    }

    /**
     * The mode one product asks for, or the deployment default when it asks
     * for nothing.
     *
     * A slug with no product is the default rather than an error. Free
     * attachments and bundle children reach here by the same path as anything
     * else, and some of them are not catalog entries in their own right — a
     * throw would turn a line the buyer was never charged for into a failed
     * checkout.
     */
    public function forSlug(string $slug): SettlementMode
    {
        $product = $this->catalog->product($slug);

        if (!is_array($product) || !array_key_exists(self::PRODUCT_KEY, $product)) {
            return $this->default;
        }

        $authored = $product[self::PRODUCT_KEY];
        $parsed = SettlementMode::parse($authored);

        if ($parsed === null) {
            $this->log->error('checkout.settlement_unrecognised', [
                'slug' => $slug,
                'default' => $this->default->value,
            ]);

            return $this->default;
        }

        return $parsed;
    }

    /**
     * What the line's plan asks for: the plan the order will name, resolved
     * by the same rule the order resolves it by, so the line held is the line
     * charged. Null when the line is sold one-off, or on a plan synced before
     * plans said how they settle — and, logged like a product's typo, when an
     * override wrote something that is not a mode.
     */
    private function planMode(CartLine $line): ?SettlementMode
    {
        $variant = VariantPlans::variant($this->catalog->product($line->slug), $line->variantId);
        $plan = VariantPlans::chosen($variant, $line->planId);

        if ($plan === null || !array_key_exists(self::PLAN_KEY, $plan)) {
            return null;
        }

        $parsed = SettlementMode::parse($plan[self::PLAN_KEY]);
        if ($parsed === null) {
            $this->log->error('checkout.settlement_unrecognised', [
                'slug' => $line->slug,
                'plan_id' => $plan['id'],
                'default' => $this->default->value,
            ]);
        }

        return $parsed;
    }

    private function authors(string $slug): bool
    {
        $product = $this->catalog->product($slug);

        return is_array($product) && array_key_exists(self::PRODUCT_KEY, $product);
    }

    /**
     * The top-level line an attachment hangs from, however deep: an add-on's
     * own lab test is still part of the plan line that brought the add-on.
     *
     * @param array<string, CartLine> $bySlug
     */
    private static function rootSlug(CartLine $line, array $bySlug): string
    {
        $seen = [];
        while ($line->parentSlug !== null && isset($bySlug[$line->parentSlug]) && !isset($seen[$line->slug])) {
            $seen[$line->slug] = true;
            $line = $bySlug[$line->parentSlug];
        }

        return $line->slug;
    }
}
