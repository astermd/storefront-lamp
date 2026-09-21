<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment\CheckoutChamp;

use AsterMD\CheckoutChampClient\Http\HttpClientInterface;
use AsterMD\CheckoutChampClient\Http\Request;
use AsterMD\CheckoutChampClient\Http\Response;

/**
 * The fence around this provider in the test environment, the counterpart to
 * {@see \AsterMD\Storefront\Payment\RefusingTransport}.
 *
 * A separate class rather than a shared one because each provider's client
 * declares its **own** `HttpClientInterface`, and a transport has to implement
 * the one its client will accept. That is the honest shape of two vendored
 * packages: the neutrality `[14.3]` asks for is about what the *storefront*
 * knows, not about pretending two third-party interfaces are one.
 *
 * It lives in this provider's namespace for the same reason, so a third
 * provider adds its fence beside its adapter rather than growing a switch in
 * `core/Payment/`.
 *
 * The reasoning is unchanged: payment is the one port with no environment-based
 * Null implementation — the adapter is chosen from synced channel data
 * (`[14.3]`) — so the fence goes at the wire, leaving capability declarations
 * and payload assembly fully testable while making the one irreversible
 * operation impossible.
 */
final class CheckoutChampRefusingTransport implements HttpClientInterface
{
    public function send(Request $request): Response
    {
        throw new \RuntimeException(
            'The payment provider was called from the test environment. '
            . 'Substitute a transport at the adapter seam, or override PaymentAdapter in the container. '
            . 'Refusing to send so a missing stub cannot place a real order.',
        );
    }
}
