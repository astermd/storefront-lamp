<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Domain;

/**
 * The result of one cart mutation: whether it was applied, and the sentence
 * the buyer is shown.
 *
 * A notice is not an error channel — an accepted mutation carries one too
 * (a replaced prescription, `[8.0h]`), because the rule that makes the
 * replacement safe is the one that says it must be visible rather than
 * silent.
 */
final class CartOutcome
{
    private function __construct(
        public readonly bool $accepted,
        public readonly ?string $notice,
    ) {
    }

    public static function accepted(?string $notice = null): self
    {
        return new self(true, $notice);
    }

    public static function rejected(string $notice): self
    {
        return new self(false, $notice);
    }
}
