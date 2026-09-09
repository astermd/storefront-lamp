<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Domain;

use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Domain\CartLine;
use PHPUnit\Framework\TestCase;

final class CartTest extends TestCase
{
    public function testPutReplacesBySlugRatherThanAppendingADuplicate(): void
    {
        $cart = new Cart();
        $cart->put(new CartLine('sema', 'Semaglutide', 'rx', 'emr-sema', null, quantity: 1, unitPriceCents: 5000));
        $cart->put(new CartLine('sema', 'Semaglutide', 'rx', 'emr-sema', null, quantity: 1, unitPriceCents: 13800));

        self::assertCount(1, $cart->lines());
        self::assertSame(13800, $cart->line('sema')?->unitPriceCents);
    }

    public function testForgetRemovesALineAndEveryDescendantRecursively(): void
    {
        $cart = new Cart();
        $cart->put(new CartLine('parent', 'Parent', 'rx', 'emr-p', null));
        $cart->put(new CartLine('child', 'Child', 'lab', 'emr-c', 'parent'));
        $cart->put(new CartLine('grandchild', 'Grandchild', 'free-addon', 'emr-g', 'child'));

        $cart->forget('parent');

        self::assertTrue($cart->isEmpty());
    }

    public function testItemCountSumsQuantitiesOfTopLevelLinesOnly(): void
    {
        $cart = new Cart();
        $cart->put(new CartLine('sema', 'Semaglutide', 'rx', 'emr-sema', null, quantity: 2));
        $cart->put(new CartLine('syringe', 'Syringe Kit', 'free-addon', 'emr-syr', 'sema', quantity: 5));

        self::assertSame(2, $cart->itemCount());
    }

    public function testSubtotalCentsSumsAcrossAllLinesIncludingChildren(): void
    {
        $cart = new Cart();
        $cart->put(new CartLine('sema', 'Semaglutide', 'rx', 'emr-sema', null, quantity: 2, unitPriceCents: 100));
        $cart->put(new CartLine('cmp', 'Metabolic Panel', 'lab', 'emr-cmp', 'sema', quantity: 1, unitPriceCents: 50));

        self::assertSame(250, $cart->subtotalCents());
    }

    public function testToArrayFromArrayRoundTripsATwoLevelCartWithATerritory(): void
    {
        $cart = new Cart();
        $cart->put(new CartLine('sema', 'Semaglutide', 'rx', 'emr-sema', null, quantity: 1, unitPriceCents: 13800, variantId: 'sema-3m'));
        $cart->put(new CartLine('cmp', 'Metabolic Panel', 'lab', 'emr-cmp', 'sema', quantity: 1, unitPriceCents: 2500));
        $cart->shippingTerritory = 'NY';

        $restored = Cart::fromArray($cart->toArray());

        self::assertSame('NY', $restored->shippingTerritory);
        self::assertCount(2, $restored->lines());
        self::assertSame('sema-3m', $restored->line('sema')?->variantId);
        self::assertSame('sema', $restored->line('cmp')?->parentSlug);
        self::assertSame(13800, $restored->line('sema')?->unitPriceCents);
    }

    public function testFromArrayWithEmptyDataYieldsAnEmptyCart(): void
    {
        $cart = Cart::fromArray([]);

        self::assertTrue($cart->isEmpty());
        self::assertNull($cart->shippingTerritory);
    }

    public function testChildrenOfReturnsOnlyTheDirectChildrenOfTheGivenSlug(): void
    {
        $cart = new Cart();
        $cart->put(new CartLine('sema', 'Semaglutide', 'rx', 'emr-sema', null));
        $cart->put(new CartLine('cmp', 'Metabolic Panel', 'lab', 'emr-cmp', 'sema'));
        $cart->put(new CartLine('syringe', 'Syringe Kit', 'free-addon', 'emr-syr', 'sema'));
        $cart->put(new CartLine('organizer', 'Pill Organizer', 'otc', 'emr-org', null));

        $children = $cart->childrenOf('sema');

        self::assertCount(2, $children);
        self::assertSame(['cmp', 'syringe'], array_map(static fn (CartLine $line): string => $line->slug, $children));
        self::assertSame([], $cart->childrenOf('nope'));
    }
}
