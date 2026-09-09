<?php

/**
 * How long each category of data is kept, and what a deletion request can
 * actually reach.
 *
 * §30 opens by stating the conflict plainly: privacy regulation gives a person
 * the right to have their data deleted, while medical-records regulation
 * requires clinical records to be kept for years. Both apply here. `[30.2]`
 * resolves it by refusing to treat the data as one category, and this file is
 * that resolution written down — every period below belongs to exactly one of
 * `[30.2]`'s four categories, and the categories behave differently on purpose.
 *
 * | category | what it is | on a deletion request |
 * |---|---|---|
 * | marketing | attribution, UTM, affiliate ids, abandonment state, consent | delete — no basis survives withdrawal |
 * | clinical | intake answers, clinician decisions, prescriptions, treatments | retain — the EMR is the authority, not this storefront |
 * | transaction | orders, charges | retain for financial and tax obligations |
 * | operational | sessions, events, logs | delete on this schedule; no long-term basis |
 *
 * **`[30.5]` makes these configuration rather than constants** because the
 * correct period varies by jurisdiction and this theme ships to more than one
 * market. The defaults below are conservative; they are not legal advice and a
 * deployment is expected to set its own.
 *
 * **`[30.6]` is the reason the periods are enforced by `bin/console db:prune`
 * rather than by intent.** Data that is merely supposed to expire does not
 * expire — every row in the local database predating this file proves it.
 *
 * ## What this storefront cannot delete, stated honestly
 *
 * `[30.4]` says the storefront is not the authority on clinical retention: it
 * forwards a deletion request to the EMR and reports what the EMR decided.
 * **There is no transport for that.** `astermd/sdk` exposes exactly one
 * deletion route across its whole `Resource/` directory — `sessions()->delete()`,
 * which removes an *analytics* session, not a clinical record. So a deletion
 * request against clinical data is an operator procedure carried out in the
 * EMR, and this storefront's part is limited to the marketing and operational
 * categories it owns. `docs/OPERATIONS.md` carries the procedure and names the
 * gap; inventing a receiver that nothing shows the EMR would call would be a
 * worse answer than saying so.
 */
return [
    /**
     * Operational data (`[30.2]`, `[30.6]`) — the categories `db:prune`
     * actually enforces.
     *
     * These are the ones with no long-term basis: a session row, its audit
     * events and the operator log exist to run and debug the funnel, and once
     * a journey is long finished they are pure liability.
     */
    'operational' => [
        /**
         * Analytics session rows, and the durable journey state they carry.
         *
         * Ninety days rather than the 30-day cookie lifetime, because the row
         * outlives the visitor's ability to resume it for a reason: both
         * reconciliation sweeps join through it, and a session deleted while an
         * order still references it turns a recoverable charge into an orphan.
         * `db:prune` additionally refuses to delete any session an `orders` row
         * points at, at any age — the period is a floor, not an override.
         */
        'session_days' => (int) ($env['RETENTION_SESSION_DAYS'] ?? 90),

        /**
         * The local audit trail (`[18.4]`).
         *
         * Longer than the sessions it describes, because `[30.7]` makes this
         * the record of who read health information and when, and a breach
         * investigation that starts after the sessions have gone still needs
         * it. It is also the only durable record that a resume link was used —
         * see `config/abandonment.php` for why that matters here.
         */
        'event_days' => (int) ($env['RETENTION_EVENT_DAYS'] ?? 365),

        /**
         * Files under `storage/logs/`.
         *
         * `[30.6]` names debug logs specifically, and `[30.9]` requires
         * deletion to reach the copies that are easy to forget — the wire log
         * is exactly such a copy, and while it ships off, a deployment that
         * ever turned it on has verbatim card and identity payloads on disk.
         * Short on purpose.
         */
        'log_days' => (int) ($env['RETENTION_LOG_DAYS'] ?? 30),
    ],

    /**
     * Transaction data (`[30.2]`) — retained, and named here so the period is
     * declared rather than implied by the absence of a job.
     *
     * `db:prune` does not delete `orders`, `order_lines` or `order_consents` at
     * any age. Seven years is the common financial-records floor; a deployment
     * whose jurisdiction says otherwise changes it here and changes its own
     * archival procedure, because nothing in this application acts on it.
     */
    'transaction' => [
        'order_days' => (int) ($env['RETENTION_ORDER_DAYS'] ?? 2557),
    ],

    /**
     * Clinical data (`[30.2]`) — retained by the EMR, which is the system of
     * record and the authority (`[30.4]`).
     *
     * Declared with a null period rather than a number, because a number here
     * would be this storefront deciding something `[30.4]` explicitly forbids
     * it from deciding. Intake answers reach the EMR; the copy held in journey
     * state expires with the session under `operational.session_days` above.
     */
    'clinical' => [
        'authority' => 'emr',
        'days' => null,
    ],

    /**
     * Marketing data (`[30.2]`) — deleted on request, and expired with the
     * session that carries it.
     *
     * First-touch attribution, the derived source category and the abandonment
     * state all live inside `sessions.attribution` and `sessions.journey_state`,
     * so they are already governed by `operational.session_days`. The period is
     * restated here so a reader looking for the marketing category finds an
     * answer rather than an omission.
     */
    'marketing' => [
        'follows' => 'operational.session_days',
    ],
];
