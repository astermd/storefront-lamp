<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use AsterMD\Storefront\Forms\LeadGateway;

/**
 * In-memory {@see LeadGateway} for exercising {@see \AsterMD\Storefront\Forms\LeadWriter}
 * without an EMR. Every call is recorded so a test can assert exactly how many
 * creates versus updates happened, in what order, and with which payload — the
 * distinction the readiness threshold turns on.
 *
 * A call is recorded even when a failure flag makes it fail, because what the
 * log answers is which calls were *attempted* — "the threshold stopped this"
 * and "the EMR rejected this" have to stay distinguishable.
 *
 * The failure flags are mutable rather than constructor-set so one test can
 * fail a write and then let the retry succeed.
 */
final class FakeLeadGateway implements LeadGateway
{
    /** @var list<array<string, mixed>> */
    public array $creates = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $updates = [];

    public bool $failCreate = false;

    public bool $failUpdate = false;

    public string $newId = 'opp-1';

    public function create(array $payload): ?string
    {
        $this->creates[] = $payload;

        return $this->failCreate ? null : $this->newId;
    }

    public function update(string $opportunityId, array $payload): bool
    {
        $this->updates[] = [$opportunityId, $payload];

        return !$this->failUpdate;
    }

    public function writes(): bool
    {
        return true;
    }
}
