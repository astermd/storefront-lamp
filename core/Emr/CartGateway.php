<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Emr;

/**
 * The storefront's view of the EMR's cart resource: replace the remote cart
 * for a session, and say which of the three outcomes happened.
 *
 * An interface for the same reason {@see SessionGateway} is one — a
 * deployment with EMR analytics switched off binds a null implementation and
 * every caller above stays identical.
 */
interface CartGateway
{
    /** @param list<array{product_id: string, name: string, qty: int}> $items */
    public function create(string $session, array $items): CartMirrorResult;

    /** @param list<array{product_id: string, name: string, qty: int}> $items */
    public function update(string $session, array $items): CartMirrorResult;
}
