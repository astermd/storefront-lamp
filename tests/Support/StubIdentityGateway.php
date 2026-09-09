<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use AsterMD\Storefront\Verification\IdentityGateway;
use AsterMD\Storefront\Verification\IdentityVerdict;

/**
 * An {@see IdentityGateway} that answers whatever the case needs and records
 * what it was asked.
 *
 * A stub rather than the live gateway because the pass branch **cannot** be
 * exercised against the provider: twenty calls recorded on 2026-08-25 across
 * nine identities and all three checks returned `valid: false` without
 * exception, so no input produces a pass and a live-walk test expecting one
 * would be asserting a fiction.
 *
 * It lives in its own PSR-4 file rather than beside its first consumer for the
 * reason {@see \AsterMD\Storefront\Tests\Domain\FakeCatalog} does: a class
 * declared inside another test file autoloads only when that file happens to
 * have been loaded first, so a case using it passes in a full run and fails on
 * its own.
 */
final class StubIdentityGateway implements IdentityGateway
{
    /** @var list<array<string, mixed>> every payload this gateway was handed, in order */
    public array $identities = [];

    /** @var list<string> the check slugs it was asked for, in order */
    public array $checks = [];

    /**
     * @param bool  $enabled whether this deployment reaches a provider at all
     * @param ?bool $valid   the verdict every check returns: true passes, false refuses, null is inconclusive
     */
    public function __construct(
        private readonly bool $enabled = true,
        private readonly ?bool $valid = null,
    ) {
    }

    public function verify(string $check, array $identity): IdentityVerdict
    {
        $this->identities[] = $identity;
        $this->checks[] = $check;

        return IdentityVerdict::fromProviderData($check, [
            'check' => $check,
            'valid' => $this->valid,
            'basis' => 'score',
            'score' => $this->valid === true ? 0.91 : 0,
            'threshold' => 0.75,
            'reasons' => $this->valid === false ? [['code' => 'address_invalid', 'message' => 'no']] : [],
        ]);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
