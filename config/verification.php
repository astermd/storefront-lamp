<?php

/**
 * Identity verification: where the step sits, whether it stops anybody, and
 * which checks it runs.
 *
 * `[22.13]` makes identity verification a capability the theme provides and a
 * placement the store owner chooses, so none of this is hardcoded. `[22.16]`
 * is the rule most easily got wrong: placement and blocking are two separate
 * settings and must not be conflated -- a deployment may legitimately want the
 * step on the receipt page and still refuse to fulfil an order that failed it.
 *
 * `[22.20]` is not configurable. The outcome and its timestamp are recorded on
 * the order whatever the placement, because it is a compliance artefact rather
 * than a funnel convenience.
 *
 * NEITHER IS THE LIFE OF A VERDICT
 *
 * A recorded verdict stands for the life of the journey: a passed check is not
 * re-asked, at any age, and a blocking gate a journey has already opened stays
 * open. There is deliberately no expiry setting here. One was declared for a
 * while and honoured by nothing, which is worse than not offering it -- a
 * deployment could set a window, read the paragraph describing it, and get a
 * gate that ignored it. If a window is wanted, it has to be built first and
 * documented second.
 *
 * WHY THIS SHIPS DISABLED AND NON-BLOCKING
 *
 * Recorded against the live provider integration on 2026-08-25: twenty calls
 * across nine identities and all three checks returned `valid: false`. Not one
 * passed. `crosscheck` scored exactly 0 against its configured threshold of
 * 0.75 -- for a real, deliverable identity with a valid phone and a real
 * originating address, and for a fabricated one, alike. The two are
 * indistinguishable in the response, so the provider cannot currently tell
 * anybody apart.
 *
 * The failure is not a threshold that could be lowered. `crosscheck` returns
 * `address_invalid` and `phone_invalid` even when called with name only, so it
 * penalises fields that were never sent; supplying real ones leaves the score
 * at 0. `dob_verify` and `ssn_verify` return a bare mismatch with no score at
 * all.
 *
 * The shape of a passing response is therefore unrecorded, and cannot be
 * recorded against this provider's sandbox -- there is no input that produces
 * one. That is why the pass branch of this feature is exercised against a fake
 * gateway rather than a live call.
 *
 * Enabled and blocking, this step refuses every real buyer. That is the whole
 * reason both switches are off, and it is a measurement rather than caution.
 * Turning either on is a decision that needs a fresh measurement: call the
 * provider with an identity known to be good and confirm it comes back
 * `valid: true` before trusting any verdict this integration produces.
 */
return [
    /**
     * The master switch. While false the step is not in the funnel at all and
     * the null gateway answers every call, so nothing reaches the provider.
     *
     * Deliberately separate from `app.features.emr_verification`, which
     * governs the unrelated email-deliverability and address-normalisation
     * checks at checkout. The two share a resource on the EMR and nothing
     * else; tying them together would mean an operator could not have the
     * address check without also running identity checks that refuse
     * everybody.
     */
    'enabled' => filter_var($env['IDENTITY_VERIFICATION'] ?? false, FILTER_VALIDATE_BOOL),

    /**
     * One of `intake`, `post_checkout`, `receipt`, `async` -- the four
     * placements `[22.14]` says all occur in practice.
     *
     * `[22.17]` recommends `intake` where the client has no strong preference,
     * because it is the only placement where a failure costs nothing: the
     * journey stops before any money has moved. Every other placement turns a
     * failure into a held order, a refund or a chase.
     *
     * `async` is declared for completeness and is not implemented: `[22.19]`
     * requires the same durable, authenticated, session-independent inbound
     * handling as a clinical review outcome, and this deployment has no
     * inbound receiver. Selecting it is a configuration error rather than a
     * silent downgrade.
     */
    'placement' => 'intake',

    /**
     * Whether a failed check stops the visitor advancing (`[22.16]`).
     *
     * False means the outcome is recorded and the journey continues, which is
     * the only honest setting while the provider cannot distinguish a real
     * identity from a fabricated one. See the note at the top of this file.
     */
    'blocking' => false,

    /**
     * The checks to run, in order, and what each one is sent.
     *
     * `[22.15]` makes this a step in the flow definition rather than a fixed
     * position, and the spec names no check sequence at all -- so the
     * escalation below is this theme's offer to a deployment, not a rule. A
     * deployment may run one check, all three, or a different order.
     *
     * Each entry stops the sequence when it returns a decisive verdict. An
     * inconclusive answer escalates to the next check; running out of checks
     * is itself inconclusive, never a refusal.
     *
     * `required` is what the provider rejects the request without -- recorded
     * per slug, because nothing documented it. `narrowing` is sent when the
     * journey has it. The distinction matters and is not cosmetic: called with
     * its required fields alone, `crosscheck` returns `address_invalid` and
     * `phone_invalid`, penalising two fields that were never sent. Sending the
     * minimum is therefore a way to fail a check that was never going to pass.
     */
    'checks' => [
        [
            'slug' => 'crosscheck',
            'required' => ['firstName', 'lastName'],
            'narrowing' => ['email', 'phone', 'ipAddress', 'address'],
        ],
        [
            'slug' => 'dob_verify',
            'required' => ['firstName', 'lastName', 'phone', 'dob'],
            'narrowing' => ['email', 'address'],
        ],
        [
            'slug' => 'ssn_verify',
            'required' => ['firstName', 'lastName', 'phone', 'ssn'],
            'narrowing' => ['email', 'dob', 'address'],
        ],
    ],
];
