<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use AsterMD\Storefront\Emr\CartGateway;
use AsterMD\Storefront\Emr\CartMirrorResult;

/**
 * In-memory {@see CartGateway} for exercising {@see \AsterMD\Storefront\Emr\CartMirror}
 * without an EMR. The configured result is returned on every call regardless
 * of arguments, and every call is recorded so tests can assert exactly how
 * many creates versus updates happened and in what order.
 */
final class FakeCartGateway implements CartGateway
{
    /** @var list<array{0: string, 1: list<array{product_id: string, name: string, qty: int}>}> */
    public array $createCalls = [];

    /** @var list<array{0: string, 1: list<array{product_id: string, name: string, qty: int}>}> */
    public array $updateCalls = [];

    public function __construct(
        private readonly CartMirrorResult $createResult = CartMirrorResult::Ok,
        private readonly CartMirrorResult $updateResult = CartMirrorResult::Ok,
    ) {
    }

    public function create(string $session, array $items): CartMirrorResult
    {
        $this->createCalls[] = [$session, $items];

        return $this->createResult;
    }

    public function update(string $session, array $items): CartMirrorResult
    {
        $this->updateCalls[] = [$session, $items];

        return $this->updateResult;
    }
}
