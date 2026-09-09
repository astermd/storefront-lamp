<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Payment;

use AsterMD\Storefront\Payment\NullPaymentAdapter;
use AsterMD\Storefront\Payment\OrderSearch;
use AsterMD\Storefront\Payment\OrderSearchResult;
use AsterMD\Storefront\Payment\ProviderOrder;
use PHPUnit\Framework\TestCase;

final class OrderSearchTest extends TestCase
{
    public function testAWindowIsNormalisedToUtcSoTwoClocksCannotDescribeDifferentSpans(): void
    {
        $search = OrderSearch::between(
            new \DateTimeImmutable('2026-08-23 20:00:00', new \DateTimeZone('Asia/Kolkata')),
            new \DateTimeImmutable('2026-08-24 20:00:00', new \DateTimeZone('Asia/Kolkata')),
        );

        self::assertSame('2026-08-23 14:30:00', $search->from->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-24 14:30:00', $search->to->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $search->from->getTimezone()->getName());
    }

    public function testAnInvertedWindowIsRefusedRatherThanSweptAsEmpty(): void
    {
        // A window whose end precedes its start returns nothing at the
        // provider, and nothing is indistinguishable from "no orphans". The
        // sweep must not be able to report all-clear because of a bad call.
        $this->expectException(\InvalidArgumentException::class);

        OrderSearch::between(
            new \DateTimeImmutable('2026-08-24 00:00:00', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-08-23 00:00:00', new \DateTimeZone('UTC')),
        );
    }

    public function testALookbackEndsAtTheGraceCutoffRatherThanAtNow(): void
    {
        $search = OrderSearch::lookback(
            seconds: 172800,
            graceSeconds: 900,
            now: new \DateTimeImmutable('2026-08-25 12:00:00', new \DateTimeZone('UTC')),
        );

        self::assertSame('2026-08-23 12:00:00', $search->from->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-25 11:45:00', $search->to->format('Y-m-d H:i:s'));
    }

    public function testALookbackShorterThanItsOwnGraceIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OrderSearch::lookback(seconds: 600, graceSeconds: 900);
    }

    public function testALimitIsClampedRatherThanPassedThroughToTheProvider(): void
    {
        $window = [
            new \DateTimeImmutable('2026-08-23 00:00:00', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-08-24 00:00:00', new \DateTimeZone('UTC')),
        ];

        self::assertSame(1, OrderSearch::between($window[0], $window[1], 0)->limit);
        self::assertSame(OrderSearch::MAX_LIMIT, OrderSearch::between($window[0], $window[1], 99999)->limit);
        self::assertSame(50, OrderSearch::between($window[0], $window[1], 50)->limit);
    }

    public function testAResultKnowsWhenTheProviderHadMoreOrdersThanTheLimitReturned(): void
    {
        $order = new ProviderOrder('34788', '2026-08-24 12:39:15', true, true, '3', 0);

        self::assertTrue(OrderSearchResult::of([$order], reportedTotal: 143)->truncated());
        self::assertFalse(OrderSearchResult::of([$order], reportedTotal: 1)->truncated());
    }

    public function testAFailedSearchIsNotAnEmptySearch(): void
    {
        // The whole point of the distinction: an empty window means "no
        // orphans", a failed call means "nothing was measured". Collapsing
        // them reports all-clear during a provider outage.
        $empty = OrderSearchResult::of([], reportedTotal: 0);
        $failed = OrderSearchResult::failed('transport_error');

        self::assertTrue($empty->ok);
        self::assertNull($empty->failureReason);
        self::assertFalse($failed->ok);
        self::assertSame('transport_error', $failed->failureReason);
        self::assertSame([], $failed->orders);
    }

    public function testTheNullAdapterDeclaresNoOrderSearchAndAnswersWithoutThrowing(): void
    {
        // A deployment with no provider configured must not crash the sweep.
        $adapter = new NullPaymentAdapter();

        self::assertFalse($adapter->capabilities()->supportsOrderSearch);

        $result = $adapter->searchOrders(OrderSearch::lookback(3600));

        self::assertFalse($result->ok);
        self::assertSame(OrderSearchResult::UNSUPPORTED, $result->failureReason);
        self::assertSame([], $result->orders);
    }
}
