<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

use AsterMD\Sdk\Enum\Event;

/**
 * The gateway used when EMR analytics sessions are switched off: a
 * half-configured deployment, and the test suite, which must never make an
 * outbound call.
 *
 * `record()` reports success unconditionally, because a deployment with
 * analytics off must behave exactly like one whose every submission landed —
 * nothing upstream should branch on whether answers are actually being
 * recorded, the same reasoning that keeps {@see \AsterMD\Storefront\Emr\NullCartGateway}
 * silent about its own inertness. Reporting `false` would be read as "the save
 * failed" and could surface a retry or a warning to a visitor whose form is
 * working exactly as configured.
 */
final class NullIntakeGateway implements IntakeGateway
{
    public function record(string $session, Event $event, string $teleformId, array $data, ?array $progress): bool
    {
        return true;
    }
}
