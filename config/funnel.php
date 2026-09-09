<?php

/**
 * The funnel: which URL is which step, and what must already be true before a
 * visitor may enter it.
 *
 * Preconditions are evaluated on every request to a listed path. When one is
 * not satisfied the visitor is redirected to the step the routing decision
 * says they should be on instead, so no page has to defend itself and no deep
 * link can drop someone into checkout with an empty cart.
 *
 * Requirement names must exist in AsterMD\Storefront\Funnel\StepPreconditions;
 * an unknown name is an error rather than a silently-skipped check.
 */
return [
    'steps' => [
        'home' => ['path' => '/', 'requires' => []],

        'prequalification' => ['path' => '/intake/eligibility/', 'requires' => ['cart_not_empty']],
        'intake' => ['path' => '/intake/', 'requires' => ['cart_not_empty', 'prequalification_satisfied']],
        'intake.medical' => ['path' => '/intake/medical/', 'requires' => ['cart_not_empty', 'prequalification_satisfied']],

        // Identity verification (`[22.13]`). It is listed here unconditionally
        // and gated by configuration rather than by its presence in this
        // table, because a step that appears and disappears cannot be
        // guarded: FlowDefinition::stepForPath() would answer null for
        // /verify/ whenever it was configured away, and an unlisted path is an
        // unguarded one.
        //
        // So the step is always a step, and config/verification.php decides
        // whether it asks anything. `[22.14]`'s four placements are read by
        // StepPreconditions, not expressed here -- this entry is the step's
        // position under the `intake` placement, which is the one `[22.17]`
        // recommends and the one this deployment ships.
        'verify' => ['path' => '/verify/', 'requires' => ['cart_not_empty', 'prequalification_satisfied', 'intake_satisfied']],

        // Pre-qualification is listed here as well as on the intake steps, and
        // not because checkout sits behind them: form submission is not a
        // funnel step and carries no guard, so the medical form can be
        // completed without the eligibility one ever being asked. Without this
        // line a deep link to checkout skips it entirely.
        //
        // `verification_satisfied` answers true whenever verification is
        // disabled, non-blocking, or placed anywhere but `intake` -- so this
        // line changes nothing for the shipped configuration and is what makes
        // a blocking pre-payment placement actually block. `[22.16]` is
        // explicit that placement and blocking are separate settings, and this
        // is the seam where they stop being separate and become one answer.
        'checkout' => ['path' => '/checkout/', 'requires' => ['cart_not_empty', 'plan_chosen_for_every_rx_line', 'prequalification_satisfied', 'intake_satisfied', 'verification_satisfied']],

        // A terminated journey is routed here and has to be able to stay, so
        // it carries no requirements of its own — anything demanded here
        // would bounce the visitor off the page explaining why they stopped.
        'not_eligible' => ['path' => '/not-eligible/', 'requires' => []],

        // An order must exist before either of these is reachable, and the
        // cart is cleared the moment one is placed — so neither can be
        // guarded on the cart. `order_placed` reads the placed order recorded
        // on the journey, which is what survives that clearing.
        'upsell' => ['path' => '/upsell/', 'requires' => ['order_placed']],
        'receipt' => ['path' => '/thank-you/', 'requires' => ['order_placed']],
    ],
];
