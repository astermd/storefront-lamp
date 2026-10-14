<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Emr;

/**
 * The port bound when `features.address_autocomplete` is off -- always the
 * case under the test suite, whose runs must never reach the network.
 *
 * It answers "could not ask" to everything, which is what the live one answers
 * in an outage, so the checkout behaves the same either way: the buyer types
 * the address.
 */
final class NullAddressAutofill implements AddressAutofill
{
    public function suggest(string $search): ?array
    {
        return null;
    }

    public function resolve(string $address): ?array
    {
        return null;
    }
}
