<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Domain;

use AsterMD\Storefront\Funnel\FunnelRules;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Verification\VerificationPlacement;

/**
 * Where this visitor should be right now (`[8.1]`).
 *
 * It is deliberately *not* a forward-only table. The guard asks this question
 * of a visitor who has just failed a precondition, so an answer that ignored
 * what they had already finished would send someone who completed a
 * questionnaire back to the start of it. Every branch below therefore reads
 * journey state as well as the cart — and every caller must hand it over.
 * The argument is optional only because a routing question can legitimately be
 * asked before a journey exists; passing null when one is at hand is how a
 * finished questionnaire gets asked for a second time.
 *
 * It is also the answer to "where does an unsatisfied step send someone",
 * which is why the guard asks this rather than walking the flow looking for
 * the earliest unsatisfied step: with an empty cart every funnel step is
 * unsatisfied, and only this decision knows what the right answer to that is.
 *
 * The rules it decides on — the plan check and the outstanding-form
 * resolution — are not implemented here. They live in {@see FunnelRules},
 * which {@see \AsterMD\Storefront\Funnel\StepPreconditions} calls as well, so
 * the routing decision and the guard that acts on it cannot hold different
 * opinions about the same cart. They previously held copies of each other and
 * had drifted; a shared rule is the only version of "these must agree" that a
 * later edit cannot quietly break.
 */
final class FunnelRouter implements StepRouter
{
    private readonly FunnelRules $rules;

    private readonly VerificationPlacement $placement;

    /**
     * Takes the catalog rather than the rules so that this class's collaborators
     * stay its own business: the rules are an implementation detail shared with
     * the guard, not a dependency the container has to know how to wire.
     *
     * `$verification` is `config/verification.php` and defaults to empty for
     * the same reason it does on the guard: an absent configuration and a
     * disabled one both mean there is no step to route anybody to.
     *
     * @param array<string, mixed> $verification
     */
    public function __construct(ProductCatalog $catalog, array $verification = [])
    {
        $this->rules = new FunnelRules($catalog);
        $this->placement = new VerificationPlacement($verification);
    }

    public function nextStep(Cart $cart, ?JourneyState $state = null): string
    {
        // A terminated journey outranks everything else, including an empty
        // cart. The line that triggered the stop is cleared when the visitor
        // starts over rather than the moment the rule fires, but even so the
        // cart can be emptied by other means — and routing a disqualified
        // visitor home would drop the explanation they are owed (`[10.46]`).
        if ($state !== null && $state->isDisqualified()) {
            return 'not_eligible';
        }

        if ($cart->isEmpty()) {
            // An empty cart on a journey that has bought something is the
            // *after* picture, not an idle visitor's: `[13.32]` clears the cart
            // the moment an order is placed. Answering "home" for it sends a
            // buyer who re-submits — or reloads a POST, or presses back — to
            // the front page with nothing to show for what they paid. The
            // receipt is what they are owed, and it is reachable precisely
            // because `order_placed` is satisfied for this journey, so the
            // destination cannot bounce them straight back off it.
            return $state !== null && $state->placedOrders !== [] ? 'receipt' : 'home';
        }

        // An Rx line with no chosen plan cannot be priced or ordered
        // (`[12.7]`). Home is where the mini-cart drawer shows the cart
        // intact; routing deeper would name a step whose own preconditions
        // this cart still fails.
        if (!FunnelRules::everyRxLineHasAPlan($cart)) {
            return 'home';
        }

        if ($this->rules->outstandingPrequalificationForm($cart, $state) !== null) {
            return 'prequalification';
        }

        if ($this->rules->outstandingIntakeForm($cart, $state) !== null) {
            return 'intake.medical';
        }

        // Identity verification, when this deployment runs it before payment
        // (`[22.13]`, `[22.14]`). Answering it here is what makes the step
        // reachable at all: a step no routing decision names can only be
        // typed into the address bar, and — worse — a guard that refuses
        // `/checkout/` for an unmet verdict computes its destination from this
        // method, so answering `checkout` for a visitor the guard is about to
        // turn away names the step they are already on. That is the
        // self-redirect branch, and it lands them on the home page having
        // finished a questionnaire.
        //
        // {@see VerificationPlacement::outstandingFor()} is the same object
        // the guard asks, for the reason the form rules above are shared:
        // agreement has to be structural rather than a coincidence of two
        // conditions written twice.
        if ($this->placement->outstandingFor($state)) {
            return 'verify';
        }

        return 'checkout';
    }
}
