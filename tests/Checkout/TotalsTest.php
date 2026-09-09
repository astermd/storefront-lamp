<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Checkout;

use AsterMD\Storefront\Checkout\Promotion;
use AsterMD\Storefront\Checkout\Totals;
use PHPUnit\Framework\TestCase;

final class TotalsTest extends TestCase
{
    public function testWithNoPromotionTheTotalIsTheSubtotal(): void
    {
        $totals = Totals::of(12000, null);

        self::assertSame(12000, $totals->subtotalCents);
        self::assertSame(0, $totals->discountCents);
        self::assertSame(12000, $totals->totalCents);
    }

    public function testADiscountIsSubtracted(): void
    {
        $totals = Totals::of(12000, new Promotion('SAVE10', 1200));

        self::assertSame(1200, $totals->discountCents);
        self::assertSame(10800, $totals->totalCents);
    }

    public function testADiscountLargerThanTheSubtotalIsClampedRatherThanGoingNegative(): void
    {
        // [13.12]: the clamp exists because a provider is free to calculate a
        // discount against a cart that has since shrunk, and a negative total
        // is a refund nobody authorised.
        $totals = Totals::of(5000, new Promotion('HUGE', 9999));

        self::assertSame(5000, $totals->discountCents, 'the discount is clamped, not just the total');
        self::assertSame(0, $totals->totalCents);
    }

    public function testANegativeDiscountIsTreatedAsZero(): void
    {
        $totals = Totals::of(5000, new Promotion('WEIRD', -500));

        self::assertSame(0, $totals->discountCents);
        self::assertSame(5000, $totals->totalCents);
    }

    public function testAnEmptyCartTotalsZero(): void
    {
        $totals = Totals::of(0, new Promotion('SAVE10', 1200));

        self::assertSame(0, $totals->discountCents);
        self::assertSame(0, $totals->totalCents);
    }

    public function testCentsConvertToDollarsExactlyAtTheBoundary(): void
    {
        // The EMR documents order_value as a float and accepts integer cents
        // verbatim without complaint, so nothing downstream will ever tell us
        // this conversion was skipped. This test is the only thing that will.
        self::assertSame(120.0, Totals::dollars(12000));
        self::assertSame(108.55, Totals::dollars(10855));
        self::assertSame(0.0, Totals::dollars(0));
        self::assertSame(0.05, Totals::dollars(5));
    }

    public function testTheDecimalStringFormIsAlwaysTwoPlaces(): void
    {
        // The provider takes prices as decimal strings; "120" and "120.0" are
        // both accepted but neither matches what a reconciliation report
        // expects to see beside a charge of 120.00.
        self::assertSame('120.00', Totals::decimalString(12000));
        self::assertSame('108.55', Totals::decimalString(10855));
        self::assertSame('0.00', Totals::decimalString(0));
    }

    public function testAPromotionRoundTripsThroughJourneyState(): void
    {
        $promotion = new Promotion('SAVE10', 1200);

        self::assertEquals($promotion, Promotion::fromArray($promotion->toArray()));
    }

    public function testAMalformedStoredPromotionIsDiscardedRatherThanTrusted(): void
    {
        self::assertNull(Promotion::fromArray([]));
        self::assertNull(Promotion::fromArray(['code' => 'X']));
        self::assertNull(Promotion::fromArray(['code' => '', 'discount_cents' => 100]));
        self::assertNull(Promotion::fromArray(['code' => 'X', 'discount_cents' => 'lots']));
    }
}
