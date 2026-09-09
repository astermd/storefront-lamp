<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Observability;

use AsterMD\Storefront\Forms\LeadGateway;

/**
 * `[20.9]` over the opportunity boundary.
 *
 * `[20.10]` names this one specifically: a rising rate of swallowed
 * opportunity writes means leads are being lost with no error anywhere, and
 * nothing else in the system will say so.
 *
 * **`writes()` is not instrumented, and that is not an oversight.** It reaches
 * nothing — it answers whether this deployment writes leads at all — and
 * timing it would put a line in the log for every capture on a deployment that
 * makes no external call, which is how a log stops being read.
 *
 * The payload is the visitor's name, email and clinical answers. Only whether
 * it landed is recorded.
 */
final class InstrumentedLeadGateway implements LeadGateway
{
    public function __construct(
        private readonly LeadGateway $inner,
        private readonly BoundaryTimer $timer,
    ) {
    }

    public function create(array $payload): ?string
    {
        return $this->timer->measure(
            Boundary::OpportunityWrite,
            fn (): ?string => $this->inner->create($payload),
            static fn (?string $id): array => ['outcome' => $id === null ? 'failed' : 'created'],
            ['operation' => 'create'],
        );
    }

    public function update(string $opportunityId, array $payload): bool
    {
        return $this->timer->measure(
            Boundary::OpportunityWrite,
            fn (): bool => $this->inner->update($opportunityId, $payload),
            static fn (bool $accepted): array => ['outcome' => $accepted ? 'accepted' : 'refused'],
            ['operation' => 'update', 'opportunity' => $opportunityId],
        );
    }

    public function writes(): bool
    {
        return $this->inner->writes();
    }
}
