<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Payment;

use AsterMD\Storefront\Payment\AdapterRegistry;
use AsterMD\Storefront\Payment\NullPaymentAdapter;
use AsterMD\Storefront\Payment\PaymentAdapter;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use PHPUnit\Framework\TestCase;

final class AdapterRegistryTest extends TestCase
{
    public function testAnAdapterIsNotBuiltUntilItIsAskedFor(): void
    {
        $built = 0;
        $registry = new AdapterRegistry((new CapturedLog())->log);
        $registry->register('vrio', function () use (&$built): PaymentAdapter {
            ++$built;

            return new NullPaymentAdapter();
        });

        self::assertSame(0, $built, 'registering must not construct');

        $registry->for('vrio');
        $registry->for('vrio');

        self::assertSame(1, $built, 'and resolving twice must construct once');
    }

    public function testAnUnknownCategoryDegradesToTheNullAdapterAndIsLogged(): void
    {
        $log = new CapturedLog();
        $registry = new AdapterRegistry($log->log);

        $adapter = $registry->for('checkoutchamp');

        self::assertInstanceOf(NullPaymentAdapter::class, $adapter);
        self::assertSame('payment.adapter_unavailable', $log->lastWarning()['event'] ?? null);
    }

    public function testAnUnconfiguredCategoryDegradesRatherThanThrowing(): void
    {
        $registry = new AdapterRegistry((new CapturedLog())->log);

        self::assertInstanceOf(NullPaymentAdapter::class, $registry->for(null));
        self::assertInstanceOf(NullPaymentAdapter::class, $registry->for('   '));
    }

    public function testAnAdapterThatThrowsWhileBuildingDoesNotTakeTheRequestWithIt(): void
    {
        // A misconfigured provider must not break a page that only browses.
        $log = new CapturedLog();
        $registry = new AdapterRegistry($log->log);
        $registry->register('vrio', static fn (): PaymentAdapter => throw new \RuntimeException('bad key'));

        self::assertInstanceOf(NullPaymentAdapter::class, $registry->for('vrio'));
        self::assertSame('payment.adapter_build_failed', $log->lastError()['event'] ?? null);
    }

    public function testCategoryLookupIsCaseAndWhitespaceInsensitive(): void
    {
        $registry = new AdapterRegistry((new CapturedLog())->log);
        $registry->register('Vrio', static fn (): PaymentAdapter => new NullPaymentAdapter());

        self::assertTrue($registry->has(' VRIO '));
    }
}
