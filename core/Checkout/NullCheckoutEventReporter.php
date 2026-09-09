<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Checkout;

/**
 * The reporter bound while nothing is reporting checkout events yet, and the
 * one every test binds — a test run must never reach the network.
 *
 * Reporting nothing is the correct degraded behaviour rather than a stub with
 * a gap in it: the whole port exists to guarantee that a silent reporting
 * failure cannot reach the buyer (`[20.1]`), and doing nothing silently is
 * exactly that guarantee held at its limit.
 */
final class NullCheckoutEventReporter implements CheckoutEventReporter
{
    public function checkoutVisited(?string $sessionUuid, Totals $totals): void
    {
    }

    /** @param list<string> $orderReferences */
    public function orderPlaced(?string $sessionUuid, Totals $totals, string $paymentMethod, array $orderReferences): void
    {
    }

    public function orderDeclined(
        ?string $sessionUuid,
        Totals $totals,
        string $paymentMethod,
        ?string $reference,
        string $reason,
    ): void {
    }

    /** @param list<string> $orderReferences */
    public function treatmentsSynced(?string $sessionUuid, array $orderReferences): void
    {
    }

    public function upsellOffered(?string $sessionUuid, string $slug, string $name): void
    {
    }

    public function upsellAccepted(?string $sessionUuid, string $slug, string $name): void
    {
    }

    public function upsellDeclined(?string $sessionUuid, string $slug, string $name): void
    {
    }

    public function orderBump(?string $sessionUuid, string $slug, bool $accepted): void
    {
    }

    /** @param list<string> $orderReferences */
    public function journeyCompleted(
        ?string $sessionUuid,
        bool $anyOrderPlaced,
        int $orderValueCents,
        int $paidTotalCents,
        ?string $paymentMethod,
        array $orderReferences,
        ?string $opportunityId,
    ): void {
    }
}
