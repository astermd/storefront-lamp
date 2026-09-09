<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Abandonment;

use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Repository\EventRepository;
use AsterMD\Storefront\Repository\SessionRepository;

/**
 * The time half of `[21.10]`: which journeys have stopped moving, and what
 * state each of them stopped in.
 *
 * **Server-side and time-based, and there was never a choice about that.**
 * `[21.12]` forbids depending on a browser unload event, and this deployment
 * could not have used one anyway: there is no client-side session bridge, and
 * the HTTP-only `amd_session` cookie is the only carrier a browser holds.
 * `[4.18]`/`[4.19]` met the same wall and answered it server-side with
 * {@see \AsterMD\Storefront\Http\Middleware\RetiredSessionMiddleware}; this is
 * the same answer for the same reason. Nothing here needs the visitor's browser to still exist, which is
 * the point — an abandoner's browser is by definition closed.
 *
 * **`sessions.updated_at` is a genuine "last moved" timestamp**, not "last
 * seen", because {@see \AsterMD\Storefront\Journey\JourneyStore::flush()}
 * compares a fingerprint of the durable state and writes nothing when it has
 * not changed. A visitor who reloads the checkout page ten times does not
 * reset their own abandonment clock; one who adds a line to their cart does.
 * Migration `0004_post_purchase.php` indexes the column so the window query is
 * not a table scan.
 *
 * **Polling, not push (`[21.11a]`).** The spec allows either "pushed on
 * transition or exposed for polling", and this deployment has no outbound push
 * infrastructure and no inbound authentication to build one behind, so the
 * signals are exposed for an external system to collect —
 * {@see \AsterMD\Storefront\Console\AbandonmentCommand} is that exposure. It
 * follows that this class **changes nothing it reads**: a sweep that marked
 * rows as it read them would lose a signal permanently whenever a poll failed
 * midway. Deduplication is the consumer's, on
 * {@see AbandonmentSignal::signalKey()}.
 *
 * **The one thing it does write is the `[30.7]` access row**, and that is a
 * different kind of write for two reasons. It is append-only, so it cannot
 * change which journeys the next sweep returns — the property above survives
 * intact — and {@see EventRepository::append()} swallows its own failures, so
 * it cannot disturb the storefront either (`[18.3]`, `[20.1]`). It has to
 * exist because of what this class produces: each signal carries a resume
 * link, the link is the raw session identifier with no expiry and no
 * revocation (`config/abandonment.php` records that decision), and every
 * signal is therefore a credential for somebody's intake answers leaving the
 * storefront. `[30.7]` asks who or what read health information, when, and
 * why. Without these rows the answer for this path is nothing at all.
 *
 * The rows are per signal and per sweep, undeduplicated: an idle journey that
 * is polled hourly for a month is read hourly for a month, and an audit that
 * recorded only the first one would answer "when" with a date that had stopped
 * being true. That makes this the highest-volume name on the table, which is
 * the concrete reason `[30.6]`'s expiry of `events` matters rather than an
 * abstract one.
 *
 * The four intervals are constructor arguments with the constants below as
 * defaults, so a deployment configures them rather than editing core.
 */
final class AbandonmentSweep
{
    /**
     * How long a journey must have sat still to count as abandoned.
     *
     * An hour is the industry-conventional cart-recovery delay and is short
     * enough that the first message still lands while the visitor remembers
     * the visit. It is one interval for all six states on purpose: `[21.10]`
     * says "a configured interval", singular, and a per-state interval would
     * mean six overlapping windows and a journey whose classification changed
     * between them.
     */
    public const int DEFAULT_IDLE_SECONDS = 3600;

    /**
     * How far back a sweep looks before giving up on a journey.
     *
     * Thirty days matches {@see \AsterMD\Storefront\Journey\SessionOptions::$lifetimeDays},
     * which is the horizon past which the resume link's cookie is gone and the
     * visitor could not pick the journey up where they left it even if they
     * wanted to.
     */
    public const int DEFAULT_LOOKBACK_SECONDS = 2_592_000;

    /**
     * The most signals one sweep will return.
     *
     * A bound rather than a page size: the poller re-reads the same window
     * next run, and the oldest journeys come first, so nothing is dropped —
     * it only stops a backlog from turning one poll into an unbounded read.
     */
    public const int DEFAULT_LIMIT = 500;

    /**
     * The consent key that means marketing (`config/consent.php`).
     *
     * Named rather than inferred because `[26.4]` keeps marketing and
     * transactional consent permanently separate, and a deployment is free to
     * rename or re-word its own consents.
     */
    public const string DEFAULT_MARKETING_CONSENT_KEY = 'marketing';

    private readonly AbandonmentClassifier $classifier;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /** `[30.7]`'s name for a journey serialised into a signal that left the storefront. */
    private const string ACCESS_EVENT = 'access.abandonment_signal';

    /**
     * @param string                  $baseUrl the storefront's public origin, `app.url`
     * @param ?EventRepository        $events  the `[30.7]` trail; null leaves the sweep unaudited, which is the shape a caller assembled before the trail existed still has
     * @param (\Closure(): int)|null  $clock   current Unix time; injected so a sweep's window is arithmetic rather than wall clock
     */
    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly string $baseUrl,
        private readonly string $resumeParam = 'amd_session',
        private readonly int $idleSeconds = self::DEFAULT_IDLE_SECONDS,
        private readonly int $lookbackSeconds = self::DEFAULT_LOOKBACK_SECONDS,
        private readonly int $limit = self::DEFAULT_LIMIT,
        private readonly string $marketingConsentKey = self::DEFAULT_MARKETING_CONSENT_KEY,
        private readonly ?EventRepository $events = null,
        ?\Closure $clock = null,
    ) {
        $this->classifier = new AbandonmentClassifier();
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * The abandonment signals as they stand right now, longest-idle first.
     *
     * A row that classifies as nothing contributes nothing: the window catches
     * every session that has stopped moving, including ones that never took a
     * step worth abandoning, and emitting a null state for those would make
     * the automation filter out most of what it polls.
     *
     * @return list<AbandonmentSignal>
     */
    public function signals(): array
    {
        $now = ($this->clock)();
        $rows = $this->sessions->findLastMovedBetween(
            gmdate('c', $now - $this->lookbackSeconds),
            gmdate('c', $now - $this->idleSeconds),
            $this->limit,
        );

        $signals = [];

        foreach ($rows as $row) {
            $state = JourneyState::fromArray($row['journey_state'], $row['attribution'], $row['opportunity_id']);
            $classified = $this->classifier->classify($state);

            if ($classified === null) {
                continue;
            }

            $lastMovedAt = $row['updated_at'];
            $movedAtTimestamp = strtotime($lastMovedAt);

            $signals[] = new AbandonmentSignal(
                state: $classified,
                sessionUuid: $row['session_uuid'],
                opportunityId: $state->opportunityId,
                // The funnel's own recording (`[21.7]`), passed through as it
                // stands. Null is honest: nothing advances it past the last
                // completed teleform today, so a journey that stopped at
                // checkout carries the step before it rather than a guess.
                furthestStep: $state->furthestStep,
                resumeUrl: $this->resumeUrl($row['session_uuid']),
                marketingConsent: AbandonmentSignal::marketingConsentOf($state, $this->marketingConsentKey),
                lastMovedAt: $lastMovedAt,
                idleSeconds: $movedAtTimestamp === false ? 0 : max(0, $now - $movedAtTimestamp),
            );

            $this->auditAccess($row['session_uuid'], $classified);
        }

        return $signals;
    }

    /**
     * `[30.7]`: this journey was read and a signal carrying its resume link is
     * about to leave.
     *
     * Recorded for the rows that produced a signal, not for every row the
     * window returned. A journey that classifies as nothing is deserialised
     * and discarded in-process — nothing about it reaches the poller — and a
     * trail claiming otherwise would overstate what happened, which on an
     * audit table is the one error that costs more than a missing row.
     *
     * The payload is who, what and why, and stops there (`[20.14]`, `[20.6]`):
     * the actor, the reason, the funnel state the journey stopped in, and the
     * fact that a resume link went with it. Not the link itself, which is a
     * credential for the very answers this row exists to protect, and not one
     * word of the answers.
     */
    private function auditAccess(string $sessionUuid, AbandonmentState $state): void
    {
        $this->events?->append($sessionUuid, self::ACCESS_EVENT, [
            'actor' => 'abandonment_sweep',
            'reason' => 'abandonment_signal',
            'state' => $state->value,
            'resume_link' => true,
        ]);
    }

    /**
     * The link that puts this visitor back where they were (`[21.11]`).
     *
     * The site root rather than the step they stopped on, because the resume
     * parameter is read on every request and the funnel guard then routes them
     * to wherever their own journey says they belong — which is a fresher and
     * more reliable answer than a path frozen into an email hours earlier.
     *
     * See {@see AbandonmentSignal}'s docblock for the `[21.4]` exposure this
     * link carries and does not close.
     */
    private function resumeUrl(string $sessionUuid): string
    {
        return rtrim($this->baseUrl, '/') . '/?' . http_build_query(
            [$this->resumeParam => $sessionUuid],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );
    }
}
