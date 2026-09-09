<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment;

/**
 * The buyer as the provider needs them (`[13.1]`).
 *
 * Billing is taken to be the shipping address (`[13.2]`), so there is one
 * address here rather than two: a provider that wants both receives this one
 * twice, which is the adapter's business, not the funnel's.
 *
 * `$territory` is the uppercased two-letter code the geo gate was re-run
 * against on submit (`[13.3]`, `[13.6]`).
 */
final class Buyer
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $email,
        public readonly string $phone,
        public readonly string $addressLine,
        public readonly string $city,
        public readonly string $territory,
        public readonly string $postalCode,
        public readonly string $country = 'US',
    ) {
    }
}
