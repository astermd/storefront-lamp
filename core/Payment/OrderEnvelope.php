<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment;

/**
 * Everything an adapter needs to place one order, in provider-neutral terms
 * (`[14.1]` capability 4).
 *
 * The whole cart is one order (`[13.19]`), so there is one envelope per
 * checkout: top-level products, mandatory bundles, free attachments and any
 * accepted order bumps are all lines here by the time this is built (§27).
 *
 * `$attribution` is the canonical key map from `Attribution\CanonicalKeys`;
 * mapping it into the provider's own slots is capability 7 and belongs to the
 * adapter, which must also report what it had to drop (`[14.7]`).
 *
 * `$idempotencyKey` is the storefront's, not the provider's: the recorded
 * provider charges an identical payload twice and ignores the external order
 * id it is handed, so a duplicate submit is caught before the call, never by
 * it (`[13.37]`).
 */
final class OrderEnvelope
{
    /**
     * @param list<OrderLine>       $lines
     * @param array<string, string> $attribution canonical keys → values
     */
    public function __construct(
        public readonly array $lines,
        public readonly Buyer $buyer,
        public readonly int $subtotalCents,
        public readonly int $discountCents,
        public readonly int $totalCents,
        public readonly string $currency,
        public readonly ?string $promotionCode,
        public readonly array $attribution,
        public readonly ?string $sessionUuid,
        public readonly ?string $clientIp,
        public readonly ?string $userAgent,
        public readonly string $idempotencyKey,
        public readonly string $anchorSlug,
    ) {
    }

    /** @return list<OrderLine> the lines the provider can actually be told about */
    public function chargeableLines(): array
    {
        return array_values(array_filter($this->lines, static fn (OrderLine $l): bool => $l->isChargeable()));
    }

    /** @return list<OrderLine> lines with no provider identity, for the operator log */
    public function unmappedLines(): array
    {
        return array_values(array_filter($this->lines, static fn (OrderLine $l): bool => !$l->isChargeable()));
    }
}
