<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

use AsterMD\Sdk\Enum\Event;
use AsterMD\Storefront\Repository\EventRepository;

/**
 * Writes each intake lifecycle event to the local `events` table on its way to
 * the EMR (`[18.1]`).
 *
 * The six questionnaire events — the pre-qualification and intake triples —
 * reached the EMR from the day the intake flow was built and landed nowhere
 * locally, which left the storefront unable to answer "how far did this
 * journey get" without asking the EMR. Every other journey fact already has a
 * local row; these did not.
 *
 * A decorator rather than a write inside {@see IntakeSession} because the two
 * are different concerns and only one of them is optional: the EMR half is
 * switched off with the analytics flag, while the audit trail is not analytics
 * and must be written either way. Wrapping the port keeps that difference at
 * the wiring rather than as a branch inside the questionnaire.
 *
 * **Never the answers.** `$data` is the encoded answer list and is PHI: what
 * is recorded is the event, the form, the position and how many fields went
 * out — a count cannot leak an answer, and everything else here is bookkeeping
 * the visitor did not tell anyone. {@see EventRepository} swallows its own
 * failures, so an unwritable audit line cannot reach the visitor either.
 */
final class RecordingIntakeGateway implements IntakeGateway
{
    public function __construct(
        private readonly IntakeGateway $inner,
        private readonly EventRepository $events,
    ) {
    }

    public function record(string $session, Event $event, string $teleformId, array $data, ?array $progress): bool
    {
        $accepted = $this->inner->record($session, $event, $teleformId, $data, $progress);

        // A blank session is a journey the EMR could never mint one for
        // (`[20.8]` forbids inventing one), and the table is keyed on it, so
        // there is nothing to file the line under.
        if ($session !== '') {
            $this->events->append($session, 'intake.' . $event->value, [
                'teleform_id' => $teleformId,
                'fields' => count($data),
                'page' => $progress['page'] ?? null,
                'accepted' => $accepted,
            ]);
        }

        return $accepted;
    }
}
