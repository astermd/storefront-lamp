<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Observability;

use AsterMD\Storefront\Checkout\CheckoutEventReporter;
use AsterMD\Storefront\Checkout\Totals;

/**
 * `[20.9]` over the treatment sync and the §17 funnel events.
 *
 * The treatment sync is the boundary `[20.9]` names; the funnel events beside
 * it travel the same transport, fail the same way, and are what `[20.11]`'s
 * step-to-step conversion is measured from, so they are timed too rather than
 * being the one EMR call nobody can see.
 *
 * **The measurement includes the local audit-trail write, and that is stated
 * rather than corrected.** The port is one method per event and each
 * implementation decides for itself how many destinations that means — here
 * it is the EMR plus `[18.1]`'s local `events` row. Splitting the figure would
 * mean reaching past the port into an implementation this decorator does not
 * choose. The local half is a single indexed insert on the same connection the
 * request already holds, so what is being watched is still the remote call.
 *
 * **Every method returns void by contract**, so nothing here has an outcome to
 * describe: the port reports success and failure identically because an order
 * that was charged is not un-charged when the EMR does not hear about it
 * (`[20.1]`). What is left — that the call happened, and how long it took — is
 * still the difference between a funnel that stopped converting and one that
 * stopped reporting.
 *
 * Totals travel as integer cents and are logged as such. Order references are
 * counted, not listed: the count is what an outage asks about, and the
 * references belong to the order rows.
 */
final class InstrumentedCheckoutEventReporter implements CheckoutEventReporter
{
    public function __construct(
        private readonly CheckoutEventReporter $inner,
        private readonly BoundaryTimer $timer,
    ) {
    }

    public function checkoutVisited(?string $sessionUuid, Totals $totals): void
    {
        $this->report(Boundary::CheckoutEvent, 'checkout_visited', $sessionUuid, [
            'total_cents' => $totals->totalCents,
        ], fn () => $this->inner->checkoutVisited($sessionUuid, $totals));
    }

    public function orderPlaced(?string $sessionUuid, Totals $totals, string $paymentMethod, array $orderReferences): void
    {
        $this->report(Boundary::CheckoutEvent, 'order_placed', $sessionUuid, [
            'total_cents' => $totals->totalCents,
            'payment_method' => $paymentMethod,
            'orders' => count($orderReferences),
        ], fn () => $this->inner->orderPlaced($sessionUuid, $totals, $paymentMethod, $orderReferences));
    }

    public function orderDeclined(
        ?string $sessionUuid,
        Totals $totals,
        string $paymentMethod,
        ?string $reference,
        string $reason,
    ): void {
        // `$reason` is the provider's own sentence and is deliberately absent:
        // see the class docblock of {@see InstrumentedPaymentAdapter}. The
        // local trail records it, scrubbed, where an operator can look it up.
        $this->report(Boundary::CheckoutEvent, 'order_declined', $sessionUuid, [
            'total_cents' => $totals->totalCents,
            'payment_method' => $paymentMethod,
            'reference' => $reference,
        ], fn () => $this->inner->orderDeclined($sessionUuid, $totals, $paymentMethod, $reference, $reason));
    }

    public function treatmentsSynced(?string $sessionUuid, array $orderReferences): void
    {
        $this->report(Boundary::TreatmentSync, 'treatments_synced', $sessionUuid, [
            'orders' => count($orderReferences),
        ], fn () => $this->inner->treatmentsSynced($sessionUuid, $orderReferences));
    }

    public function upsellOffered(?string $sessionUuid, string $slug, string $name): void
    {
        $this->report(Boundary::CheckoutEvent, 'upsell_offered', $sessionUuid, [
            'slug' => $slug,
        ], fn () => $this->inner->upsellOffered($sessionUuid, $slug, $name));
    }

    public function upsellAccepted(?string $sessionUuid, string $slug, string $name): void
    {
        $this->report(Boundary::CheckoutEvent, 'upsell_accepted', $sessionUuid, [
            'slug' => $slug,
        ], fn () => $this->inner->upsellAccepted($sessionUuid, $slug, $name));
    }

    public function upsellDeclined(?string $sessionUuid, string $slug, string $name): void
    {
        $this->report(Boundary::CheckoutEvent, 'upsell_declined', $sessionUuid, [
            'slug' => $slug,
        ], fn () => $this->inner->upsellDeclined($sessionUuid, $slug, $name));
    }

    public function orderBump(?string $sessionUuid, string $slug, bool $accepted): void
    {
        $this->report(Boundary::CheckoutEvent, 'order_bump', $sessionUuid, [
            'slug' => $slug,
            'accepted' => $accepted,
        ], fn () => $this->inner->orderBump($sessionUuid, $slug, $accepted));
    }

    public function journeyCompleted(
        ?string $sessionUuid,
        bool $anyOrderPlaced,
        int $orderValueCents,
        int $paidTotalCents,
        ?string $paymentMethod,
        array $orderReferences,
        ?string $opportunityId,
    ): void {
        $this->report(Boundary::CheckoutEvent, 'journey_completed', $sessionUuid, [
            'any_order_placed' => $anyOrderPlaced,
            'order_value_cents' => $orderValueCents,
            'paid_total_cents' => $paidTotalCents,
            'payment_method' => $paymentMethod,
            'orders' => count($orderReferences),
            'opportunity' => $opportunityId,
        ], fn () => $this->inner->journeyCompleted(
            $sessionUuid,
            $anyOrderPlaced,
            $orderValueCents,
            $paidTotalCents,
            $paymentMethod,
            $orderReferences,
            $opportunityId,
        ));
    }

    /**
     * @param array<string, scalar|null> $context
     * @param \Closure(): void           $call
     */
    private function report(Boundary $boundary, string $operation, ?string $sessionUuid, array $context, \Closure $call): void
    {
        $this->timer->measure(
            $boundary,
            $call,
            null,
            ['operation' => $operation, 'session' => $sessionUuid] + $context,
        );
    }
}
