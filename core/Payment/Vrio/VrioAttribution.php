<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment\Vrio;

/**
 * Capability 7: canonical attribution keys → this provider's 20 generic
 * tracking slots (`[14.8]`).
 *
 * CRMs expose numbered slots and no named UTM fields (`[14.5]`), so the UTM
 * values are mapped into slots by convention. Reserving 15–19 for the five UTM
 * parameters costs sub-identifiers 15–19 their slot; that trade is safe in
 * practice because real campaigns send only a handful of sub-identifiers, and
 * the drop is reported rather than silent (`[14.7]`).
 *
 * The map is confirmed by live data on this account: orders placed before this
 * storefront existed carry `tracking15: "fb"`, `tracking16: "email"` and
 * `tracking17: "summar-sale"`, which is source/medium/campaign in exactly this
 * order.
 */
final class VrioAttribution
{
    /**
     * Canonical key → slot number. Order is the contract, not an accident.
     *
     * @var array<string, int>
     */
    private const array SLOTS = [
        'affiliate_id' => 1,
        'c1' => 2, 'c2' => 3, 'c3' => 4, 'c4' => 5, 'c5' => 6, 'c6' => 7, 'c7' => 8,
        'c8' => 9, 'c9' => 10, 'c10' => 11, 'c11' => 12, 'c12' => 13, 'c13' => 14,
        'utm_source' => 15, 'utm_medium' => 16, 'utm_campaign' => 17, 'utm_term' => 18, 'utm_content' => 19,
        'c14' => 20,
    ];

    /**
     * @param  array<string, string> $attribution canonical keys → values
     * @return array{0: array<string, string>, 1: list<string>} the slot fields, and the keys that had nowhere to go
     */
    public static function map(array $attribution): array
    {
        $slots = [];
        $dropped = [];

        foreach ($attribution as $key => $value) {
            $value = trim((string) $value);

            // An empty value is omitted, not dropped: nothing was lost, there
            // was simply nothing to send (`[14.6]`).
            if ($value === '') {
                continue;
            }

            $slot = self::SLOTS[$key] ?? null;
            if ($slot === null) {
                $dropped[] = $key;

                continue;
            }

            $slots['tracking' . $slot] = $value;
        }

        return [$slots, $dropped];
    }
}
