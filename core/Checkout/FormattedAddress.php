<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Checkout;

/**
 * A one-line US address split into the checkout's four fields.
 *
 * The EMR's address services answer formatted strings rather than components:
 * `350 5th Avenue, New York, NY 10118-0110, USA` from the verifier, and the
 * same without the ZIP from the autofill. Reading them right to left is
 * reliable where reading left to right is not -- the country, then
 * `STATE ZIP` (or `STATE` alone), then the city are each one comma-separated
 * part, and everything before the city is the street, which may itself hold a
 * comma (`123 Main St, Suite 5`).
 *
 * The ZIP is cut to its five digits. The checkout accepts ZIP+4, but the
 * buyer is used to typing five, and a field that fills with a figure they do
 * not recognise reads as a mistake.
 *
 * Anything that is not a US address in that shape answers null, and the
 * caller fills nothing rather than half a form.
 */
final class FormattedAddress
{
    private const string COUNTRY = '/,\s*(USA|United States)\s*$/i';

    private const string STATE_AND_ZIP = '/^([A-Z]{2})(?:\s+(\d{5})(?:-\d{4})?)?$/';

    /** @return array{address_line: string, city: string, territory: string, postal_code: string}|null */
    public static function parse(string $formatted): ?array
    {
        $formatted = trim($formatted);

        if (preg_match(self::COUNTRY, $formatted) !== 1) {
            return null;
        }

        $parts = array_map('trim', explode(',', (string) preg_replace(self::COUNTRY, '', $formatted)));

        if (count($parts) < 3 || preg_match(self::STATE_AND_ZIP, (string) end($parts), $match) !== 1) {
            return null;
        }

        array_pop($parts);
        $city = (string) array_pop($parts);
        $street = implode(', ', array_filter($parts, static fn (string $part): bool => $part !== ''));

        if ($street === '' || $city === '') {
            return null;
        }

        return [
            'address_line' => $street,
            'city' => $city,
            'territory' => $match[1],
            'postal_code' => $match[2] ?? '',
        ];
    }

    /** Whether an autofill suggestion names a US address, judged the same way {@see self::parse()} judges it. */
    public static function isUnitedStates(string $formatted): bool
    {
        return preg_match(self::COUNTRY, trim($formatted)) === 1;
    }
}
