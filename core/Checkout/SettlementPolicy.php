<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Checkout;

use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Payment\SettlementMode;
use AsterMD\Storefront\Support\OperatorLog;

/**
 * Whether an order takes the money or reserves it — decided once, here.
 *
 * Two layers, and they are two because they answer different questions. The
 * deployment default in `config/payment.php` answers "how does this storefront
 * settle", which is a commercial arrangement with one payment provider. The
 * per-product `settlement` key in `config/products.overrides.php` answers "does
 * this particular thing get charged before somebody has approved it", which is
 * a clinical or fulfilment fact about the product and varies inside one
 * deployment.
 *
 * **The per-product key lives in the override layer and nowhere else**, on the
 * same footing as `geo_blocks` and `requires_prequalification`: the EMR channel
 * payload has no concept of settlement, so `theme:sync` neither writes it nor
 * can overwrite it, and a re-sync cannot silently start charging a product that
 * was marked to hold.
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
}
