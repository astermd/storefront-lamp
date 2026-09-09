<?php

/**
 * Post-purchase upsells — offers presented *after* a successful charge, each
 * placing its own separate order against the instrument the provider already
 * holds.
 *
 * These are not the checkout order bumps in `cross-sells.php`. A bump is
 * accepted before submission and rides along on the main order, so declining
 * one costs nothing and there is no second charge. An upsell is charged
 * separately once the first order has settled, which is why a declined upsell
 * must never stop the flow: the buyer has already paid for what they came for.
 *
 * **Keyed by the upsell, not by the trigger.** Each entry names the products
 * whose purchase earns it in its own `offer_after` list, and an upsell earned
 * by two purchased products is offered once. This is the opposite arrangement
 * to `cross-sells.php`, which is keyed by trigger, and the reason is ordering:
 * exactly one upsell is presented per step and the order is *this file's
 * order*, so the offers have to be a single ordered list rather than something
 * assembled from several trigger buckets. There is deliberately no `position`
 * key — a second authority on order is a second thing that can disagree with
 * the first.
 *
 * The queue is built once, at checkout success, from what was just bought, and
 * stepped over the requests that follow. Configuration can therefore change
 * underneath a live queue: an entry that no longer resolves to a configured
 * product is skipped silently at the moment it would have been shown, and the
 * queue advances. `bin/console config:validate` is what catches a
 * misconfiguration loudly, before a buyer meets it.
 *
 * Ships empty, for the same reason `cross-sells.php` does: every product in
 * the current channel's catalog is a prescription, an order carries at most
 * one prescription, and none of the synced variants carries a provider
 * mapping — so nothing here could be charged for even if it were offered.
 * Populate it when the catalog has something to sell after the fact.
 *
 * A worked example, which `config:validate` would accept once the catalog held
 * a `daily-wellness-pack` with a provider mapping:
 *
 *     'daily-wellness-pack' => [
 *         'slug'                 => 'daily-wellness-pack',  // must exist in the catalog
 *         'variant_id'           => null,                    // null takes the default variant
 *         'offer_after'          => ['semaglutide', 'tirzepatide-5mg'],
 *         'eyebrow'              => 'Wait! Your Exclusive Offer!',
 *         'headline'             => 'Enhance Your Wellness Journey',
 *         'body'                 => 'Optimize your daily routine with our clinically backed supplement plan.',
 *         'bullets'              => [
 *             'Immune Support formulated for daily resilience',
 *             'Energy Boost without the midday crash',
 *             'Personalized for your unique health profile',
 *         ],
 *         'image'                => '/assets/img/upsell.png',
 *         'price_cents_override' => 899,                      // null keeps the catalog price
 *         'accept_label'         => 'Add to Order',
 *         'decline_label'        => 'No Thanks',
 *         'footnote'             => 'Billed monthly. Cancel by contacting support.',
 *     ],
 *
 * `footnote` is the small print under the two buttons and is **deliberately empty by
 * default**, because it is the one line only the deployment can write truthfully.
 * Whether an accepted offer bills again is a property of the provider offer it maps to,
 * not of this application — the offer this channel maps its products to reports itself
 * as recurring, so a page that said "one-time charge" would be lying, while the mockup's
 * "Cancel anytime" promises a cancellation surface the storefront does not have. Set it
 * to whatever is true of the offer behind it, or leave it unset and say nothing.
 */
return [
    'upsells' => [],
];
