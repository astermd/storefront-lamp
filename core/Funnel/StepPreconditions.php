<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Funnel;

use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Verification\VerificationPlacement;

/**
 * The named predicates `config/funnel.php` refers to.
 *
 * Named rather than expressed as closures in the config file so the flow
 * stays declarative data an operator can read, and so an unknown name fails
 * loudly: a mistyped requirement that quietly evaluated true would leave a
 * step unguarded and look exactly like a step that had no requirements.
 *
 * A precondition is added once the code able to produce its underlying fact
 * exists: the order-placed precondition belongs beside whatever records that
 * fact. Declaring one before anything can evaluate it would make every funnel
 * page unreachable.
 *
 * `order_placed` is the one that had to wait. The upsell and receipt steps
 * could not be guarded at all while nothing placed orders, and they cannot be
 * guarded on the cart even now: `[13.32]` clears the cart the moment an order
 * is placed, so by the time a buyer reaches either page the cart is empty and
 * indistinguishable from a visitor who never had one. The placed order
 * recorded on the journey is the only fact left to test — durable, server-side
 * and unforgeable from the browser, which a "just bought something" flag in
 * the PHP session would not have been.
 *
 * The two form preconditions are deliberately **satisfied-or-not-required**
 * rather than "completed". A cart with nothing to ask goes straight to
 * checkout (`[8.3]`), and a channel that folds its eligibility questions into
 * the intake form authors no separate pre-qualification form at all — so
 * demanding completion of a form that does not exist would make checkout
 * permanently unreachable rather than guarded.
 *
 * A journey that could not be loaded at all is a **third** answer, not a
 * synonym for "not satisfied yet". {@see \AsterMD\Storefront\Journey\JourneyStore::state()}
 * returns null when session resolution failed — a database or EMR outage, or
 * a deployment with analytics switched off — and at that point every durable
 * fact about the journey is *unknowable* rather than known-false. Each
 * precondition below therefore picks its own direction of failure for that
 * case, and says why: the two questionnaire gates stay shut (`[8.6]`), and
 * `order_placed` opens (`[20.1]`). Answering them all the same way is what
 * locked a buyer who had just paid out of their own receipt.
 *
 * The rules themselves — the plan check and the outstanding-form resolution —
 * live in {@see FunnelRules}, which {@see \AsterMD\Storefront\Domain\FunnelRouter}
 * calls too. That is deliberate and load-bearing: the guard decides whether a
 * visitor may stay, the router decides where they go instead, and a funnel
 * where those two disagree either strands the visitor or leaves a step
 * unguarded. Sharing the rule is what makes disagreement impossible rather
 * than merely unintended.
 *
 * The catalog is reached through the {@see ProductCatalog} port rather than
 * the concrete provider, the same way {@see \AsterMD\Storefront\Domain\CartRules}
 * and {@see \AsterMD\Storefront\Domain\FunnelRouter} reach it.
 */
final class StepPreconditions
{
    private readonly FunnelRules $rules;

    private readonly VerificationPlacement $placement;

    /**
     * Takes the catalog rather than the rules for the same reason
     * {@see \AsterMD\Storefront\Domain\FunnelRouter} does: the shared rule is
     * an implementation detail of the pair, not a fourth thing the container
     * has to know how to build and hand to both.
     *
     * `$verification` is `config/verification.php`, and it defaults to empty
     * rather than being required because an absent configuration and a
     * disabled one mean the same thing here: nothing to ask, so nothing to
     * gate on. That default is what lets a caller who has no interest in
     * identity verification build this class without knowing it exists.
     *
     * @param array<string, mixed> $verification
     */
    public function __construct(ProductCatalog $catalog, array $verification = [])
    {
        $this->rules = new FunnelRules($catalog);
        $this->placement = new VerificationPlacement($verification);
    }

    public function satisfied(string $requirement, Cart $cart, ?JourneyState $state): bool
    {
        return match ($requirement) {
            'cart_not_empty' => !$cart->isEmpty(),
            'plan_chosen_for_every_rx_line' => FunnelRules::everyRxLineHasAPlan($cart),
            'prequalification_satisfied' => $this->prequalificationSatisfied($cart, $state),
            'intake_satisfied' => $this->intakeSatisfied($cart, $state),
            'order_placed' => self::orderPlaced($state),
            'verification_satisfied' => $this->verificationSatisfied($state),
            default => throw new \InvalidArgumentException(sprintf('Unknown funnel precondition "%s".', $requirement)),
        };
    }

    /**
     * Whether identity verification permits this visitor to go on (`[22.16]`).
     *
     * Delegated rather than decided here, for the reason
     * {@see \AsterMD\Storefront\Funnel\FunnelRules} is delegated to by this
     * class and the router alike: the routing decision and the guard have to
     * agree about one configuration, and they previously agreed only by
     * writing the same conditions twice. {@see VerificationPlacement} states
     * the invariant between the two questions.
     */
    private function verificationSatisfied(?JourneyState $state): bool
    {
        return $this->placement->satisfied($state);
    }

    /**
     * Whether the intake questionnaire this cart calls for has been completed
     * — or whether there was never one to complete (`[8.3]`).
     *
     * A disqualified journey is never satisfied, whatever its answers say. A
     * hard stop is the server's verdict (`[10.44a]`), and a visitor who
     * finished the form before the rule fired must not be let through on the
     * strength of having finished it.
     */
    private function intakeSatisfied(Cart $cart, ?JourneyState $state): bool
    {
        if ($this->rules->intakeForms($cart) === []) {
            return true;
        }

        if ($state === null) {
            return false;
        }

        return !$state->isDisqualified() && $this->rules->outstandingIntakeForm($cart, $state) === null;
    }

    /**
     * Whether the dedicated eligibility step has been completed, for the
     * products that ask for one (`[8.2]`).
     *
     * Pre-qualification is opt-in twice over, and {@see FunnelRules} is where
     * "twice over" is defined — including the part that used to differ here:
     * both declarations have to come from the same cart line. Reading one
     * line's opt-in against another line's form invented a gate no product had
     * declared, which stranded a visitor the router was never going to send to
     * that step; reading it the other way let a form completed for one line
     * stand in for a different line's outstanding one, which left the
     * eligibility step unguarded.
     */
    private function prequalificationSatisfied(Cart $cart, ?JourneyState $state): bool
    {
        if ($this->rules->prequalificationForms($cart) === []) {
            return true;
        }

        if ($state === null) {
            return false;
        }

        return !$state->isDisqualified() && $this->rules->outstandingPrequalificationForm($cart, $state) === null;
    }

    /**
     * Whether this journey has bought something — assumed when there is no
     * journey to ask.
     *
     * The one precondition that opens rather than closes on an unknowable
     * journey, and the asymmetry is the whole point of distinguishing the two.
     * By the time the receipt or the upsell is requested the order is placed
     * and the card is charged; refusing a buyer their own receipt because the
     * database blinked is a worse outcome than showing a stranger a page that,
     * with no journey behind it, has no order on it to show. The two
     * questionnaire gates make the opposite call for the opposite reason: an
     * outage does not make an uncollected medical form safe (`[8.6]`), and
     * nothing irreversible has happened yet to the visitor those gates stop.
     *
     * This is also the only behaviour that works at all for a deployment with
     * EMR analytics switched off, where no journey is ever minted: a
     * fail-closed `order_placed` would make the receipt permanently
     * unreachable rather than guarded.
     */
    private static function orderPlaced(?JourneyState $state): bool
    {
        return $state === null || $state->placedOrders !== [];
    }
}
