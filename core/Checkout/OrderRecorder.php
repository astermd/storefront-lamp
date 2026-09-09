<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Checkout;

use AsterMD\Storefront\Payment\OrderEnvelope;
use AsterMD\Storefront\Payment\PaymentCredential;
use AsterMD\Storefront\Payment\PlacementOutcome;

/**
 * The storefront's own record of what was bought (`[18.1]`).
 *
 * A port rather than a concrete class so {@see CheckoutService} depends on the
 * *fact* that a placement is recorded and not on the table it lands in. The
 * order of operations `[13.32]` fixes is the service's; where the row goes is
 * the recorder's.
 *
 * **No card ever crosses this boundary intact.** The credential is passed so
 * that an implementation can take {@see PaymentCredential::lastFour()} for a
 * receipt and a support call — four digits, and nothing more (`[15.8]`). An
 * implementation that wrote the whole number would be writing a card at rest.
 */
interface OrderRecorder
{
    /**
     * Records one placement — header, lines and consents — and returns the
     * local row id, or null when nothing was written.
     *
     * A null return is not an error the buyer may see: by the time this is
     * called the money has already moved, so an implementation that cannot
     * write must log and return null rather than throw (`[20.1]`). The guard
     * against charging something that could never be recorded runs earlier, at
     * the idempotency claim, which happens before the provider is called.
     *
     * @param list<ConsentRecord> $consents what the buyer agreed to, in the wording they were shown (`[26.6]`)
     * @param bool                $isUpsell whether this placement is a post-purchase add-on rather than the
     *                                      checkout order (`[16.12]`). Defaulted to the checkout case because
     *                                      that is the placement every caller but one is making, and because a
     *                                      row that wrongly claims to be an upsell would mis-attribute revenue
     *                                      in the one query the column exists to answer.
     */
    public function record(
        OrderEnvelope $order,
        PlacementOutcome $outcome,
        PaymentCredential $credential,
        array $consents,
        string $providerCategory,
        bool $isUpsell = false,
    ): ?int;
}
