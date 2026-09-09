<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Observability;

use AsterMD\Sdk\Enum\Event;
use AsterMD\Storefront\Forms\IntakeGateway;

/**
 * `[20.9]` over the progressive-save boundary.
 *
 * A refused save is invisible to the visitor by design — a lost save must
 * never trap someone in a form (`[10.26]`) — so the acceptance rate is the
 * only place a save endpoint that has begun refusing everything becomes
 * visible before the intake completion rate does.
 *
 * **How many answers went out is recorded; what they were is not.** The port's
 * own contract says so in terms, and the field values here are the clinical
 * record itself (`[20.14]`).
 */
final class InstrumentedIntakeGateway implements IntakeGateway
{
    public function __construct(
        private readonly IntakeGateway $inner,
        private readonly BoundaryTimer $timer,
    ) {
    }

    public function record(string $session, Event $event, string $teleformId, array $data, ?array $progress): bool
    {
        return $this->timer->measure(
            Boundary::FormSave,
            fn (): bool => $this->inner->record($session, $event, $teleformId, $data, $progress),
            static fn (bool $accepted): array => ['outcome' => $accepted ? 'accepted' : 'refused'],
            [
                'operation' => $event->value,
                'session' => $session,
                'teleform' => $teleformId,
                'fields' => count($data),
                'page' => $progress['page'] ?? null,
            ],
        );
    }
}
