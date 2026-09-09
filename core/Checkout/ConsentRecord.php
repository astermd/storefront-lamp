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
}
