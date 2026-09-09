<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Observability;

use AsterMD\Storefront\Checkout\CheckoutEventReporter;
use AsterMD\Storefront\Checkout\Totals;

/**
 * A checkout event port that records what it was handed and does nothing else.
 *
 * The decorator under test may not change what happens, so what a test of it
 * mostly needs is proof that every argument arrived untouched — including the
 * ones the log line deliberately drops. Recording the calls verbatim is what
 * makes "dropped from the line" and "dropped on the way through" tellable
 * apart, which is the whole distinction `[20.14]` turns on.
 *
 * Not `final`, so a test that needs one method to fail can override it.
 */
class RecordingCheckoutEventReporter implements CheckoutEventReporter
{
    /** @var list<array<int, mixed>> the method name followed by its arguments, in call order */
    public array $calls = [];

    public function checkoutVisited(?string $sessionUuid, Totals $totals): void
    {
        $this->calls[] = ['checkoutVisited', $sessionUuid, $totals];
    }

    public function orderPlaced(?string $sessionUuid, Totals $totals, string $paymentMethod, array $orderReferences): void
    {
        $this->calls[] = ['orderPlaced', $sessionUuid, $totals, $paymentMethod, $orderReferences];
    }

    public function orderDeclined(
        ?string $sessionUuid,
        Totals $totals,
        string $paymentMethod,
        ?string $reference,
        string $reason,
    ): void {
        $this->calls[] = ['orderDeclined', $sessionUuid, $totals, $paymentMethod, $reference, $reason];
    }

    public function treatmentsSynced(?string $sessionUuid, array $orderReferences): void
    {
        $this->calls[] = ['treatmentsSynced', $sessionUuid, $orderReferences];
    }

    public function upsellOffered(?string $sessionUuid, string $slug, string $name): void
    {
        $this->calls[] = ['upsellOffered', $sessionUuid, $slug, $name];
    }

    public function upsellAccepted(?string $sessionUuid, string $slug, string $name): void
    {
        $this->calls[] = ['upsellAccepted', $sessionUuid, $slug, $name];
    }

    public function upsellDeclined(?string $sessionUuid, string $slug, string $name): void
    {
        $this->calls[] = ['upsellDeclined', $sessionUuid, $slug, $name];
    }

    public function orderBump(?string $sessionUuid, string $slug, bool $accepted): void
    {
        $this->calls[] = ['orderBump', $sessionUuid, $slug, $accepted];
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
        $this->calls[] = [
            'journeyCompleted',
            $sessionUuid,
            $anyOrderPlaced,
            $orderValueCents,
            $paidTotalCents,
            $paymentMethod,
            $orderReferences,
            $opportunityId,
        ];
    }
}
