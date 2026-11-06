<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Domain;

use AsterMD\Storefront\Domain\VariantPlans;
use PHPUnit\Framework\TestCase;

final class VariantPlansTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function plan(string $id, int $firstPaymentCents): array
    {
        return ['id' => $id, 'name' => 'Plan ' . $id, 'first_payment_cents' => $firstPaymentCents, 'settlement' => 'capture'];
    }

    /** @return array<string, mixed> a variant as `theme:sync` writes one sold on two plans */
    private static function variant(): array
    {
        return [
            'id' => 'v-1mg',
            'price_cents' => 10000,
            'plan' => self::plan('monthly', 10000),
            'plans' => [self::plan('monthly', 10000), self::plan('quarterly', 27000)],
        ];
    }

    public function testAVariantSoldOnPlansOffersEveryOneDefaultFirst(): void
    {
        self::assertSame(['monthly', 'quarterly'], array_column(VariantPlans::of(self::variant()), 'id'));
    }

    public function testAVariantSoldOneOffOffersNone(): void
    {
        self::assertSame([], VariantPlans::of(['id' => 'v-1mg', 'price_cents' => 10000]));
        self::assertSame([], VariantPlans::of(null));
    }

    public function testAnOverrideThatSellsTheVariantOneOffIsObeyedThoughThePlansRemain(): void
    {
        $variant = ['plan' => null] + self::variant();

        self::assertNotSame([], $variant['plans'], 'precondition: the generated plans are still there');
        self::assertSame([], VariantPlans::of($variant));
        self::assertNull(VariantPlans::chosen($variant, 'quarterly'));
    }

    public function testACatalogSyncedBeforePlansWereListedIsOnItsOnePlan(): void
    {
        $variant = self::variant();
        unset($variant['plans']);

        self::assertSame(['monthly'], array_column(VariantPlans::of($variant), 'id'));
    }

    public function testAnOverrideThatNamesAnotherDefaultPutsItFirst(): void
    {
        $variant = ['plan' => self::plan('quarterly', 27000)] + self::variant();

        self::assertSame(['quarterly', 'monthly'], array_column(VariantPlans::of($variant), 'id'));
    }

    public function testTheChosenPlanIsTheOneNamedElseTheDefault(): void
    {
        self::assertSame('quarterly', VariantPlans::chosen(self::variant(), 'quarterly')['id'] ?? null);
        self::assertSame('monthly', VariantPlans::chosen(self::variant(), null)['id'] ?? null);
        self::assertSame('monthly', VariantPlans::chosen(self::variant(), 'retired-in-a-resync')['id'] ?? null);
    }

    public function testOnlyAPlanTheVariantIsSoldOnIsOffered(): void
    {
        self::assertTrue(VariantPlans::offers(self::variant(), 'quarterly'));
        self::assertFalse(VariantPlans::offers(self::variant(), 'another-variants-plan'));
    }

    public function testALinesVariantIsTheOneChosenElseTheFirst(): void
    {
        $product = ['variants' => [['id' => 'v-1mg'], ['id' => 'v-2mg']]];

        self::assertSame('v-2mg', VariantPlans::variant($product, 'v-2mg')['id'] ?? null);
        self::assertSame('v-1mg', VariantPlans::variant($product, null)['id'] ?? null);
        self::assertSame('v-1mg', VariantPlans::variant($product, 'gone')['id'] ?? null);
        self::assertNull(VariantPlans::variant(['variants' => []], 'v-1mg'));
        self::assertNull(VariantPlans::variant(null, 'v-1mg'));
    }
}
