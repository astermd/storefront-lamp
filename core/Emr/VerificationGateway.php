<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Emr;

/**
 * Address and email checks against the EMR's configured third-party
 * providers. One of the ports listed in `docs/ARCHITECTURE.md`, "Ports, Null
 * implementations, and the container seam", which is where its live and Null
 * implementations and the switch between them are described.
 *
 * Every method returns null for "could not determine", which is a third
 * distinct answer from yes and no and the most important one here: this
 * storefront's credential is currently refused for the whole `verification()`
 * resource -- `verifyEmail`, `verifyAddress` and `autofillAddress` all answer
 * "You do not have permission to perform this action" against a freshly
 * minted token. A gateway that could only say yes or no would have to call
 * every buyer's email invalid.
 *
 * An undeterminable answer never blocks an order (`[20.1]`). These checks
 * improve a form; they do not gate a purchase.
 */
interface VerificationGateway
{
    /** True, false, or null when the check could not be run. */
    public function emailIsDeliverable(string $email): ?bool;

    /** @return array<string, string>|null the normalised address, or null when the check could not be run */
    public function normaliseAddress(string $address): ?array;

    /** Whether this gateway does anything at all, so a caller can skip the round trip. */
    public function isEnabled(): bool;
}
