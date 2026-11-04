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
 *
 * `$reference` is the pricing system's own id for the code when it gave one
 * ({@see \AsterMD\Storefront\Payment\PromotionQuote::$reference}), kept with
 * the code so the order can name the discount the way that system will look
 * it up again.
 *
 * `$lineDiscounts` is how the discount falls across the cart's lines, by line
 * slug, when the provider said so ({@see \AsterMD\Storefront\Payment\PromotionQuote::$lineDiscounts}).
 * It is only ever shown: the amount charged is `$discountCents`.
 */
final class Promotion
{
    public function __construct(
        public readonly string $code,
        public readonly int $discountCents,
        public readonly ?string $reference = null,
        /** @var array<string, int> */
        public readonly array $lineDiscounts = [],
    ) {
    }

    /** That line's share of the discount, or 0 when the provider gave only the total. */
    public function discountOn(string $slug): int
    {
        return $this->lineDiscounts[$slug] ?? 0;
    }

    /** @return array{code: string, discount_cents: int, reference?: string, line_discounts?: array<string, int>} */
    public function toArray(): array
    {
        $stored = ['code' => $this->code, 'discount_cents' => $this->discountCents];

        if ($this->reference !== null) {
            $stored['reference'] = $this->reference;
        }

        if ($this->lineDiscounts !== []) {
            $stored['line_discounts'] = $this->lineDiscounts;
        }

        return $stored;
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

        $reference = $stored['reference'] ?? null;

        return new self(
            $code,
            $cents,
            is_string($reference) && $reference !== '' ? $reference : null,
            self::lineDiscounts($stored['line_discounts'] ?? null, $cents),
        );
    }

    /**
     * The stored breakdown, or none when any part of it is not a breakdown of
     * `$total`: it is display only, so a summary with the total alone is the
     * safe reading of a row it cannot trust.
     *
     * @return array<string, int>
     */
    private static function lineDiscounts(mixed $stored, int $total): array
    {
        if (!is_array($stored)) {
            return [];
        }

        $lines = [];
        foreach ($stored as $slug => $cents) {
            if (!is_string($slug) || $slug === '' || !is_int($cents) || $cents < 0) {
                return [];
            }
            $lines[$slug] = $cents;
        }

        return array_sum($lines) === $total ? $lines : [];
    }
}
