<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Checkout;

use AsterMD\Storefront\Payment\OrderEnvelope;
use AsterMD\Storefront\Payment\PaymentCredential;
use AsterMD\Storefront\Payment\PlacementOutcome;

/**
 * The recorder bound while nothing is persisting orders yet.
 *
 * It exists so {@see CheckoutService} can be written, wired and covered
 * against a port rather than against a table — the same reason
 * {@see \AsterMD\Storefront\Emr\NullCartGateway} exists. A deployment that
 * reaches production with this bound has no local order record and therefore
 * no way to answer `[18.1]`'s "the provider charged someone but the EMR never
 * heard of it", which is why the real implementation is not optional.
 */
final class NullOrderRecorder implements OrderRecorder
{
    /** @param list<ConsentRecord> $consents */
    public function record(
        OrderEnvelope $order,
        PlacementOutcome $outcome,
        PaymentCredential $credential,
        array $consents,
        string $providerCategory,
        bool $isUpsell = false,
    ): ?int {
        return null;
    }
}
