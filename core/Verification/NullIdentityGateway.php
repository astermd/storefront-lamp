<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Verification;

/**
 * The gateway bound when `verification.enabled` is off — which is how this
 * theme ships, and always the case under the test suite, whose runs must never
 * reach the network.
 *
 * It answers **inconclusive** to everything, and the fact that it never
 * answers `passed` is the whole design. `[22.16]` allows a deployment to run
 * the step blocking, and a disabled gateway that reported a pass would let
 * anyone through the moment an operator switched identity checks off — turning
 * "we are not checking" into "everyone checks out". Inconclusive is the honest
 * answer and, per `[20.1]`, one that never presents to a buyer as a failure
 * either.
 *
 * It is also the same answer the live gateway gives when the integration is
 * inactive, so switching the feature off changes what the step *knows* and
 * never what the rest of the funnel *does*.
 */
final class NullIdentityGateway implements IdentityGateway
{
    public function verify(string $check, array $identity): IdentityVerdict
    {
        return IdentityVerdict::inconclusive($check, 'disabled');
    }

    public function isEnabled(): bool
    {
        return false;
    }
}
