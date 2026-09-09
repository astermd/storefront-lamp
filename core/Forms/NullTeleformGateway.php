<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * The gateway used when there is no EMR to ask: a deployment with analytics
 * switched off or blank credentials, and the test suite, which must never
 * make an outbound call.
 *
 * Both methods return `null`, which resolves to the "form unavailable" state
 * `[10.3]` rather than an empty form. That is the opposite choice from
 * {@see \AsterMD\Storefront\Emr\NullCartGateway}, which reports success, and
 * deliberately so: a mirrored cart is a side effect nobody upstream should
 * branch on, whereas a form with no questions is not a degraded intake step —
 * it is a page that silently collects nothing.
 */
final class NullTeleformGateway implements TeleformGateway
{
    public function metadata(string $teleformId): ?TeleformMetadata
    {
        return null;
    }

    public function definition(TeleformMetadata $metadata): ?array
    {
        return null;
    }
}
