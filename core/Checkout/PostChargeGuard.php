<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Checkout;

use AsterMD\Storefront\Support\CardScrubber;
use AsterMD\Storefront\Support\OperatorLog;

/**
 * The discipline every write that happens after the money may have moved is
 * subject to.
 *
 * **Past the charge that reverses completely.** Once the payment adapter has
 * returned, the money may have moved, and from that point no failure of ours
 * may reach the buyer as a failure: an error page in front of someone whose
 * card was just debited is the one outcome that leaves nobody with a record of
 * the order. Every write after the call therefore runs in here, which logs
 * what could not be written as a reconciliation item and lets the order stand.
 *
 * A collaborator rather than a private method, because the checkout charge is
 * no longer the only charge on a journey: an upsell charges the stored
 * instrument the placement left behind, and everything it writes afterwards is
 * past the same point of no return. Two copies of this rule would drift, and
 * the direction they drift in is a buyer seeing a 500 with a debited card.
 */
final class PostChargeGuard
{
    public function __construct(private readonly OperatorLog $log)
    {
    }

    /**
     * One step that happens after the money may have moved.
     *
     * Loud rather than silent: a write that could not be made here is an order
     * whose local record is incomplete, and the log line is the only thing
     * that will ever tell an operator to go and look. Loud rather than fatal
     * for the reason the class docblock gives — the buyer is holding a charged
     * card, and an error page in front of them loses the order entirely.
     *
     * `\Throwable` rather than `\Exception`, because a `TypeError` from a shape
     * that moved under a collaborator is the same problem as a dead database
     * and has the same right answer.
     *
     * @param string  $step      names the write, so the log line says which one to go and repair
     * @param ?string $reference the provider's own order reference, which is the only identifier
     *                           every other party in this system knows — null on the paths where
     *                           there is no order to point at, which still deserve a line
     * @param \Closure(): mixed $write
     */
    public function run(string $step, ?string $reference, \Closure $write): void
    {
        try {
            $write();
        } catch (\Throwable $error) {
            $this->log->error('checkout.post_charge_write_failed', [
                'step' => $step,
                'reference' => $reference,
                // An exception message is foreign text that can quote the
                // statement it failed on, so it is scrubbed rather than
                // trusted. {@see OperatorLog} now scrubs every value it writes,
                // which makes this pass a second one and not the only one — it
                // stays because the cost is a regex over one string on a path
                // that is already failing, and because the thing it contains
                // reaches a durable file. A defence whose only proof is that
                // some other class still performs it is not one worth removing
                // to save a function call.
                'reason' => CardScrubber::scrub($error->getMessage()),
            ]);
        }
    }
}
