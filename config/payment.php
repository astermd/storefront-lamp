<?php

/**
 * Payment behaviour that is not the provider's own credential block.
 *
 * The credentials live in `config/channel.generated.php`, written by
 * `theme:sync` and gitignored because they are live secrets. What is here is
 * everything a deployment chooses rather than inherits.
 *
 * `shipping_profile_id` has no home in the EMR channel payload and must be
 * set by hand. The configured provider refuses any order carrying a payment
 * action without one ("Shipping Method Required"), and refuses a profile that
 * does not belong to the campaign, so a wrong value fails every checkout
 * rather than degrading. `config:validate` checks it is present.
 */
return [
    // Falls back to the synced channel's provider_category. Set it only to
    // pin a deployment to one adapter regardless of what sync reports.
    'adapter' => null,

    'shipping_profile_id' => (int) ($env['PAYMENT_SHIPPING_PROFILE_ID'] ?? 1),

    'currency' => 'USD',

    // Whether an order takes the money at checkout or only reserves it.
    //
    //   'capture'   — create and charge in one call. The shipped default, and
    //                 what this storefront did before the setting existed.
    //   'authorize' — reserve the funds and stop. Something outside this
    //                 storefront decides when they are settled; until then the
    //                 order exists, the buyer has a receipt saying so, and
    //                 `bin/console payment:capture <reference>` is what takes
    //                 the money.
    //
    // A single product can override this upwards in
    // `config/products.overrides.php` ('settlement' => 'authorize'). It cannot
    // override it downwards: a cart is one order, and a cart holding anything
    // marked 'authorize' authorizes as a whole. Capturing a product a
    // deployment marked hold-until-event is a charge nobody asked for, and only
    // that direction needs a refund to undo.
    //
    // The provider has to be able to honour it. An adapter that does not
    // declare authorize-and-capture support refuses an authorize order before
    // the call rather than charging it; `bin/console config:validate` reports
    // that combination as an error so it is found before a buyer does.
    'settlement' => $env['PAYMENT_SETTLEMENT'] ?? 'capture',

    // Settings that belong to one payment provider, because only that provider
    // has the choice to make. Everything above this line applies whichever
    // adapter is configured.
    'checkout_champ' => [
        // Which of CheckoutChamp's two authorization mechanisms to use. Only
        // consulted when an order settles as 'authorize'; a charge-now order
        // takes the same path either way.
        //
        //   'qa'      — /order/import/ with forceQA:1 puts the order in PENDING
        //               review with the FULL ORDER AMOUNT held on the card, and
        //               /order/qa/ releases it. The provider recommends this,
        //               and it is the default: a later capture is far less
        //               likely to decline because the money is genuinely
        //               reserved.
        //   'preauth' — /order/preauth/ validates the card by charging a
        //               nominal amount and refunding it, then /order/import/
        //               settles. It does NOT reserve the order's value, so by
        //               the time you capture, the funds may be gone and the
        //               settle call can decline. The older mechanism, still
        //               supported.
        //
        // Deployment-wide, never per product: it is a property of how this
        // deployment is set up with its provider, and a cart cannot be half one
        // and half the other.
        'authorize_mode' => $env['PAYMENT_CC_AUTHORIZE_MODE'] ?? 'qa',
    ],

    'rate_limits' => [
        // Per client address, per fixed window. Deliberately generous: this
        // stops a stuck retry loop and a double-click storm, not a determined
        // attacker, and refusing an honest buyer their third attempt at a
        // mistyped card is the worse failure.
        'checkout.submit' => ['limit' => 8, 'window_seconds' => 300],
        'checkout.promo' => ['limit' => 20, 'window_seconds' => 300],

        // Accepting a post-purchase upsell charges a card, so it is bucketed
        // like a submit rather than like a read. Tighter than checkout's,
        // because there is nothing here for an honest buyer to retype: a
        // mistyped card earns several attempts, a one-click add-on does not,
        // and the offer is skipped rather than refused when the bucket is
        // spent — the buyer has already bought what they came for.
        'upsell.accept' => ['limit' => 6, 'window_seconds' => 300],
    ],

    // How long a claimed-but-unfinished attempt blocks a retry before the
    // guard assumes the first request died mid-flight.
    'attempt_stale_after_seconds' => 120,
];
