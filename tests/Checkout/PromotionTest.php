<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Checkout;

use AsterMD\Storefront\Checkout\Promotion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The promotion as journey state stores it: written by one request and read
 * back by the next, so what survives the round trip is what the summary shows.
 */
final class PromotionTest extends TestCase
{
    public function testHowTheDiscountFallsAcrossTheLinesSurvivesBeingStored(): void
    {
        $stored = (new Promotion('SPRING', 3000, 'cpn-1', ['tirzepatide' => 2000, 'b12' => 1000]))->toArray();

        $promotion = Promotion::fromArray($stored);

        self::assertNotNull($promotion);
        self::assertSame(2000, $promotion->discountOn('tirzepatide'));
        self::assertSame(1000, $promotion->discountOn('b12'));
        self::assertSame(0, $promotion->discountOn('sharps'), 'a line the code did not discount');
    }

    public function testAPromotionWithOnlyATotalIsStoredAsItAlwaysWas(): void
    {
        self::assertSame(['code' => 'SPRING', 'discount_cents' => 3000], (new Promotion('SPRING', 3000))->toArray());
    }

    /** @return iterable<string, array{mixed}> */
    public static function storedBreakdownsThatCannotBeTrusted(): iterable
    {
        yield 'parts that do not add up to the total' => [['tirzepatide' => 2000]];
        yield 'a negative part' => [['tirzepatide' => 4000, 'b12' => -1000]];
        yield 'a part that is not cents' => [['tirzepatide' => '3000']];
        yield 'not a breakdown at all' => ['3000'];
    }

    /**
     * Journey state outlives a deploy, so a row can hold anything. The
     * breakdown is display only, and the total alone is the safe reading.
     */
    #[DataProvider('storedBreakdownsThatCannotBeTrusted')]
    public function testAStoredBreakdownThatIsNotOneOfTheTotalIsDroppedAndTheCodeKept(mixed $lines): void
    {
        $promotion = Promotion::fromArray(['code' => 'SPRING', 'discount_cents' => 3000, 'line_discounts' => $lines]);

        self::assertNotNull($promotion);
        self::assertSame(3000, $promotion->discountCents);
        self::assertSame([], $promotion->lineDiscounts);
    }
}
