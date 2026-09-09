<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment;

/**
 * What happened when the order was submitted (`[13.24]`-`[13.28]`).
 *
 * **Three states, not two.** `[14.22]` frames the third as a Stripe concern,
 * but the configured provider here returns `response_code: 101` with a
 * redirect payload for 3-D Secure, PayPal and Klarna, so a two-state model
 * would have to call a challenge either a success or a failure and both are
 * wrong. Every adapter returns one of these three.
 *
 * `$reference` is present on a decline as well as a success: the recorded
 * provider creates the order and then fails the card, and that reference must
 * be captured and recorded against the decline (`[13.26]`) or a retry places a
 * second order the operator cannot reconcile with the first.
 *
 * `$reason` is buyer-safe and shown verbatim (`[13.28]`). `$rawStatus` is the
 * provider's own status for the operator log, never rendered.
 *
 * `$chargeDiscrepancy` is the fourth thing an outcome can carry and the only
 * one that is not a state: a placement whose charged total does not match the
 * total the storefront displayed. It hangs off a placed outcome rather than
 * being a state of its own because it is not an alternative to being placed --
 * the charge went through -- and because a fourth state would silently become
 * "not placed" everywhere a `match` on state already exists.
 *
 * `$reusableCredential` is the fifth thing an outcome can carry, and it hangs
 * off a *placed* outcome only. It is the handle a later charge on this journey
 * submits instead of a card (`[15.13]`), minted by the adapter from its own
 * response so that nothing above the provider boundary learns what is inside
 * it. A decline carries none: a vault entry that refused one charge is not a
 * credential worth keeping, and a placement is the only event that proves the
 * provider now holds an instrument it will accept.
 */
final class PlacementOutcome
{
    public const string PLACED = 'placed';

    public const string DECLINED = 'declined';

    public const string PENDING_ACTION = 'pending_action';

    private function __construct(
        public readonly string $state,
        public readonly ?string $reference,
        public readonly ?string $reason,
        public readonly ?string $rawStatus,
        public readonly ?string $actionUrl,
        public readonly ?ChargeDiscrepancy $chargeDiscrepancy = null,
        public readonly ?PaymentCredential $reusableCredential = null,
    ) {
    }

    public static function placed(
        string $reference,
        ?string $rawStatus = null,
        ?ChargeDiscrepancy $discrepancy = null,
        ?PaymentCredential $reusableCredential = null,
    ): self {
        return new self(self::PLACED, $reference, null, $rawStatus, null, $discrepancy, $reusableCredential);
    }

    public static function declined(?string $reference, string $reason, ?string $rawStatus = null): self
    {
        return new self(self::DECLINED, $reference, $reason, $rawStatus, null);
    }

    public static function pendingAction(string $reference, string $actionUrl, ?string $rawStatus = null): self
    {
        return new self(self::PENDING_ACTION, $reference, null, $rawStatus, $actionUrl);
    }

    /**
     * Whether the charge went through.
     *
     * **True even when the charged total is wrong.** A discrepancy can only be
     * measured after the provider has already taken the money, so the choice
     * is between recording an order that was charged at the wrong figure and
     * losing the record of a charge altogether. Only the first is recoverable:
     * an operator can refund a difference they can see, and can do nothing
     * about a charge with no order behind it and a buyer who was shown a
     * decline. The buyer reaches their receipt for the same reason -- their
     * card was debited, and telling them otherwise is a lie their statement
     * will contradict. Getting the money right is a human follow-up; that is
     * what {@see self::hasChargeDiscrepancy()} and the error-level log entry
     * beside it exist to start.
     *
     * Callers that care about the figure ask both questions. Folding the
     * discrepancy into this answer would turn a recoverable overcharge into an
     * unrecoverable one.
     */
    public function isPlaced(): bool
    {
        return $this->state === self::PLACED;
    }

    /** Whether this placement needs a human to reconcile what was charged. */
    public function hasChargeDiscrepancy(): bool
    {
        return $this->chargeDiscrepancy !== null;
    }
}
