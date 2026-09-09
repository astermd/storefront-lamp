<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Checkout;

/**
 * A code the provider accepted and the discount it calculated (`[13.11]`).
 *
 * Both halves are stored together because either alone is misleading: a code
 * with no amount cannot be shown on a summary, and an amount with no code
 * cannot be re-attached to the order. It is dropped after a successful
 * checkout so it cannot bleed into the upsell flow (`[13.17]`).
 */
final class Promotion
{
    public function __construct(
        public readonly string $code,
        public readonly int $discountCents,
    ) {
    }

    /** @return array{code: string, discount_cents: int} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'discount_cents' => $this->discountCents];
    }

    /**
     * Rebuilds a stored promotion, or null when what was stored is not one.
     *
     * Journey state survives a deploy, so a row written by an older shape is a
     * real possibility; discarding it costs the visitor a re-entered code,
     * while trusting it would put an unvalidated number into a total.
     *
     * @param array<string, mixed>|null $stored
     */
    public static function fromArray(?array $stored): ?self
    {
        if ($stored === null) {
            return null;
        }

        $code = $stored['code'] ?? null;
        $cents = $stored['discount_cents'] ?? null;

        if (!is_string($code) || $code === '' || !is_int($cents)) {
            return null;
        }

        return new self($code, $cents);
    }
}
