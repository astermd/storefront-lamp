<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment;

/**
 * Everything an adapter needs to settle one authorized order, in
 * provider-neutral terms.
 *
 * **A value object rather than a bare reference, because the two providers need
 * different amounts of it and the difference is not a detail.** One settles from
 * the reference alone -- the authorization it holds already knows what it is
 * for. The other requires the lines to be sent again: a pre-authorized order
 * there carries an *empty* `items` array until the settling call supplies them,
 * so `orderId` on its own is answered "No products exist in the order" and the
 * order stays partial with the funds still held.
 *
 * That second provider cannot be helped by re-reading the order either, which is
 * what makes the lines a genuine input rather than something the adapter could
 * fetch: the projection it would read back is the same empty one.
 *
 * So the lines travel with the request, and the adapter that does not need them
 * ignores them. The alternative -- a narrower signature plus a per-provider
 * escape hatch -- would put a provider's shape back above `[14.1]`'s boundary,
 * which is the one thing that boundary exists to prevent.
 *
 * `$lines` are the same {@see OrderLine}s the placement was built from, read
 * back from `order_lines`. They carry the provider's own opaque identifiers, so
 * an adapter rebuilds its own request from them without the caller knowing how.
 */
final readonly class CaptureRequest
{
    /** @param list<OrderLine> $lines as the order was placed, from the storefront's own record */
    public function __construct(
        public string $reference,
        public array $lines = [],
    ) {
    }

    /** @return list<OrderLine> the lines an adapter can actually name to its provider */
    public function chargeableLines(): array
    {
        return array_values(array_filter($this->lines, static fn (OrderLine $l): bool => $l->isChargeable()));
    }
}
