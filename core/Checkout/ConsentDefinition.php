<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Checkout;

/**
 * One configured consent control (`[26.3]`, `[26.8]`).
 *
 * `$links` duplicates the hrefs already inside `$html` on purpose: a
 * deploy-time check that every legal document resolves (`[26.9]`) needs them
 * as data, and parsing them back out of markup would break the moment someone
 * writes an href with single quotes.
 */
final class ConsentDefinition
{
    /** @param list<string> $links */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $html,
        public readonly bool $blocking,
        public readonly array $links,
    ) {
    }
}
