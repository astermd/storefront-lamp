<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Emr;

/**
 * The gateway used when EMR analytics sessions are switched off: a
 * half-configured deployment, and the test suite, which must never make an
 * outbound call.
 *
 * Both methods report `Ok` unconditionally, because a deployment with
 * analytics off must behave exactly like one whose mirror always succeeded —
 * nothing upstream should branch on whether a cart is actually being
 * mirrored, the same reasoning that keeps {@see NullSessionGateway} silent
 * about its own inertness.
 */
final class NullCartGateway implements CartGateway
{
    public function create(string $session, array $items): CartMirrorResult
    {
        return CartMirrorResult::Ok;
    }

    public function update(string $session, array $items): CartMirrorResult
    {
        return CartMirrorResult::Ok;
    }
}
