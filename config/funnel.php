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

        // Checkout demands a payable cart, and no questionnaire. This
        // deployment collects the intake from the patient portal *after* the
        // order is placed, so `prequalification_satisfied` and
        // `intake_satisfied` are deliberately absent here: a buyer who chose
        // "Proceed to Checkout" on a product page must be able to pay with
        // both still outstanding.
        //
        // Both preconditions still exist and are still enforced on the steps
        // that genuinely need them -- `/intake/`, `/intake/medical/` and
        // `/verify/` above -- so the "Start Assessment" route is unchanged.
        // What changed is only that checkout stopped being their gate.
        //
        // `verification_satisfied` STAYS, and the asymmetry is deliberate.
        // Identity verification is also collected from the patient portal
        // here, but it is collected that way by *configuration*:
        // config/verification.php ships `enabled => false` (and `blocking =>
        // false`), so this precondition already answers true and this line
        // costs the buyer nothing. Deleting it would not change that -- it
        // would delete `[22.16]`, which says placement and blocking are
        // separate settings, and leave a deployment that turns blocking on
        // with a setting that silently does nothing. Turn it off in config,
        // where it is a decision; do not remove the seam that enforces it.
        //
        // Removing them here does NOT make the funnel-optimal route skip the
        // questionnaire: FunnelRouter::nextStep() still answers
        // `prequalification` or `intake.medical` for a cart that owes one, so
        // a visitor who never asked for checkout is still taken there.
        //
        // `not_disqualified` is what those three requirements were carrying
        // besides completion, and it does NOT come back with them. A hard
        // stop is the server's verdict on answers already given (`[10.45]`,
        // `[10.46]`), and it has to outlive the completion gate: an
        // unanswered questionnaire is not a refusal and must not block a
        // sale, but an answered one that ended in a stop must. Without this
        // line a visitor stopped for pregnancy or an MTC history could walk
        // from /not-eligible/ to /checkout/ and pay.
        'checkout' => ['path' => '/checkout/', 'requires' => ['cart_not_empty', 'plan_chosen_for_every_rx_line', 'not_disqualified', 'verification_satisfied']],

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
