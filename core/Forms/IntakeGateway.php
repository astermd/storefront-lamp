<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

use AsterMD\Sdk\Enum\Event;

/**
 * The storefront's view of the EMR's intake-submission resource: record what a
 * visitor has answered so far, against one journey event.
 *
 * An interface for the same reason {@see \AsterMD\Storefront\Emr\CartGateway}
 * is one — a deployment with EMR analytics switched off binds a null
 * implementation and every caller above stays identical.
 *
 * There is deliberately **one** method rather than a create/update pair. The
 * SDK ties the choice to the event (`*Initiated` creates the server-side
 * record, `*InProgress` and `*Completed` update it), and the caller is the only
 * party that knows which event this save represents, so letting the gateway
 * infer it would mean guessing at the journey's position from the payload.
 *
 * `$data` is the encoded answer list and is therefore PHI: implementations may
 * log how many fields went out, never what was in them.
 */
interface IntakeGateway
{
    /**
     * Records one submission. Returns whether the EMR accepted it.
     *
     * A `false` is not the caller's problem to solve — recording a submission
     * is on `[20.2]`'s degrade-silently list, and a lost save must never trap
     * someone in a form (`[10.26]`).
     *
     * @param list<array{id: string, name: string, label: string, type: string, value: list<array<string, mixed>>}> $data the full accumulated answer list, as {@see AnswerEncoder} produces it
     * @param array{page: int, total: int}|null $progress the multi-page position, or null for a single-page form (`[10.24]`)
     */
    public function record(string $session, Event $event, string $teleformId, array $data, ?array $progress): bool;
}
