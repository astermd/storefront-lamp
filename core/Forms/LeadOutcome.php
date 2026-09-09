<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * What one call to {@see LeadWriter::capture()} actually did.
 *
 * Two separate questions, deliberately not collapsed into one. `created()`
 * answers `[9.6]`'s "did a record actually get made *by this call*" — the
 * signal that arms the once-per-page-load capture, so a second blur on the
 * same load does not fire again. `opportunityId()` answers "is there a lead to
 * amend from here on", and is non-null in the much larger set of cases where
 * one already existed. A single boolean would conflate "nothing happened
 * because the payload was too thin" with "nothing happened because the lead
 * was already there", and those two want opposite follow-ups.
 *
 * The two named constructors exist because a bare `(bool, ?string)` pair reads
 * as nothing at the call site, and the invalid fourth combination — created
 * with no id — cannot be spelled through either of them.
 */
final class LeadOutcome
{
    private function __construct(
        private readonly bool $created,
        private readonly ?string $opportunityId,
    ) {
    }

    /** A lead was created by this call, and here is its id. */
    public static function createdLead(string $opportunityId): self
    {
        return new self(true, $opportunityId);
    }

    /**
     * No lead was created by this call. `$opportunityId` carries the one that
     * already existed, or null when there is still none — a payload below the
     * threshold, or a create that failed and must be retried (`[9.5]`).
     */
    public static function noneCreated(?string $opportunityId = null): self
    {
        return new self(false, $opportunityId);
    }

    public function created(): bool
    {
        return $this->created;
    }

    public function opportunityId(): ?string
    {
        return $this->opportunityId;
    }
}
