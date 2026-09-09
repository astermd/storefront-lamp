<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Attribution;

/**
 * Reduces a raw parameter bag to canonical tracking keys.
 *
 * Pure and total: unrecognised parameters are dropped rather than carried
 * along, and a value that is absent, blank after trimming, or not a scalar
 * is treated as not present at all — so a link carrying `?aff_id=` never
 * shadows a real affiliate id arriving under another alias.
 */
final class AttributionNormalizer
{
    /**
     * @param array<array-key, mixed> $params raw query or decrypted-payload parameters
     *
     * @return array<string, string> canonical key => value, only non-empty entries
     */
    public static function normalize(array $params): array
    {
        $out = [];

        foreach (CanonicalKeys::aliases() as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                if (!array_key_exists($alias, $params) || !is_scalar($params[$alias])) {
                    continue;
                }

                $value = trim((string) $params[$alias]);
                if ($value === '') {
                    continue;
                }

                $out[$canonical] = $value;
                break;
            }
        }

        return $out;
    }
}
