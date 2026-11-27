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
 *
 * The total travels in integer cents like every other amount here; turning it
 * into the decimal dollars the EMR stores is the implementation's job, at the
 * protocol boundary. It is the storefront's own cart total, sent with every
 * mirror so the EMR's copy of the cart never carries a total from an earlier
 * version of its items.
 */
interface CartGateway
{
    /** @param list<array{product_id: string, name: string, qty: int}> $items */
    public function create(string $session, array $items, int $totalCents): CartMirrorResult;

    /** @param list<array{product_id: string, name: string, qty: int}> $items */
    public function update(string $session, array $items, int $totalCents): CartMirrorResult;
}
