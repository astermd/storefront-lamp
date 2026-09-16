<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment;

/**
 * What an adapter says it can do, so the storefront adapts its UI rather than
 * discovering a missing capability at runtime (`[14.4]`).
 *
 * `$discountScope` is a declared property, not a fixed rule (`[14.6a]`):
 * attaching the code to the first line only is right for providers whose
 * discounts are per line item and wrong for providers whose discount is an
 * order-level object.
 *
 * `$collectionSurface` travels with `$credentialStrategy` because they are one
 * decision, not two (`[15.4b]`): tokenization only reduces PCI scope when the
 * card is collected in a surface the storefront does not control, so an
 * adapter declaring `tokenization` must also declare where its fields live.
 *
 * `$requiredConfigKeys` is what `config:validate` checks before a deployment
 * goes live, and `$pciPosture` is what it reports to the operator (`[15.15]`).
 *
 * `$supportsOrderSearch` is declared rather than assumed for the same reason
 * as the rest: `[21.9a]`'s reverse sweep has to be able to tell "this provider
 * cannot be asked what orders it holds" from "this provider was asked and
 * found nothing". Those look identical at the call site and mean opposite
 * things, and a deployment whose adapter cannot answer must produce no sweep
 * rather than a clean bill of health.
 *
 * `$supportsAuthorizeCapture` is the one capability whose absence must stop a
 * checkout rather than adapt a page. Everything else here degrades: a provider
 * with no promotions hides the promo control, a provider with no order search
 * produces no sweep. An adapter handed an authorize envelope it cannot honour
 * has no degraded form -- charging instead is taking money the deployment
 * said to hold, and that is the failure this whole flag exists to make
 * impossible. It defaults to false so an adapter written before this existed
 * refuses rather than silently captures.
 */
final class AdapterCapabilities
{
    public const string SCOPE_LINE_ITEM = 'line-item';

    public const string SCOPE_ORDER_LEVEL = 'order-level';

    public const string STRATEGY_TOKENIZATION = 'tokenization';

    public const string STRATEGY_RAW_CARRY_FORWARD = 'raw-card-carry-forward';

    public const string STRATEGY_ORDER_REFERENCE = 'reference-order';

    public const string SURFACE_THEME_FIELDS = 'theme-fields';

    public const string SURFACE_PROVIDER_HOSTED = 'provider-hosted';

    /**
     * @param list<string> $requiredConfigKeys keys that must exist under `payment.<category>` for this adapter to run
     * @param list<string> $routingHintKeys    opaque provider-side ids passed through untouched (`[14.6c]`)
     */
    public function __construct(
        public readonly string $providerCategory,
        public readonly bool $supportsPromotions,
        public readonly string $discountScope,
        public readonly string $credentialStrategy,
        public readonly string $collectionSurface,
        public readonly array $requiredConfigKeys,
        public readonly array $routingHintKeys,
        public readonly string $pciPosture,
        public readonly bool $supportsRefund = false,
        public readonly bool $supportsRecurring = false,
        public readonly bool $supportsOrderSearch = false,
        public readonly bool $supportsAuthorizeCapture = false,
    ) {
    }

    /** Whether the theme renders its own card fields, or hosts the provider's (`[15.4c]`). */
    public function rendersOwnCardFields(): bool
    {
        return $this->collectionSurface === self::SURFACE_THEME_FIELDS;
    }
}
