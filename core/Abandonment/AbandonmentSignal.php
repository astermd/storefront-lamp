<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Abandonment;

use AsterMD\Storefront\Journey\JourneyState;

/**
 * One abandonment signal: everything `[21.11]` says the consuming email
 * automation is owed about a journey that stopped, and deliberately nothing
 * else.
 *
 * **What is not here is the point.** `[20.6]` and
 * {@see JourneyState::$formAnswers}'s own docblock put clinical answers
 * outside every log, exception message and analytics payload, and a polled
 * JSON document handed to an outside platform is the widest of the three. So
 * this carries the *fact* of an abandoned intake and never its content: no
 * answers, no cart lines, no buyer name, no email address. The automation
 * platform is fed from the EMR, and `opportunity_id` is the join it already
 * has — an identifier of a record over there rather than anything the visitor
 * typed. When it is null the journey never crossed `[11.6]`'s threshold, which
 * means there is no contact address to have carried anyway.
 *
 * **The resume link, and the permanent exposure it carries.** `[21.11]`
 * requires the link, so it is here. `[21.4]` asks for that link to be
 * short-lived, single-purpose and revoked at completion, "because it grants a
 * stranger access to intake answers, which are health information". This
 * deployment's resume parameter is the raw session uuid — it carries no
 * expiry, and completing a journey does not revoke it. That is a deliberate
 * choice rather than an omission, so nothing here should be "fixed" into
 * revoking it. What follows from the choice is that the exposure is permanent
 * too, and the compensating control is the audit trail: every use of a resume
 * link, and every sweep that emits one, is recorded as an access to health
 * information (`[30.7]`). Two consequences follow for anyone handling one of
 * these documents: it is a bearer credential for a health record, and it must
 * not be written to any durable store or log that outlives the journey.
 */
final class AbandonmentSignal
{
    /** The visitor ticked the marketing consent when they were shown it (`[26.2]`). */
    public const string MARKETING_GRANTED = 'granted';

    /** The visitor was shown the marketing consent and did not tick it — `[21.11b]`'s mark. */
    public const string MARKETING_DECLINED = 'declined';

    /** The journey stopped before the consent controls were ever rendered. */
    public const string MARKETING_NOT_ASKED = 'not_asked';

    /**
     * @param string $furthestStep the funnel's own recording (`[21.7]`), or null when the journey never completed a step
     * @param string $lastMovedAt  `sessions.updated_at`, which advances only when journey state actually changed
     */
    public function __construct(
        public readonly AbandonmentState $state,
        public readonly string $sessionUuid,
        public readonly ?string $opportunityId,
        public readonly ?string $furthestStep,
        public readonly string $resumeUrl,
        public readonly string $marketingConsent,
        public readonly string $lastMovedAt,
        public readonly int $idleSeconds,
    ) {
    }

    /**
     * How this journey answered the marketing consent, as one of the three
     * values above.
     *
     * `[21.11b]` requires a declined marketing consent to be marked so the
     * platform can suppress the message, and `[26.4]` makes marketing and
     * transactional consent permanently separate — so this reads one key and
     * never infers from another. A journey that granted transactional SMS and
     * refused marketing answers `declined` here, which is the whole reason the
     * two are separate entries in `config/consent.php`.
     *
     * **Three values rather than a boolean, and no derived "suppress" flag.**
     * The consents are written in one place, at checkout submission, so most
     * abandoned journeys never reached the question at all. Folding that into
     * `declined` would say the visitor refused something nobody asked them,
     * and folding it into `granted` would be worse. Emitting a boolean would
     * force this class to pick a policy for the never-asked case — suppress
     * everything, and cart-abandonment mail stops working; suppress nothing,
     * and the platform mails people who never consented — and that policy is
     * the deployment's to set with its counsel, not a storefront's to bury in
     * a derived field. The fact is reported; the branch belongs to the
     * consumer.
     *
     * @param string $key the configured marketing consent key, `config/consent.php`
     */
    public static function marketingConsentOf(JourneyState $state, string $key): string
    {
        foreach ($state->consents as $consent) {
            if ($consent['key'] === $key) {
                return $consent['granted'] === true ? self::MARKETING_GRANTED : self::MARKETING_DECLINED;
            }
        }

        return self::MARKETING_NOT_ASKED;
    }

    /**
     * A stable identity for "this journey, in this state, having last moved at
     * this moment" — `[21.13]`'s answer for a polled feed.
     *
     * `[21.13]` forbids re-firing an event for a step already recorded, and a
     * poll re-reads the same rows on every run by construction. Rather than
     * writing a "signal sent" mark back during a read sweep — which would make
     * a failed poll lose the signal permanently and put a write on the path of
     * something `[20.1]` says must never disturb the storefront — the document
     * carries a key the consumer can deduplicate on. It is stable for exactly
     * as long as the journey has not moved, because `sessions.updated_at` is
     * fingerprint-gated, and it changes the moment the journey does — so a
     * visitor who resumes, gets further and stops again produces a new signal
     * rather than a repeat of the old one.
     *
     * Composed and readable rather than hashed: every part of it is already
     * elsewhere in the same document, so a digest would hide the inputs
     * without concealing anything.
     */
    public function signalKey(): string
    {
        return $this->sessionUuid . ':' . $this->state->value . ':' . $this->lastMovedAt;
    }

    /**
     * The wire document, whose key set is a contract with an external system
     * (`[21.11a]`) and not an internal detail — renaming one is a breaking
     * change for the automation platform rather than a refactor.
     *
     * @return array{signal_key: string, state: string, session_uuid: string, opportunity_id: ?string, furthest_step: ?string, resume_url: string, marketing_consent: string, last_moved_at: string, idle_seconds: int}
     */
    public function toArray(): array
    {
        return [
            'signal_key' => $this->signalKey(),
            'state' => $this->state->value,
            'session_uuid' => $this->sessionUuid,
            'opportunity_id' => $this->opportunityId,
            'furthest_step' => $this->furthestStep,
            'resume_url' => $this->resumeUrl,
            'marketing_consent' => $this->marketingConsent,
            'last_moved_at' => $this->lastMovedAt,
            'idle_seconds' => $this->idleSeconds,
        ];
    }
}
