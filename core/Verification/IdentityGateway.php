<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Verification;

/**
 * One identity check against the EMR's configured identity provider
 * (`[22.13]`-`[22.21]`).
 *
 * Deliberately **not** {@see \AsterMD\Storefront\Emr\VerificationGateway},
 * which shares the EMR's `verification()` resource and nothing else: that one
 * asks whether an email would arrive and how a carrier spells an address, and
 * is a form aid. This one asks whether a person is who they say they are, is a
 * compliance artefact (`[22.20]`), and carries a Social Security Number to do
 * it. Merging them would put an SSN behind a switch called "address check".
 *
 * The port performs **one** check and takes no view on which, or on what runs
 * next. `[22.15]` makes the sequence a property of the flow definition and the
 * spec names no check order at all, so the order lives in
 * `config/verification.php` and is walked by
 * {@see IdentityVerificationSequence}.
 *
 * Every implementation returns a verdict rather than throwing. A check that
 * cannot run must never present as a buyer failing (`[20.1]`), and the only
 * way to guarantee that at every call site is to leave no failure channel for
 * a caller to mistranslate.
 */
interface IdentityGateway
{
    /**
     * Runs `$check` against `$identity` and answers with one of three states.
     *
     * @param string               $check    a check slug as `config/verification.php` names it
     * @param array<string, mixed> $identity recognised keys are `firstName`, `lastName`, `email`,
     *                                       `phone`, `dob` (`YYYY-MM-DD`), `ssn`, `ipAddress` and
     *                                       `address`; which are required depends on the check
     */
    public function verify(string $check, array $identity): IdentityVerdict;

    /** Whether this gateway reaches a provider at all, so a caller can skip the round trip. */
    public function isEnabled(): bool;
}
