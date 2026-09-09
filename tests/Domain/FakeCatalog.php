<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Domain;

use AsterMD\Storefront\Domain\ProductCatalog;

/**
 * A catalog built from a literal array, for the pure-domain tests that need
 * products without a config cascade behind them.
 *
 * It lives in its own file rather than beside its first consumer so that
 * PSR-4 can autoload it: declared inside another test file, it resolved only
 * when that file happened to be loaded first, which made any test using it
 * pass in a full run and fail on its own.
 */
final class FakeCatalog implements ProductCatalog
{
    /** @param array<string, array<string, mixed>> $products */
    public function __construct(private readonly array $products)
    {
    }

    public function product(string $slug): ?array
    {
        return $this->products[$slug] ?? null;
    }
}
