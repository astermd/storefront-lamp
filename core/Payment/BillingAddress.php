<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment;

/**
 * The address the card is billed to, when the buyer gave one apart from the
 * delivery address.
 *
 * Billing defaults to shipping (`[13.2]`), so a {@see Buyer} carries one of
 * these only when the buyer ticked "Use a different billing address". Its
 * absence means "bill the delivery address", never "no billing address".
 */
final class BillingAddress
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $addressLine,
        public readonly string $city,
        public readonly string $territory,
        public readonly string $postalCode,
        public readonly string $country = 'US',
    ) {
    }

    public function fullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }
}
