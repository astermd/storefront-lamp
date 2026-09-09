<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Upsell;

/**
 * Everything the upsell page renders, assembled once on the server.
 *
 * The same division of labour {@see \AsterMD\Storefront\Checkout\CheckoutViewModel}
 * keeps: the template resolves nothing. The price here is the *decided* figure
 * — the one the charge will use — rather than a catalog price the template
 * could re-derive differently, because an offer whose page and whose charge
 * disagree about the money is the one defect a buyer notices on their
 * statement.
 *
 * `$acceptPath` and `$declinePath` are on the model rather than hardcoded in
 * the template so that the page is correct with JavaScript off (`[8.7]`'s
 * reasoning): both buttons are real form posts to real routes, and the routes
 * come from the flow definition rather than from a string in a `.twig` file.
 *
 * `$position` and `$total` are `[16.3]`'s one-offer-per-step made visible. They
 * count only offers that can actually be put, so a queue with a dead entry in
 * it does not promise the buyer a screen that never arrives.
 *
 * There is deliberately **no notice field**. The two things this flow ever has
 * to say — a flood refusal and a charge that may still be in flight — are
 * flashed and rendered by `partials/cart-notice.twig`, which the checkout
 * layout this page extends already includes. A second notice channel on the
 * model would be a second place a message could be shown, or silently not be.
 */
final class UpsellViewModel
{
    /** @param list<string> $bullets */
    public function __construct(
        public readonly string $key,
        public readonly string $eyebrow,
        public readonly string $headline,
        public readonly string $body,
        public readonly array $bullets,
        public readonly ?string $image,
        public readonly string $productName,
        public readonly int $priceCents,
        public readonly string $currency,
        public readonly string $acceptLabel,
        public readonly string $declineLabel,
        /** Small print under the two buttons, or null. {@see Upsell::$footnote} for why it is configured. */
        public readonly ?string $footnote,
        public readonly string $acceptPath,
        public readonly string $declinePath,
        public readonly int $position,
        public readonly int $total,
    ) {
    }
}
