<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Checkout;

use AsterMD\Storefront\Payment\Buyer;

/**
 * The checkout form's buyer fields (`[13.1]`), canonicalised once on the way
 * in.
 *
 * Every sub-action on the checkout page — a bump toggle, a promo apply, a plan
 * switch — reposts the whole form (`[27.10]`), so these values are built,
 * validated, stored in journey state, re-rendered and eventually handed to the
 * payment adapter. Canonicalising at the single point they enter the system is
 * what stops those five readers from disagreeing: `(212) 555-1234` typed once
 * is `2125551234` to the validator, to the idempotency key, to the stored
 * state and to the provider, so a buyer who changes nothing does not look like
 * a buyer who changed something.
 *
 * The field names are the checkout page's own, and match what
 * {@see Prefill::from()} produces, so a prefilled render and a submitted form
 * are the same set of keys read in opposite directions.
 *
 * Billing is shipping (`[13.2]`), so there is one address here, not two.
 */
final class BuyerDetails
{
    /** Checkout field name → the property it fills. */
    private const array FIELDS = [
        'first_name' => 'firstName',
        'last_name' => 'lastName',
        'email' => 'email',
        'phone' => 'phone',
        'address_line' => 'addressLine',
        'city' => 'city',
        'territory' => 'territory',
        'postal_code' => 'postalCode',
    ];

    public function __construct(
        public readonly string $firstName = '',
        public readonly string $lastName = '',
        public readonly string $email = '',
        public readonly string $phone = '',
        public readonly string $addressLine = '',
        public readonly string $city = '',
        public readonly string $territory = '',
        public readonly string $postalCode = '',
    ) {
    }

    /**
     * Reads the posted body, keeping only the fields checkout owns.
     *
     * Everything is trimmed, the phone is reduced to its digits and the
     * territory is uppercased (`[13.1]`). A missing key is an empty string
     * rather than null, because "not sent" and "sent blank" are the same
     * omission to a buyer and validation should say so in the same words.
     *
     * @param array<string, mixed> $body
     */
    public static function fromSubmitted(array $body): self
    {
        $values = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $values[$field] = self::text($body[$field] ?? null);
        }

        return new self(
            firstName: $values['first_name'],
            lastName: $values['last_name'],
            email: $values['email'],
            phone: self::digits($values['phone']),
            addressLine: $values['address_line'],
            city: $values['city'],
            territory: strtoupper($values['territory']),
            postalCode: $values['postal_code'],
        );
    }

    /**
     * The canonical field set, keyed the way the form, journey state and
     * prefill all key it.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $out = [];
        foreach (self::FIELDS as $field => $property) {
            $out[$field] = $this->{$property};
        }

        return $out;
    }

    /**
     * The same buyer as the payment adapter's contract wants them.
     *
     * The conversion lives here rather than in the service so the field-name
     * translation happens once: a provider payload that disagreed with the
     * stored order about which value was the city would be invisible until a
     * parcel went astray.
     */
    public function toBuyer(): Buyer
    {
        return new Buyer(
            firstName: $this->firstName,
            lastName: $this->lastName,
            email: $this->email,
            phone: $this->phone,
            addressLine: $this->addressLine,
            city: $this->city,
            territory: $this->territory,
            postalCode: $this->postalCode,
        );
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * A phone as the digits it contains.
     *
     * Formatting is the buyer's business and punctuation carries no
     * information: `(212) 555-1234` and `2125551234` are one number, and
     * storing them as two would make a corrected typo indistinguishable from a
     * reformatted one, which is exactly the distinction the idempotency key
     * rests on (`[13.37]`).
     */
    private static function digits(string $value): string
    {
        return (string) preg_replace('/\D+/', '', $value);
    }
}
