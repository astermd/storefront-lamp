<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment;

/**
 * What happened when an authorized order was asked to settle.
 *
 * Three states for the same reason {@see OrderSearchResult} has three: "the
 * capture was attempted and refused" and "this provider cannot be asked to
 * capture at all" look identical at the call site and mean opposite things.
 * An operator whose capture failed has money still reserved and a window in
 * which to retry; an operator whose adapter never supported capture has an
 * authorize that should never have been placed, and the fix is configuration,
 * not a retry.
 *
 * **Nothing here is buyer-facing.** A capture runs long after the buyer has
 * left — the triggering event is outside this storefront — so `$reason` is an
 * operator's line, not `[13.28]`'s verbatim-to-the-buyer text. It is still
 * scrubbed by the adapter that builds it, because the provider's free text on
 * this path is the same gateway free text that path carries.
 *
 * There is no `$amountCents`. A capture settles the authorization the provider
 * already holds, and the figure it settles for is the provider's record rather
 * than anything this storefront could restate — reporting a number here would
 * invite it to be compared with a local total that was never what was
 * authorized. {@see ChargeDiscrepancy} is where a figure that disagrees
 * belongs, and it is raised at placement, when the comparison is meaningful.
 */
final readonly class CaptureOutcome
{
    public const string CAPTURED = 'captured';

    public const string FAILED = 'failed';

    public const string UNSUPPORTED = 'unsupported';

    private function __construct(
        public string $state,
        public ?string $reference,
        public ?string $reason,
    ) {
    }

    public static function captured(string $reference): self
    {
        return new self(self::CAPTURED, $reference, null);
    }

    /** @param string $reason an operator-facing code or scrubbed provider line, never shown to a buyer */
    public static function failed(?string $reference, string $reason): self
    {
        return new self(self::FAILED, $reference, $reason);
    }

    /**
     * This adapter does not do authorize-and-capture.
     *
     * Answered rather than thrown, on the same terms as
     * {@see OrderSearchResult::unsupported()}: a deployment whose provider
     * cannot capture must produce a stated refusal an operator can read, not a
     * crashed command (`[20.1]`).
     */
    public static function unsupported(): self
    {
        return new self(self::UNSUPPORTED, null, 'capture_unsupported');
    }

    public function isCaptured(): bool
    {
        return $this->state === self::CAPTURED;
    }
}
