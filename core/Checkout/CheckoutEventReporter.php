<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Checkout;

/**
 * Where a checkout's funnel events go (§17), behind one port.
 *
 * The invariant every implementation owes: **tracking, analytics and
 * reporting must never break the storefront (`[20.1]`, `[10.26]`)**. An order
 * that was charged is not un-charged because the EMR did not hear about it, so
 * no method here may throw and none returns anything the checkout path could
 * branch on. Payment and eligibility stop the buyer; reporting does not.
 *
 * `$sessionUuid` is nullable throughout, and null means "do not report".
 * Analytics being off, or an EMR that could not mint a session, must not stop
 * a purchase (`[20.1]`), and inventing an identifier so the
 * payload looks complete is forbidden outright (`[20.8]`) — an order carrying
 * a synthetic id can never be reconciled back to anything.
 *
 * Totals arrive as {@see Totals}, in integer cents, because that is the only
 * representation of money this codebase has. Converting to the decimal dollars
 * one API documents is the implementation's business and happens at that
 * boundary alone.
 */
interface CheckoutEventReporter
{
    /**
     * The checkout page was reached.
     *
     * Recorded on render rather than on submit because the EMR's checkout
     * event is one record per session, created once and updated in place: an
     * update with no create before it is refused, so the funnel has to be
     * opened here or every order event is lost.
     */
    public function checkoutVisited(?string $sessionUuid, Totals $totals): void;

    /**
     * @param list<string> $orderReferences the provider's own references for the orders placed
     */
    public function orderPlaced(?string $sessionUuid, Totals $totals, string $paymentMethod, array $orderReferences): void;

    /**
     * A decline, with the reference the provider created before it failed the
     * card (`[13.26]`) and the reason it gave.
     */
    public function orderDeclined(
        ?string $sessionUuid,
        Totals $totals,
        string $paymentMethod,
        ?string $reference,
        string $reason,
    ): void;

    /**
     * Tells the EMR about orders the aggregator has already charged (`[17.2]`,
     * `[17.4]`).
     *
     * A batch rather than one call per order, because the EMR keys a treatment
     * record on the session and accumulates the order references into it: two
     * calls for two orders and one call for both leave the same single record.
     * Measured, not assumed — which is also why calling this twice for an
     * order that has already been reported is safe.
     *
     * An empty batch must not be sent. The endpoint answers 400 for one ("No
     * suborders found in any of the provided orders"), so a caller with nothing
     * to report would buy a warning line about nothing.
     *
     * The first-touch source `[17.4]` forwards is the implementation's to
     * resolve, not the caller's to pass: it belongs to the journey, and a
     * reporter that took it as an argument would make every call site a place
     * that has to remember to read journey state before it is wiped.
     *
     * @param list<string> $orderReferences the provider's own references, in the order they were placed
     */
    public function treatmentsSynced(?string $sessionUuid, array $orderReferences): void;

    /** An offer was shown (`[16.7]`): the product identifier and the name the buyer read. */
    public function upsellOffered(?string $sessionUuid, string $slug, string $name): void;

    /** The buyer said yes and the charge went through (`[16.7]`). */
    public function upsellAccepted(?string $sessionUuid, string $slug, string $name): void;

    /**
     * The offer did not become an order (`[16.7]`).
     *
     * One event for both ways that happens — the buyer declining, and a charge
     * that was refused — because the buyer's own answer and the provider's are
     * the same fact from the funnel's point of view: the add-on was not sold.
     * Which of the two it was is `JourneyState::$upsellOutcomes`' business, and
     * that distinction stays there rather than being flattened into an event
     * name the EMR has no case for.
     */
    public function upsellDeclined(?string $sessionUuid, string $slug, string $name): void;

    /**
     * One order-bump decision on `[18.1]`'s local trail (`[27.13]`).
     *
     * Local only, and that is the whole design rather than a shortcut: the EMR's
     * checkout-event vocabulary has no bump case, and inventing one would either
     * overwrite the funnel's single record with a detail or invent an event the
     * EMR does not model. A bump is also not a second order -- it is an ordinary
     * line on the main charge -- so the funnel record that matters is already
     * the one the placement writes.
     *
     * @param bool $accepted true when the buyer took the offer, false when they withdrew it
     */
    public function orderBump(?string $sessionUuid, string $slug, bool $accepted): void;

    /**
     * The one funnel event that closes a checkout (`[17.5]`, `[18.1]`).
     *
     * Fired from the receipt, once per checkout, and it is the *outcome* of the
     * whole journey rather than of one placement: order-placed when at least
     * one order succeeded, order-declined when none did, carrying the
     * successful references or — when nothing succeeded — the declined ones.
     *
     * **The two money figures are not a {@see Totals}, deliberately.** Totals
     * is subtotal − discount, and a charge discrepancy breaks that identity:
     * the provider can take more than the pre-discount value, which `Totals`
     * cannot represent and would clamp away. The paid total here is the sum of
     * what was actually debited, read back from the order rows, so it is the
     * figure a reconciliation can be run against.
     *
     * @param bool         $anyOrderPlaced  whether the journey placed at least one order
     * @param int          $orderValueCents the pre-discount value of what was bought
     * @param int          $paidTotalCents  what was actually charged, summed over the placed orders
     * @param ?string      $paymentMethod   null when nothing was paid, and not to be invented for the payload
     * @param list<string> $orderReferences the successful references, or the declined ones when nothing succeeded
     * @param ?string      $opportunityId   the CRM link captured during the journey, read before the wipe (`[17.6]`)
     */
    public function journeyCompleted(
        ?string $sessionUuid,
        bool $anyOrderPlaced,
        int $orderValueCents,
        int $paidTotalCents,
        ?string $paymentMethod,
        array $orderReferences,
        ?string $opportunityId,
    ): void;
}
