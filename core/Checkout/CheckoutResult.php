<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Checkout;

use AsterMD\Storefront\Payment\PlacementOutcome;

/**
 * What one checkout submission came to.
 *
 * Every branch of {@see CheckoutService::submit()} answers with one of these,
 * including the branches that never reach the provider — a failed validation,
 * an ungranted blocking consent, a geo block, a refused rate limit. Those
 * carry a synthesised {@see PlacementOutcome::declined()} rather than a null
 * outcome, so a caller asking the one question that matters (`isPlaced()`)
 * gets a truthful answer without first having to ask which kind of failure it
 * was. `$outcome->rawStatus` names the branch for the operator log; it is
 * never rendered.
 *
 * `$errors` is keyed by checkout field name, exactly as
 * {@see BuyerValidator::validate()} keys its own, so the template can put each
 * message beside the control that earned it. `$notice` is the page-level
 * sentence that has no single field to sit beside: the provider's own decline
 * reason (shown verbatim, `[13.28]`), the geo block naming the products
 * (`[13.7]`), or the honest "we could not complete this" of a challenge.
 *
 * `$refusedBecause` is the one thing the outcome cannot express: the storefront
 * refused before the provider was reached, for a reason the buyer cannot fix
 * by editing the form. It exists so the HTTP layer can answer 429 to a flood
 * and 200 to a decline without pattern-matching on a message string.
 */
final class CheckoutResult
{
    /** Too many submissions from this address inside the window (`[13.8]`). */
    public const string REFUSED_RATE_LIMITED = 'rate_limited';

    /** An identical submission is with the provider right now (`[13.37]`). */
    public const string REFUSED_IN_FLIGHT = 'in_flight';

    /**
     * The attempt could not be claimed at all, so the order could not be made
     * recordable before the charge.
     *
     * This is the deliberate exception to `[20.1]`'s degrade-silently rule: a
     * charge nobody can reconcile is worse for the buyer than a checkout that
     * refuses and asks them to try again.
     */
    public const string REFUSED_UNRECORDABLE = 'unrecordable';

    /**
     * @param array<string, string> $errors checkout field name → the buyer-facing message
     */
    public function __construct(
        public readonly PlacementOutcome $outcome,
        public readonly Totals $totals,
        public readonly array $errors = [],
        public readonly ?string $notice = null,
        public readonly ?string $redirectTo = null,
        public readonly ?string $refusedBecause = null,
    ) {
    }

    /** Whether the buyer may be moved off the checkout page at all. */
    public function placed(): bool
    {
        return $this->outcome->isPlaced();
    }
}
