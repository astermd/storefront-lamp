<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Checkout;

use AsterMD\Storefront\Checkout\CheckoutEventReporter;
use AsterMD\Storefront\Checkout\Totals;

/**
 * A reporter that counts what reached the EMR, so a case can assert on the
 * *number* of reports rather than only on their effects.
 *
 * That distinction is the point: the funnel keeps one checkout record per
 * session and updates it in place, so a second report of the same event
 * changes nothing observable at the destination and can only be caught here.
 */
final class CountingCheckoutEventReporter implements CheckoutEventReporter
{
    public int $visits = 0;

    /** @var list<array{reference: ?string, reason: string}> */
    public array $declines = [];

    /** @var list<list<string>> */
    public array $placements = [];

    public function checkoutVisited(?string $sessionUuid, Totals $totals): void
    {
        ++$this->visits;
    }

    /** @param list<string> $orderReferences */
    public function orderPlaced(?string $sessionUuid, Totals $totals, string $paymentMethod, array $orderReferences): void
    {
        $this->placements[] = $orderReferences;
    }

    public function orderDeclined(
        ?string $sessionUuid,
        Totals $totals,
        string $paymentMethod,
        ?string $reference,
        string $reason,
    ): void {
        $this->declines[] = ['reference' => $reference, 'reason' => $reason];
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
