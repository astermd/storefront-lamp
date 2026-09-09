<?php

/**
 * Checkout order bumps — offers shown on the checkout page and accepted before
 * the order is submitted, so an accepted one becomes an ordinary line on the
 * main charge rather than a second one.
 *
 * These are not the post-purchase upsells in `upsells.php`. A bump costs the
 * buyer nothing extra to decline and carries no risk of abandonment mid-flow;
 * an upsell is charged separately after the first order settles.
 *
 * Keyed by the slug of the product whose presence in the cart triggers the
 * offer. A product may trigger several; several products may trigger the same
 * one, in which case it is shown once.
 *
 * Ships empty because no product in the current channel's catalog is a legal
 * bump: every one of them is a prescription, and an order carries at most one
 * prescription. Populate it when the catalog has something to cross-sell.
 *
 *     'tirzepatide-5mg' => [
 *         [
 *             'key'                  => 'anti-nausea-kit',
 *             'slug'                 => 'anti-nausea-kit',   // must exist in the catalog
 *             'variant_id'           => null,                 // null takes the default variant
 *             'headline'             => 'Add an anti-nausea kit',
 *             'body'                 => 'Ondansetron, for the first weeks of treatment.',
 *             'position'             => 1,
 *             'price_cents_override' => 1900,                 // null keeps the catalog price
 *         ],
 *     ],
 */
return [
    'max_on_page' => 3,
    'bumps' => [],
];
