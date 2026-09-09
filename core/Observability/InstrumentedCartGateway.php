<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Observability;

use AsterMD\Storefront\Emr\CartGateway;
use AsterMD\Storefront\Emr\CartMirrorResult;

/**
 * `[20.9]` over the cart-mirror boundary.
 *
 * The three mirror outcomes are reported apart rather than as
 * success-or-failure, because `NotFound` is the recoverable one the caller
 * acts on and `Failed` is the one it swallows (`[7.12]`) — collapsing them
 * would hide a remote cart resource that had started answering 404 to
 * everything.
 *
 * The line count goes into the log; the lines do not. What someone put in
 * their cart is the beginning of a clinical record here.
 */
final class InstrumentedCartGateway implements CartGateway
{
    public function __construct(
        private readonly CartGateway $inner,
        private readonly BoundaryTimer $timer,
    ) {
    }

    public function create(string $session, array $items): CartMirrorResult
    {
        return $this->mirror('create', $session, $items, fn (): CartMirrorResult => $this->inner->create($session, $items));
    }

    public function update(string $session, array $items): CartMirrorResult
    {
        return $this->mirror('update', $session, $items, fn (): CartMirrorResult => $this->inner->update($session, $items));
    }

    /**
     * @param list<array{product_id: string, name: string, qty: int}> $items
     * @param \Closure(): CartMirrorResult                            $call
     */
    private function mirror(string $operation, string $session, array $items, \Closure $call): CartMirrorResult
    {
        return $this->timer->measure(
            Boundary::CartMirror,
            $call,
            static fn (CartMirrorResult $result): array => ['outcome' => match ($result) {
                CartMirrorResult::Ok => 'ok',
                CartMirrorResult::NotFound => 'not_found',
                CartMirrorResult::Failed => 'failed',
            }],
            ['operation' => $operation, 'session' => $session, 'items' => count($items)],
        );
    }
}
