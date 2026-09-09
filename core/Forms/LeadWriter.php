<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Support\OperatorLog;

/**
 * Create-once-then-update orchestration for the lead behind an intake form
 * (`[9.7]`), and the single place a failed lead write is swallowed.
 *
 * **Why the swallow lives here and not at the call sites.** Capturing a lead is
 * on `[20.2]`'s degrade-silently list: a visitor filling in a medical form must
 * never see a CRM outage, and must never be blocked by one (`[10.26]`). One
 * boundary that catches, logs and reports keeps that auditable — the gateways
 * stay honest about what happened and every caller gets the same policy for
 * free. This mirrors {@see \AsterMD\Storefront\Emr\CartMirror}.
 *
 * **The threshold guards creation only.** `[9.9]` is a correctness rule, not a
 * failure policy: below a first name and an email the record is a fragment
 * nobody can follow up on, so the gateway is not called at all — this is the
 * one path here that declines to write rather than writing and forgiving. Once
 * an opportunity exists, every later save is an update regardless of what it
 * carries, so a phone number typed after the fact still lands.
 *
 * **A failed create leaves the journey able to retry.** `opportunityId` stays
 * null, so the next capture attempts the create again (`[9.5]`) — a network
 * blip must not permanently lose an abandoner's lead. The converse rule holds
 * too: an id, once remembered, is never unset, because forgetting it would make
 * the following save create a second opportunity for the same person.
 *
 * **The log carries key names, never values.** The payload here is the
 * visitor's name, email and clinical answers (`[11.1]`'s mapped record), so
 * what goes to disk is the session uuid, which operation was attempted, and
 * *which fields were present* — enough to tell a validation rejection from an
 * outage, and no more. {@see OperatorLog::redact()} is key-based and could not
 * strip an answer embedded in a longer string, so the values never enter the
 * context in the first place.
 */
final class LeadWriter
{
    public function __construct(
        private readonly LeadGateway $gateway,
        private readonly RecordMapper $mapper,
        private readonly OperatorLog $log,
    ) {
    }

    /**
     * Writes what is known about this visitor to their lead, creating it if the
     * payload has finally crossed the threshold.
     *
     * @param array<string, mixed> $payload the `opportunity`-rooted record, rebuilt in full (`[11.5]`)
     */
    public function capture(string $sessionUuid, JourneyState $state, array $payload): LeadOutcome
    {
        // Nothing answered yet maps to nothing worth saying — and an empty
        // update would be a round trip that changes no field.
        if ($payload === []) {
            return LeadOutcome::noneCreated($state->opportunityId);
        }

        // A gateway that writes nowhere has not failed, so nothing is logged:
        // the alternative is an analytics-off deployment reporting a lead
        // failure on every capture while working exactly as configured.
        if (!$this->gateway->writes()) {
            return LeadOutcome::noneCreated($state->opportunityId);
        }

        $existing = $state->opportunityId;

        if ($existing !== null) {
            if (!$this->gateway->update($existing, $payload)) {
                $this->log->warning('intake.lead_write_failed', [
                    'session' => $sessionUuid,
                    'operation' => 'update',
                    'opportunity_id' => $existing,
                    'fields' => array_keys($payload),
                ]);
            }

            return LeadOutcome::noneCreated($existing);
        }

        if (!$this->mapper->ready($payload)) {
            return LeadOutcome::noneCreated();
        }

        $opportunityId = $this->gateway->create($this->creationPayload($sessionUuid, $state, $payload));

        if ($opportunityId === null) {
            $this->log->warning('intake.lead_write_failed', [
                'session' => $sessionUuid,
                'operation' => 'create',
                'fields' => array_keys($payload),
            ]);

            return LeadOutcome::noneCreated();
        }

        $state->opportunityId = $opportunityId;

        return LeadOutcome::createdLead($opportunityId);
    }

    /**
     * The record as the create call receives it: the mapped answers, the
     * analytics session that produced them, and the first-touch source.
     *
     * `sessions` is the documented join between an opportunity and the tracking
     * sessions it came from, and is the only link the create schema offers — the
     * channel binding `[11.8]` asks for reaches the EMR through that session,
     * which already carries `channel_id`.
     *
     * The source is attached on creation only, which is `[11.9]` and `[5.12]`
     * meeting: attribution is captured once on first touch and the original
     * referrer keeps credit for the whole journey, so re-sending it on later
     * updates could only ever overwrite the first answer with a later one. A
     * `source` the caller has already decided is left alone, and an attribution
     * that derived no category contributes nothing rather than a blank.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function creationPayload(string $sessionUuid, JourneyState $state, array $payload): array
    {
        // Union rather than assignment: a mapping that already resolved
        // `sessions` was authored deliberately and is not ours to replace.
        $payload += ['sessions' => [$sessionUuid]];

        $source = $state->attribution?->derived['source_category'] ?? null;

        if (!isset($payload['source']) && is_string($source) && trim($source) !== '') {
            $payload['source'] = $source;
        }

        return $payload;
    }
}
