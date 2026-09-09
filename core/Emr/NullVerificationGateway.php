<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Emr;

/**
 * The gateway bound when `features.emr_verification` is off — which is its
 * default, and always the case under the test suite, whose runs must never
 * reach the network.
 *
 * It answers "could not determine" to everything, which is the same answer a
 * live gateway gives when the credential is refused, so switching the feature
 * off changes what checkout *knows* and never what it *does*: the local format
 * checks stand on their own and an order is never blocked by a check that did
 * not run (`[20.1]`).
 */
final class NullVerificationGateway implements VerificationGateway
{
    public function emailIsDeliverable(string $email): ?bool
    {
        return null;
    }

    public function normaliseAddress(string $address): ?array
    {
        return null;
    }

    public function isEnabled(): bool
    {
        return false;
    }
}
