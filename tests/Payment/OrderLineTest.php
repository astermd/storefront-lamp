<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Payment;

use AsterMD\Storefront\Payment\OrderLine;
use PHPUnit\Framework\TestCase;

final class OrderLineTest extends TestCase
{
    public function testALineCarriesTheCatalogKindItWasBuiltWith(): void
    {
        $rx = new OrderLine('semaglutide', 'Semaglutide', '337', '3414', 12000, 1, kind: 'rx');

        self::assertSame('rx', $rx->kind);
        self::assertSame('otc', (new OrderLine('mask', 'Sleeping Mask', null, null, 0, 1))->kind);
    }
}
