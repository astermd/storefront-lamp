<?php

/**
 * When a stopped journey counts as abandoned, and how far back to look.
 *
 * `[21.12]` makes abandonment detection server-side and time-based rather than
 * a browser event, so these four numbers are the whole definition. They are
 * configuration rather than constants because the right interval is a property
 * of the funnel, not of the software: a single-page supplement store and a
 * multi-step telehealth intake do not agree about how long a pause means
 * somebody has gone.
 *
 * The signal these produce is read by `bin/console abandonment:signals`, which
 * `[21.11a]` exists for — the storefront exposes the state and the marketing
 * platform does the sending.
 *
 * **What the signal carries is a credential.** `[21.11]` requires a resume
 * link, and a resume link restores somebody's intake answers, which are health
 * information. Treat the command's output as PHI: it belongs in the automation
 * platform and nowhere else — not a CI artefact, not a shell history, not a
 * log.
 *
 * **The link is permanent, by decision rather than by omission.** `[21.4]`
 * asks for a resume link that is short-lived, single-purpose and revoked once
 * the journey completes. This deployment's link is the raw analytics session
 * identifier — `?amd_session=<uuid>` — it carries no expiry, and completing a
 * journey does not revoke it. That was chosen deliberately over a tokenised
 * form, so it is not a gap waiting to be closed and nothing here should be
 * "fixed" into revoking it. What follows from the choice is that the exposure
 * is permanent too: anyone holding the URL holds indefinite access to that
 * person's intake answers. The compensating control is the audit trail — every
 * use of a resume link, and every sweep that serialises a journey into a
 * signal, is recorded as an access to health information (`[30.7]`), because
 * with no expiry the log is the only record that the link was used.
 */
return [
    /**
     * How long a journey must sit unmoved before it is abandoned.
     *
     * Measured against `sessions.updated_at`, which is a true "last moved"
     * timestamp rather than "last seen": the journey store only writes when
     * the state's fingerprint actually changed, so reloading a page does not
     * reset the clock and a visitor reading a long intake page is not counted
     * as having left.
     *
     * An hour is deliberately not aggressive. The cost of being early is a
     * "you left something behind" email to somebody still filling in the form.
     */
    'idle_seconds' => (int) ($env['ABANDONMENT_IDLE_SECONDS'] ?? 3600),

    /**
     * How far back a sweep looks for stopped journeys.
     *
     * Matched to the 30-day session cookie lifetime in `app.session`: a
     * journey older than its own cookie cannot be resumed by the visitor who
     * left it, so a recovery email pointing at it would be an invitation to a
     * dead link. Shorten this and old journeys stop being chased; lengthen it
     * past the cookie and they are chased without being recoverable.
     */
    'lookback_seconds' => (int) ($env['ABANDONMENT_LOOKBACK_SECONDS'] ?? 2592000),

    /**
     * The most signals one run will emit.
     *
     * A bound rather than a page: the sweep is a snapshot an external system
     * polls, and a run that would exceed this reports what it found rather
     * than paging, because a partial snapshot delivered on time is worth more
     * to a recovery campaign than a complete one delivered late.
     */
    'limit' => (int) ($env['ABANDONMENT_LIMIT'] ?? 500),

    /**
     * Which consent key carries marketing permission.
     *
     * `[21.11b]` requires a signal for somebody who declined marketing to be
     * marked so the platform can suppress it, and `[26.4]` keeps marketing and
     * transactional consent permanently separate — declining marketing must
     * never suppress a shipping notification. This names the key in
     * `config/consent.php` that answers the marketing half.
     *
     * The signal reports `granted`, `declined` or `not_asked` rather than a
     * boolean, and the third value is not a technicality: consents are only
     * recorded at checkout, so most abandoned journeys never reached the
     * question. Whether "never asked" may be mailed is a decision for the
     * deployment and its counsel, and collapsing it into a boolean here would
     * make that decision silently on their behalf.
     */
    'marketing_consent_key' => (string) ($env['ABANDONMENT_MARKETING_CONSENT_KEY'] ?? 'marketing'),
];
