<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Reconciliation;

use AsterMD\Storefront\Checkout\CheckoutEventReporter;
use AsterMD\Storefront\Checkout\Totals;
use AsterMD\Storefront\Repository\OrderRepository;

/**
 * A {@see CheckoutEventReporter} that behaves like the EMR-backed one without
 * a network anywhere near it.
 *
 * It is a stand-in for the real reporter's *observable* contract rather than
 * for its internals, and that contract is the only thing the sweep is allowed
 * to depend on: a sync the EMR accepted stamps `orders.treatment_reference`, a
 * sync that failed leaves it null, and nothing about either is returned to the
 * caller (`[20.1]`). So this fake stamps rows through the same repository the
 * real one uses, and the sweep reads the outcome the same way an operator
 * would — off the row.
 *
 * `$failing` names the references the EMR refuses; `$throwing` makes the call
 * itself blow up, which the interface forbids and the sweep must survive
 * anyway.
 */
final class RecordingTreatmentReporter implements CheckoutEventReporter
{
    /** @var list<array{session: ?string, references: list<string>}> */
    public array $syncs = [];

    /**
     * @param list<string> $failing references the EMR will not accept
     */
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly array $failing = [],
        private readonly bool $throwing = false,
        private readonly string $treatmentId = '6a8c72619f9b46188233413b',
    ) {
    }

    public function treatmentsSynced(?string $sessionUuid, array $orderReferences): void
    {
        $this->syncs[] = ['session' => $sessionUuid, 'references' => $orderReferences];

        if ($this->throwing) {
            throw new \RuntimeException('the EMR was unreachable');
        }

        if ($sessionUuid === null || $orderReferences === []) {
            return;
        }

        foreach ($orderReferences as $reference) {
            if (in_array($reference, $this->failing, true)) {
                // One refusal fails the whole batch, as the endpoint does.
                return;
            }
        }

        foreach ($orderReferences as $reference) {
            $row = $this->orders->findByReference($reference);
            if ($row !== null) {
                $this->orders->markTreatmentSynced($row['id'], $this->treatmentId);
            }
        }
    }

    public function checkoutVisited(?string $sessionUuid, Totals $totals): void
    {
    }

    /** @param list<string> $orderReferences */
    public function orderPlaced(?string $sessionUuid, Totals $totals, string $paymentMethod, array $orderReferences): void
    {
    }

    public function orderDeclined(?string $sessionUuid, Totals $totals, string $paymentMethod, ?string $reference, string $reason): void
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
