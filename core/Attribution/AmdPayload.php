<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Attribution;

use AsterMD\Sdk\Support\QueryParamCipher;

/**
 * Reads the `_amd` tracking payload that affiliate links carry instead of
 * plain query parameters.
 *
 * The parameters are encrypted so browser extensions cannot rewrite
 * affiliate identifiers in transit (`[5.1]`), and the cipher itself belongs
 * to the SDK: {@see QueryParamCipher::decrypt()} owns the wire format and
 * the key shape, and is already total — an absent, truncated, tampered or
 * wrongly-keyed token comes back as an empty list rather than an exception,
 * which is what lets the caller fall back silently to plain parameters
 * (`[5.3]`). This class contributes only the secret key. A payload that
 * decrypts successfully but carries no entries is indistinguishable here from
 * a token that could not be read; both return `null`. This conflation is
 * harmless: the caller's response is identical either way (fall back to plain
 * parameters), and both situations merit an operator's attention in the log.
 *
 * What it does add is the rotation overlap (`[5.2b]`): keys are tried in
 * order, current first, so a deployment mid-rotation keeps reading links
 * that were signed with the previous key and are already sitting in sent
 * emails and bought ads where they cannot be recalled.
 *
 * Decryption is in-process by requirement (`[5.2a]`) — this runs on the
 * first request of every affiliate landing, so a network call to a key
 * service here would make that service's outage an outage of the entire top
 * of the funnel. A decoded payload is still untrusted input: AES-CBC carries
 * no authenticity, so the values are normalised against the canonical alias
 * table before anything uses them.
 */
final class AmdPayload
{
    /**
     * @param string       $token   the raw `_amd` value from the URL
     * @param list<string> $keysHex secret keys as the SDK expects them, current key first
     *
     * @return array<string, string>|null decoded parameters, or null when no key could read the token
     */
    public static function decode(string $token, array $keysHex): ?array
    {
        if ($token === '') {
            return null;
        }

        foreach ($keysHex as $keyHex) {
            $pairs = QueryParamCipher::decrypt($token, $keyHex);
            if ($pairs === []) {
                continue;
            }

            $params = [];
            foreach ($pairs as $pair) {
                $params[$pair['key']] = $pair['value'];
            }

            return $params;
        }

        return null;
    }
}
