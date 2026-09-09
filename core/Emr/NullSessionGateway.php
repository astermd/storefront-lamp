<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Emr;

/**
 * The gateway used when EMR analytics sessions are switched off: a
 * half-configured deployment, and the test suite, which must never make an
 * outbound call.
 *
 * It reports "no session could be created" and "this session is not known",
 * which are states the funnel already has to handle (`[4.5]`), so switching
 * analytics off degrades the storefront rather than changing its behaviour.
 */
final class NullSessionGateway implements SessionGateway
{
    public function create(array $data, ?string $userAgent, ?string $clientIp): ?string
    {
        return null;
    }

    public function view(string $uuid): ?array
    {
        return ['exists' => false, 'opportunity_id' => null, 'events' => []];
    }
}
