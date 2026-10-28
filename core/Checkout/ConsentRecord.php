<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Checkout;

/**
 * What one visitor agreed to, and the exact words they were shown (`[26.6]`).
 *
 * The copy is stored alongside the version rather than only the version,
 * because a version stamp is only a pointer: it resolves to the right wording
 * for as long as the deployment keeps every past config revision, and no
 * deployment does.
 */
final class ConsentRecord
{
    public function __construct(
        public readonly string $key,
        public readonly bool $granted,
        public readonly string $copyVersion,
        public readonly string $copyShown,
        public readonly string $at,
    ) {
    }

    /** @return array{key: string, granted: bool, copy_version: string, copy_shown: string, at: string} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'granted' => $this->granted,
            'copy_version' => $this->copyVersion,
            'copy_shown' => $this->copyShown,
            'at' => $this->at,
        ];
    }

    /**
     * Rebuilds a record journey state kept, or null when what was kept is not
     * one -- for an order finished in a later request than the one the buyer
     * consented in (`[14.22]`), whose consents were recorded then and are not
     * asked for again.
     *
     * @param array<string, mixed> $stored
     */
    public static function fromArray(array $stored): ?self
    {
        $key = $stored['key'] ?? null;
        $granted = $stored['granted'] ?? null;

        if (!is_string($key) || $key === '' || !is_bool($granted)) {
            return null;
        }

        return new self(
            $key,
            $granted,
            (string) ($stored['copy_version'] ?? ''),
            (string) ($stored['copy_shown'] ?? ''),
            (string) ($stored['at'] ?? ''),
        );
    }
}
