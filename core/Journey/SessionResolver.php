<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Journey;

use AsterMD\Storefront\Attribution\AmdPayload;
use AsterMD\Storefront\Attribution\Attribution;
use AsterMD\Storefront\Emr\SessionGateway;
use AsterMD\Storefront\Repository\EventRepository;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Support\RequestContext;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Decides which analytics session this request belongs to, and captures
 * first-touch attribution while doing it.
 *
 * The order is the contract. A resume parameter beats the cookie, because
 * it comes from a message the storefront itself sent and is authoritative
 * over whatever the browser currently holds (`[21.3]`); the cookie beats
 * minting, because session creation is idempotent per browser (`[4.2]`);
 * and minting happens as soon as a visitor arrives rather than at the first
 * cart action (`[4.1]`), which is why the create payload has to carry the
 * attribution captured moments earlier (`[4.4]`).
 *
 * The cookie beating minting has one limit: a cookie naming a session the EMR
 * says it does not know is discarded rather than kept, and the journey is
 * transplanted onto a freshly minted one ({@see self::remint()}). Keeping it
 * would leave every EMR call the journey makes failing and swallowed for the
 * life of the cookie, which surfaces as nothing at all.
 *
 * Two failure behaviours are deliberate. An unresolvable session resolves to
 * null and the journey continues untracked — synthetic identifiers are
 * forbidden precisely because an order carrying one can never be reconciled
 * back to anything (`[20.8]`, `[21.9b]`). And a resume value that is
 * malformed or names a session the EMR has never heard of is ignored in
 * silence rather than shown as an error (`[4.10]`, `[21.2]`): the visitor
 * simply lands at the funnel entry point.
 *
 * Reconciliation is one read per journey, not per request (`[4.14]`): the
 * outcome is written into durable journey state, so the second page view
 * costs nothing. When an adoption already fetched the snapshot, that same
 * snapshot is applied rather than fetched again — and a journey that already
 * carries the current read-model is adopted without any fetch at all, so a
 * resume URL followed twice reads the EMR once.
 */
final class SessionResolver
{
    /**
     * The generation of {@see \AsterMD\Storefront\Emr\EmrSessionGateway}'s
     * read-model extractors. Stamped into journey state next to `reconciled`
     * whenever a snapshot is applied, and compared before a journey is
     * treated as reconciled.
     *
     * Bump it whenever those extractors change which keys they read, and
     * every journey reconciled under the old ones re-reads exactly once
     * instead of keeping an answer produced by a path that turned out to be
     * wrong (`[4.12]`). Without it, `reconciled` makes a mistaken read
     * permanent.
     *
     * Still generation 1: the shape was confirmed against a live session read
     * on 2026-08-23, and both extractor path lists already led with the keys
     * the EMR actually returns, so no journey needs re-reading.
     */
    public const int READ_MODEL_SHAPE = 1;

    /**
     * Accepted session-identifier shape (`[4.10]`): a canonical UUID or a
     * hex object id, and nothing containing a separator or a path.
     */
    private const string UUID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9-]{15,63}$/';

    public function __construct(
        private readonly SessionGateway $gateway,
        private readonly SessionRepository $sessions,
        private readonly JourneyStore $journey,
        private readonly EventRepository $events,
        private readonly OperatorLog $log,
        private readonly SessionOptions $options,
    ) {
    }

    public function resolve(ServerRequestInterface $request, bool $mayCreate): SessionResolution
    {
        $context = RequestContext::fromRequest($request);
        $query = $request->getQueryParams();
        $incoming = $this->captureAttribution(
            $query,
            $request->getHeaderLine('Referer'),
            $context->userAgent,
            $request->getUri()->getHost(),
        );

        // Shape validation is split from the existence check so the two
        // rejection reasons stay distinguishable: a malformed value never
        // reaches adoptFromResumeLink() at all (`[4.10]`, ignored silently),
        // while a shape-valid value the EMR does not recognise does, and is
        // the only case the audit event below exists for (`[21.2]`).
        $candidate = self::validUuid((string) ($query[$this->options->resumeParam] ?? ''));
        $adoption = $candidate !== null ? $this->adoptFromResumeLink($candidate) : null;
        if ($adoption !== null) {
            $state = $this->hydrate($adoption['uuid'], $incoming, $adoption['snapshot']);
            $this->events->append($adoption['uuid'], 'session_adopted');
            $this->auditResumeAccess($adoption['uuid'], $state);

            return new SessionResolution($adoption['uuid'], issueCookie: true);
        }

        $cookie = self::validUuid((string) ($request->getCookieParams()[$this->options->cookieName] ?? ''));
        if ($cookie !== null) {
            $state = $this->journey->load($cookie);
            $snapshot = self::needsReconciliationRead($state) ? $this->gateway->view($cookie) : null;

            // An authoritative "the EMR does not know this session" is now
            // actionable rather than something to record and carry: every
            // mirror call this journey makes from here would fail and be
            // swallowed (`[7.12]`), so the cart would silently stop mirroring
            // for the life of the cookie. Reconciled is deliberately NOT
            // stamped on this path — when the re-mint cannot happen on this
            // request, the next page view has to re-read and try again rather
            // than inherit the wrong answer forever.
            if ($snapshot !== null && $snapshot['exists'] !== true) {
                if ($mayCreate) {
                    return $this->remint($cookie, $state, $incoming, $context, $request);
                }

                $state->attribution = Attribution::firstTouch($state->attribution, $incoming);

                return new SessionResolution($cookie);
            }

            if ($snapshot !== null) {
                self::applySnapshot($state, $snapshot);
            }
            $state->attribution = Attribution::firstTouch($state->attribution, $incoming);

            return new SessionResolution($cookie);
        }

        if (!$mayCreate) {
            return new SessionResolution(null);
        }

        $uuid = $this->gateway->create($incoming->emrSessionPayload(), $context->userAgent, $context->clientIp);
        if ($uuid === null || self::validUuid($uuid) === null) {
            $this->log->warning('session.create_failed', ['path' => $request->getUri()->getPath()]);

            return new SessionResolution(null);
        }

        $state = new JourneyState();
        $state->attribution = $incoming;
        // A session minted on this request has no server-side history, so the
        // reconciliation read would be a guaranteed round-trip for nothing.
        // No read-model generation is stamped, because none was consulted:
        // that is what leaves a later resume link — the one signal that
        // server-side state has moved on somewhere this browser never saw —
        // its single read (`[4.12]`).
        $state->reconciled = true;

        $this->sessions->insert($uuid, $state->toArray(), $incoming->toArray());
        $this->journey->adopt($uuid, $state);
        $this->events->append($uuid, 'session_created', ['source_category' => $incoming->derived['source_category']]);

        // Reaching here with a shape-valid resume parameter that adoption
        // still rejected means it named a session the EMR does not know:
        // recorded locally so a broken retargeting link is diagnosable,
        // never surfaced to the visitor. A malformed resume value is not
        // this case — it is ignored silently and never audited (`[4.10]`).
        if ($candidate !== null && $adoption === null) {
            $this->events->append($uuid, 'session_resume_rejected', ['rejected' => $candidate]);
        }

        return new SessionResolution($uuid, issueCookie: true);
    }

    /**
     * Replaces a cookie the EMR no longer recognises with a fresh session,
     * carrying the journey across.
     *
     * What moves and what does not is the whole decision, and it is made for
     * every durable field rather than for the ones that came to mind — a field
     * with no decision behind it defaults to *not carried*, and the pairing
     * that produced is stated below. `SessionResolverTest` enumerates the list
     * so a field added later cannot join it by omission.
     *
     * What travels is everything durable that is a fact about this buyer:
     * attribution, the cart, the furthest step, the opportunity link, the
     * questionnaire — answers, per-form status and any standing
     * disqualification — the identity verdict, the checkout's own working
     * state — the typed buyer details, the applied promotion, the accepted
     * bumps and the recorded consents — and the whole record of a purchase
     * that has already happened: the placed orders, the upsell outcomes, the
     * retained receipt, the fire-once completion guard and the retirement flag
     * (`[5.12]`, `[5.21]`, `[21.7]`, `[9.8]`, `[21.5]`, `[13.30]`, `[22.20]`,
     * `[17.1]`, `[4.17]`, `[4.18]`).
     *
     * What does not travel is everything that describes the *old session's*
     * relationship with something else. The recorded EMR events describe what
     * that session fired, and carrying them would suppress the "initiated"
     * events on a session where nothing has fired yet, which is the
     * duplicate-suppression rule `[4.13]` pointed the wrong way. Neither does
     * the cart-mirror flag — the new session has no remote cart, so its first
     * mirror must be a create — nor the read-model generation, because nothing
     * was read for the new identifier. The promotion's cart digest is left
     * behind deliberately: a discount is quoted against a set of priced lines
     * and re-quoting it is the safe direction, so the checkout re-prices it
     * rather than trusting a figure with no cart attached (`[14.6b]`). And the
     * post-purchase working state — the credential handle, the upsell queue,
     * its cursor and the quoted prices — is left behind for the reason that
     * decides every close call on this path: without a handle the remaining
     * offers are skipped and nothing is said to the buyer (`[15.13]`), which
     * costs one optional add-on, where carrying a handle onto an identifier
     * whose queue position had not travelled with it would re-offer something
     * already charged.
     *
     * A failed mint leaves the visitor on the old identifier with the journey
     * still unreconciled, so the next page view tries again rather than
     * settling into the broken state this method exists to escape.
     */
    private function remint(
        string $stale,
        JourneyState $state,
        Attribution $incoming,
        RequestContext $context,
        ServerRequestInterface $request,
    ): SessionResolution {
        $attribution = Attribution::firstTouch($state->attribution, $incoming);

        // Getting the rejected identifier back is not a new session, and it is
        // the one failure here that would not stay contained: the insert would
        // no-op on conflict and adopt() would fingerprint the transplant away,
        // so the same cookie would be re-issued over the same still-unreconciled
        // row and the next page view would mint again, once per view for as long
        // as the visitor stayed. Treated as a failed mint for that reason.
        $uuid = $this->gateway->create($attribution->emrSessionPayload(), $context->userAgent, $context->clientIp);
        if ($uuid === null || $uuid === $stale || self::validUuid($uuid) === null) {
            $this->log->warning('session.remint_failed', ['path' => $request->getUri()->getPath()]);
            $state->attribution = $attribution;

            return new SessionResolution(null);
        }

        $fresh = new JourneyState();
        $fresh->attribution = $attribution;
        $fresh->opportunityId = $state->opportunityId;
        $fresh->cart = $state->cart;
        $fresh->furthestStep = $state->furthestStep;
        $fresh->reconciled = true;
        // The questionnaire travels too. A visitor whose cookie the EMR has
        // stopped recognising has not stopped being the person who answered
        // fifty medical questions, and making them retype the form is the
        // worst thing this path could do to them. The verdict travels with the
        // answers on purpose: leaving a disqualification behind while keeping
        // what caused it would silently re-open a funnel the server closed.
        $fresh->formAnswers = $state->formAnswers;
        $fresh->formStatus = $state->formStatus;
        $fresh->disqualifiedRule = $state->disqualifiedRule;
        $fresh->disqualifiedTeleform = $state->disqualifiedTeleform;
        // Everything the buyer typed or chose at checkout travels for the same
        // reason the questionnaire does. Neither credential field appears
        // here, for two different reasons. There is no card to carry at all:
        // the configured provider charges a later order against an instrument
        // it already holds (`[15.3]`, `[15.9]`), so nothing in this
        // application ever holds one. And the reference handle that stands in
        // for it is left behind with the rest of the post-purchase working
        // state, below.
        $fresh->buyer = $state->buyer;
        $fresh->promotion = $state->promotion;
        $fresh->acceptedBumps = $state->acceptedBumps;
        $fresh->consents = $state->consents;
        $fresh->placedOrders = $state->placedOrders;
        // The completed journey travels as one piece, because carrying half of
        // it is what made this dangerous. `placedOrders` is what the
        // `order_placed` precondition reads, so a journey that kept it and
        // lost the fire-once guard was still admitted to the receipt and no
        // longer remembered having been there — and the second firing reports
        // the same order reference under the *new* session, which is the key
        // the EMR builds a treatment record on. That writes a second clinical
        // record for one paid order (`[17.1]`, `[17.2]`). The retained receipt
        // and the retirement flag travel with it for the reasons they survive
        // the completion wipe: a refresh has to render, and the request that
        // clears the cookie has to find the decision already made (`[4.17]`,
        // `[4.18]`). The upsell outcomes are the only trail a *declined* offer
        // leaves anywhere (`[16.11]`, `[18.1]`).
        $fresh->upsellOutcomes = $state->upsellOutcomes;
        $fresh->receipt = $state->receipt;
        $fresh->completedAt = $state->completedAt;
        $fresh->sessionRetired = $state->sessionRetired;
        // The identity verdict is the questionnaire's verdict one rule later.
        // A visitor whose cookie the EMR has stopped recognising has not
        // stopped being the person whose identity was checked, and leaving the
        // answer behind spends a second provider check to re-derive one
        // already recorded (`[22.20]`).
        $fresh->verification = $state->verification;

        // The opportunity is passed to the insert rather than left to the
        // save-back: adopt() fingerprints the state it is handed, so nothing
        // downstream ever sees the transplanted link as a change to write.
        $this->sessions->insert($uuid, $fresh->toArray(), $attribution->toArray(), $fresh->opportunityId);
        $this->journey->adopt($uuid, $fresh);
        $this->events->append($uuid, 'session_reminted', ['replaced' => $stale]);
        $this->log->info('session.reminted', ['session' => $uuid, 'replaced' => $stale]);

        return new SessionResolution($uuid, issueCookie: true);
    }

    /**
     * Decrypts the `_amd` payload when one is present and merges it over the
     * plain parameters. The wrapper key is lifted out before normalisation so
     * it can never end up inside stored tracking data (`[5.4]`), and a
     * decryption failure is logged for operators but never surfaced
     * (`[5.3]`).
     *
     * @param array<array-key, mixed> $query
     */
    private function captureAttribution(
        array $query,
        string $referrer,
        ?string $userAgent,
        ?string $selfHost = null,
    ): Attribution {
        $token = (string) ($query[$this->options->payloadParam] ?? '');
        unset($query[$this->options->payloadParam]);

        $encrypted = [];
        if ($token !== '') {
            $decoded = AmdPayload::decode($token, $this->options->trackingKeys);
            if ($decoded === null) {
                $this->log->warning('amd_decrypt_failed', ['keys_tried' => count($this->options->trackingKeys)]);
            } else {
                $encrypted = $decoded;
            }
        }

        return Attribution::capture($query, $encrypted, $referrer === '' ? null : $referrer, $userAgent, $selfHost);
    }

    /**
     * Decides whether a shape-valid resume value names a session worth
     * adopting, and hands back whatever it learned while deciding.
     *
     * A journey already carrying the current read-model is adopted with no
     * snapshot at all: its local row is itself proof the EMR knows the
     * identifier, so re-reading would break the one-read-per-journey rule for
     * a visitor who follows the same resume URL twice (`[4.14]`). Anything
     * else is read from the EMR, which both validates the identifier and
     * produces the snapshot the journey is missing.
     *
     * @param string $candidate an already shape-validated resume value
     *
     * @return array{uuid: string, snapshot: array{exists: bool, opportunity_id: ?string, events: list<string>}|null}|null
     */
    private function adoptFromResumeLink(string $candidate): ?array
    {
        // Deliberately a read-only lookup rather than JourneyStore::load(),
        // which would create a row for an identifier this method may be about
        // to reject.
        $row = $this->sessions->find($candidate);
        if ($row !== null && self::carriesCurrentReadModel(JourneyState::fromArray($row['journey_state'], null, null))) {
            return ['uuid' => $candidate, 'snapshot' => null];
        }

        $snapshot = $this->gateway->view($candidate);
        if ($snapshot === null || $snapshot['exists'] !== true) {
            $this->log->info('session.resume_rejected', ['session' => $candidate]);

            return null;
        }

        return ['uuid' => $candidate, 'snapshot' => $snapshot];
    }

    /**
     * Brings the durable journey state up to date with the EMR and folds this
     * request's attribution into it.
     *
     * A snapshot passed in was fetched moments ago on this same request and is
     * therefore authoritative — applying it is the whole point of having
     * fetched it. Only when none was supplied is a read considered, and then
     * only for a journey that does not already carry the current read-model,
     * so the reconciliation stays one read per journey rather than one per
     * request (`[4.14]`).
     *
     * That read is currently unreachable, and deliberately kept: the only
     * caller is the resume-link path, which reaches here having just fetched a
     * snapshot in order to decide the link was worth adopting at all, so
     * `$snapshot` is never null in practice today. The branch is what makes
     * this method correct for a caller that adopts an identifier by some other
     * route — an operator tool, a future channel — rather than a dead limb, and
     * removing it would hand that caller a silently unreconciled journey.
     *
     * @param array{exists: bool, opportunity_id: ?string, events: list<string>}|null $snapshot already-fetched state, when available
     */
    private function hydrate(string $uuid, Attribution $incoming, ?array $snapshot): JourneyState
    {
        $state = $this->journey->load($uuid);

        if ($snapshot === null && self::needsReconciliationRead($state)) {
            $snapshot = $this->gateway->view($uuid);
        }

        if ($snapshot !== null) {
            self::applySnapshot($state, $snapshot);
        }

        $state->attribution = Attribution::firstTouch($state->attribution, $incoming);

        return $state;
    }

    /**
     * `[30.7]`: the resume link was followed, so somebody's journey — intake
     * answers included — was put back in front of a browser.
     *
     * `[30.8]` is what makes this a row rather than a log line: the audit
     * trail is the compliance record, and this deployment needs it more than
     * most. The link is the raw session identifier with no expiry and no
     * revocation, a decision `config/abandonment.php` records rather than
     * apologises for, and the consequence of that decision is that **nothing
     * else in this system can say the link was ever used.** Every other event
     * on this table records a write; this one records a read.
     *
     * It is written on the adoption path alone. A cookie visit is the same
     * browser coming back to its own journey, which is the storefront working,
     * and auditing it would bury the one access that carries an exposure under
     * every page view in the funnel. A resume value the EMR rejects never
     * reaches here at all — nothing was loaded, so nothing was read, and
     * `session_resume_rejected` already records the attempt.
     *
     * The payload is who, what and why (`[20.14]`, `[20.6]`): the actor, the
     * reason, and whether a questionnaire was among what came back. Never a
     * single answer — this row long outlives the request, and the whole point
     * of auditing an access to health information is defeated by an audit that
     * is itself a copy of it.
     */
    private function auditResumeAccess(string $uuid, JourneyState $state): void
    {
        $this->events->append($uuid, 'access.journey_resumed', [
            'actor' => 'resume_link',
            'reason' => 'journey_resumed',
            'restored_intake' => $state->formAnswers !== [],
            'teleforms' => count($state->formAnswers),
        ]);
    }

    /**
     * Folds an EMR snapshot into durable journey state.
     *
     * Only one of the three possible outcomes of a read reaches here.
     * `exists: true` recovers the linked opportunity and the events the EMR
     * has already recorded (`[4.12]`) — the opportunity only if the journey
     * has none, and the events as a union rather than a replacement, because
     * an event fired locally on this request would otherwise be erased by a
     * snapshot taken before the EMR write landed, and a name lost that way is
     * fired twice (`[4.13]`).
     *
     * The other two never arrive. A read that failed leaves the caller with
     * nothing to apply, so `reconciled` stays false and a later request
     * retries (`[20.2]`); and `exists: false` is now handled by the caller
     * instead — it is grounds for discarding the identifier rather than for
     * recording an answer against it, so reaching here with one would stamp
     * the very `reconciled` that {@see self::remint()} exists to withhold.
     * The guard below stays as a cheap invariant.
     *
     * @param array{exists: bool, opportunity_id: ?string, events: list<string>} $snapshot
     */
    private static function applySnapshot(JourneyState $state, array $snapshot): void
    {
        if ($snapshot['exists'] === true) {
            $state->opportunityId ??= $snapshot['opportunity_id'];
            foreach ($snapshot['events'] as $name) {
                $state->recordEmrEvent($name);
            }
        }

        $state->reconciled = true;
        $state->readModelShape = self::READ_MODEL_SHAPE;
    }

    /**
     * A journey needs the read when it has never been reconciled — including
     * one whose earlier read failed — or when the facts it carries came from a
     * superseded generation of the extractors. A journey minted here carries
     * no generation at all and needs nothing: there was no server-side
     * history to read when it was created.
     */
    private static function needsReconciliationRead(JourneyState $state): bool
    {
        if (!$state->reconciled) {
            return true;
        }

        return $state->readModelShape !== null && $state->readModelShape !== self::READ_MODEL_SHAPE;
    }

    private static function carriesCurrentReadModel(JourneyState $state): bool
    {
        return $state->reconciled && $state->readModelShape === self::READ_MODEL_SHAPE;
    }

    private static function validUuid(string $value): ?string
    {
        $value = trim($value);

        return preg_match(self::UUID_PATTERN, $value) === 1 ? $value : null;
    }
}
