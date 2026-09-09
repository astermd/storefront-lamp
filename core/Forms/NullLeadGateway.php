<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * The gateway used when EMR analytics sessions are switched off: a
 * half-configured deployment, and the test suite, which must never make an
 * outbound call.
 *
 * `create()` returns null and `update()` returns true, which is not an
 * inconsistency. There is no id to invent — returning a fake one would have
 * {@see LeadWriter} remember it and spend the rest of the journey updating an
 * opportunity that does not exist — while an update has nothing to hand back
 * but "this landed", and a deployment with analytics off must look to
 * everything upstream like one whose writes all succeeded. In practice
 * `update()` is unreachable here, because the null create never gives the
 * journey an id to update against.
 */
final class NullLeadGateway implements LeadGateway
{
    public function create(array $payload): ?string
    {
        return null;
    }

    public function update(string $opportunityId, array $payload): bool
    {
        return true;
    }

    public function writes(): bool
    {
        return false;
    }
}
