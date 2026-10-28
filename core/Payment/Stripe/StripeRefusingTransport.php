<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment\Stripe;

use Stripe\HttpClient\ClientInterface;

/**
 * Stripe's HTTP client under the test environment: refuses to send.
 *
 * The third provider's half of the fence {@see \AsterMD\Storefront\Payment\RefusingTransport}
 * and {@see \AsterMD\Storefront\Payment\CheckoutChamp\CheckoutChampRefusingTransport}
 * are for theirs: the adapter is chosen from synced channel data rather than
 * from the environment, so nothing else stands between a test and a real
 * payment. Stripe's SDK declares its own client interface, so it gets its own
 * fence.
 */
final class StripeRefusingTransport implements ClientInterface
{
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        throw new \RuntimeException(
            'The payment provider was called from the test environment. '
            . 'Substitute a transport at the adapter seam, or override PaymentAdapter in the container. '
            . 'Refusing to send so a missing stub cannot create a real payment.',
        );
    }
}
