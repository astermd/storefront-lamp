<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Verification;

use AsterMD\Storefront\Journey\JourneyState;

/**
 * Where the identity step sits, and whether it is still holding this journey.
 *
 * `[22.13]` makes the placement a store-owner decision and `[22.16]` insists
 * that placement and blocking are two settings which "must not be conflated".
 * Honouring that means two different readers have to agree about one
 * configuration: {@see \AsterMD\Storefront\Domain\FunnelRouter} asks *where
 * should this visitor be*, and
 * {@see \AsterMD\Storefront\Funnel\StepPreconditions} asks *may they enter the
 * step they asked for*. Those are different questions with different answers,
 * and the answers have to be derived from one place.
 *
 * They previously were not, and the pair of defects that produced is worth
 * recording because both were invisible from either side alone. The router
 * never answered `verify` at all, so the step was reachable only by typing its
 * URL. And because the guard did refuse an unmet `verification_satisfied` on
 * `/checkout/` while the router still answered `checkout` for that visitor,
 * the guard's target was the step the visitor was already on — which takes its
 * self-redirect branch and lands them on the home page, with no explanation,
 * having passed a questionnaire.
 *
 * So the two questions live here as two methods with one configuration, and
 * the invariant between them is stated in {@see self::satisfied()}.
 */
final class VerificationPlacement
{
    /**
     * The placement that runs before any money moves, and the only one that
     * can gate a pre-payment step.
     *
     * The other three (`post_checkout`, `receipt`, `async`) all run at or
     * after checkout. Re-asking them at a pre-payment gate would strand a
     * buyer behind a step that comes *after* the one they are trying to
     * reach — which is not a stricter reading of `[22.16]`, it is a deadlock.
     */
    private const string PRE_PAYMENT_PLACEMENT = 'intake';

    /** @param array<string, mixed> $config `config/verification.php` */
    public function __construct(private readonly array $config = [])
    {
    }

    /**
     * Whether this deployment runs the step at all, in the funnel.
     *
     * Both halves are required and neither implies the other: a disabled
     * deployment has no step, and an enabled one placed after checkout has no
     * *pre-payment* step.
     */
    public function collectsBeforePayment(): bool
    {
        return ($this->config['enabled'] ?? false) === true
            && ($this->config['placement'] ?? null) === self::PRE_PAYMENT_PLACEMENT;
    }

    /**
     * Whether a failed verdict may stop the visitor advancing (`[22.16]`).
     *
     * Deliberately separate from {@see self::collectsBeforePayment()} rather
     * than folded into it. A deployment may want the step run and recorded and
     * still not want a provider outage to cost it orders — which, given the
     * recorded behaviour of the configured provider, is the only responsible
     * setting today.
     */
    public function blocks(): bool
    {
        return $this->collectsBeforePayment() && ($this->config['blocking'] ?? false) === true;
    }

    /**
     * Whether the step still has something to ask this journey.
     *
     * This is the router's question, and it is true in two cases. A journey
     * that has never been asked is the obvious one. The second is a journey
     * that answered and failed **under a blocking placement**: the step is not
     * finished with them, and answering anything else here is what produced
     * the home-page bounce described in the class docblock — the guard needs a
     * destination that is not the step it just refused.
     *
     * Under a non-blocking placement a recorded failure *is* an answer, and
     * the journey moves on. That is what non-blocking means.
     */
    public function outstandingFor(?JourneyState $state): bool
    {
        if (!$this->collectsBeforePayment()) {
            return false;
        }

        if ($state === null || $state->verification === null) {
            return true;
        }

        return $this->blocks() && !$state->verificationPassed();
    }

    /**
     * Whether verification permits this journey past a pre-payment gate.
     *
     * The guard's question, and the invariant that keeps it honest: this
     * answers false **only** where {@see self::outstandingFor()} answers true
     * *and* the placement blocks. A gate that refused someone the router would
     * not redirect is the deadlock this class exists to prevent, so the two
     * are written next to each other on purpose.
     *
     * Note what is deliberately not accepted: an inconclusive verdict does not
     * satisfy a blocking gate, because it is not a statement about the buyer
     * at all. Whether an inconclusive answer *should* stop somebody is a real
     * question — but it is a question about whether to block, and the answer
     * to it is the `blocking` setting, not a quiet widening here.
     */
    public function satisfied(?JourneyState $state): bool
    {
        if (!$this->blocks()) {
            return true;
        }

        return $state !== null && $state->verificationPassed();
    }
}
