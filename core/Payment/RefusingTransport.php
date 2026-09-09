<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment;

use AsterMD\VrioClient\Http\HttpClientInterface;
use AsterMD\VrioClient\Http\Request;
use AsterMD\VrioClient\Http\Response;

/**
 * A payment transport that refuses to send anything, bound under the test
 * environment so a forgotten stub fails loudly instead of quietly reaching the
 * live provider.
 *
 * Every other external system this application talks to is fenced off in the
 * test environment by a Null implementation of its port, which is what makes
 * it structurally impossible for a test to reach the EMR. **Payment had no
 * such fence.** The adapter is chosen from synced channel data rather than
 * from the environment (`[14.3]`), so under the test suite the container
 * handed out a real provider adapter wired to a real HTTP client — and a test
 * that called `place()` without substituting a transport would have posted an
 * order to the provider and charged a card.
 *
 * Nothing did, but nothing stopped it either, and that is the shape of absence
 * this codebase has been bitten by before: not a wrong branch, but a guard
 * nobody wrote.
 *
 * The fence goes here, at the transport, rather than on the port itself,
 * because the adapter's other work is pure and worth exercising:
 * `capabilities()` is derived from configuration, and payload assembly is the
 * part payment tests most need to assert against. Refusing at the wire keeps
 * all of that testable while making the one irreversible operation impossible.
 * A test that wants a provider response substitutes its own transport at this
 * same seam, exactly as before — this only decides what happens when it
 * forgets.
 */
final class RefusingTransport implements HttpClientInterface
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
