<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Checkout;

use AsterMD\Storefront\Payment\AdapterCapabilities;

/**
 * Everything the checkout page renders, assembled once on the server.
 *
 * The template does no resolution of its own: a line's variant name, a bump's
 * effective price, whether the promo control exists at all — each is decided
 * here, for the same reason {@see \AsterMD\Storefront\Http\Middleware\TemplateGlobalsMiddleware}
 * assembles the cart drawer's shape rather than leaving it to Twig. A page
 * that computed a price in a template would be a second authority on money.
 *
 * `$capabilities` is on the view model rather than being flattened into two
 * booleans because `[15.4c]` and `[14.4]` are the same rule seen twice: the
 * page adapts to what the adapter declares, and the payment section is a slot
 * the adapter fills rather than a fixed block of markup. Flattening it would
 * mean every new capability that changes the page needs a new field here.
 *
 * `$prefill` is keyed by checkout field name — the same keys
 * {@see BuyerDetails::toArray()} produces and {@see Prefill::from()} returns —
 * so a rendered form and a submitted one are one key set read in opposite
 * directions. **The card is never among them** (`[15.8]`): a card echoed into
 * a `value` attribute lands in the browser's back-forward cache and in any
 * proxy that logs response bodies.
 */
final class CheckoutViewModel
{
    /**
     * @param array<string, string>       $prefill  checkout field name → the value to render
     * @param array<string, string>       $errors   checkout field name → the message to render beside it
     * @param list<array<string, mixed>>  $lines    the cart, in presentation shape
     * @param list<array<string, mixed>>  $bumps    the offers this cart earns (§27)
     * @param array<string, mixed>|null   $plans    the Rx line's variant selector, or null when there is no Rx line
     * @param list<ConsentDefinition>     $consents the controls §26 asks for, rendered unchecked
     */
    public function __construct(
        public readonly array $prefill,
        public readonly array $errors,
        public readonly ?string $notice,
        public readonly Totals $totals,
        public readonly array $lines,
        public readonly array $bumps,
        public readonly ?array $plans,
        public readonly array $consents,
        public readonly AdapterCapabilities $capabilities,
        public readonly ?Promotion $promotion,
        public readonly string $currency,
        public readonly int $itemCount,
    ) {
    }
}
