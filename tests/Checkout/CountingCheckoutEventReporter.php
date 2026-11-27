<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Checkout;

use AsterMD\Storefront\Checkout\CheckoutEventReporter;
use AsterMD\Storefront\Checkout\Totals;
use AsterMD\Storefront\Payment\PaymentDescriptor;

/**
 * A reporter that counts what reached the EMR, so a case can assert on the
 * *number* of reports rather than only on their effects.
 *
 * That distinction is the point: a second report of an order event rewrites
 * the same checkout record, so it changes nothing observable at that record
 * and can only be caught here.
 */
final class CountingCheckoutEventReporter implements CheckoutEventReporter
{
    public int $visits = 0;

    /** @var list<array{event: string, total_cents: int, product_id: ?string, payment_method: ?string}> */
    public array $details = [];

    /** @var list<array{reference: ?string, reason: string}> */
    public array $declines = [];

    /** @var list<list<string>> */
    public array $placements = [];

    /** How the last placed order said it was paid for. */
    public ?PaymentDescriptor $lastPlacedPayment = null;

    /** How the last refused order said it was being paid for. */
    public ?PaymentDescriptor $lastDeclinedPayment = null;

    public function checkoutVisited(?string $sessionUuid, Totals $totals): void
    {
        ++$this->visits;
    }

    public function addedShippingInfo(?string $sessionUuid, Totals $totals, ?string $productId): void
    {
        $this->details[] = ['event' => 'added_shipping_info', 'total_cents' => $totals->totalCents, 'product_id' => $productId, 'payment_method' => null];
    }

    public function addedPaymentInfo(?string $sessionUuid, Totals $totals, ?string $productId, string $paymentMethod): void
    {
        $this->details[] = ['event' => 'added_payment_info', 'total_cents' => $totals->totalCents, 'product_id' => $productId, 'payment_method' => $paymentMethod];
    }

    /** @param list<string> $orderReferences */
    public function orderPlaced(?string $sessionUuid, Totals $totals, string $paymentMethod, array $orderReferences, ?PaymentDescriptor $payment = null): void
    {
        $this->placements[] = $orderReferences;
        $this->lastPlacedPayment = $payment;
    }

    public function orderDeclined(
        ?string $sessionUuid,
        Totals $totals,
        string $paymentMethod,
        ?string $reference,
        string $reason,
        ?PaymentDescriptor $payment = null,
    ): void {
        $this->declines[] = ['reference' => $reference, 'reason' => $reason];
        $this->lastDeclinedPayment = $payment;
    }

    /** @param list<string> $orderReferences */
    public function treatmentsSynced(?string $sessionUuid, array $orderReferences, ?PaymentDescriptor $payment = null): void
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
