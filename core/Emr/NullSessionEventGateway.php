<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Emr;

/**
 * The gateway used when EMR analytics sessions are switched off, including
 * under the test suite, which must never make an outbound call.
 */
final class NullSessionEventGateway implements SessionEventGateway
{
    public function pageView(string $session, PageView $page): void
    {
    }

    public function beginIdentityVerification(string $session): void
    {
    }
}
