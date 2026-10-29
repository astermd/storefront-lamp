<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Catalog;

use AsterMD\Storefront\Catalog\SubscriptionPlans;
use PHPUnit\Framework\TestCase;

final class SubscriptionPlansTest extends TestCase
{
    private const string PLAN_ID = '6adf4668a103373c607b85a1';

    private const string PRODUCT_ID = '6a1c28245f315cee0e41c346';

    private const string VARIANT_ID = '6a1c28245f315cee0e41c344';

    /**
     * @param list<array<string, mixed>> $steps
     * @return array<string, mixed>
     */
    private static function plan(array $steps, array $overrides = []): array
    {
        return $overrides + [
            '_id' => self::PLAN_ID,
            'name' => 'tadalafil-monthly',
            'display_name' => 'Tadalafil — monthly',
            'product_id' => self::PRODUCT_ID,
            'steps' => $steps,
        ];
    }

    /** @return array<string, mixed> */
    private static function monthly(int $startDay = 0, string $recurrence = 'ongoing', int $count = 1): array
    {
        return ['start_day' => $startDay, 'recurrence' => $recurrence, 'interval_days' => 30, 'occurrence_count' => $count];
    }

    /**
     * @param list<list<array<string, mixed>>> $cycles items per cycle
     * @return array<string, mixed>
     */
    private static function shipment(string $id, array $cycles, array $schedule): array
    {
        return [
            'step_id' => $id,
            'type' => 'shipment',
            'schedule' => $schedule,
            'items_by_cycle' => array_map(static fn (array $items): array => ['items' => $items], $cycles),
        ];
    }

    /** @return array<string, mixed> */
    private static function item(int $priceCents, int $quantity = 1, ?string $variantId = self::VARIANT_ID): array
    {
        return ['product_id' => self::PRODUCT_ID, 'variant_id' => $variantId, 'price_cents' => $priceCents, 'quantity' => $quantity];
    }

    public function testAFixedFirstChargeIsTheFirstPayment(): void
    {
        $plan = self::plan([
            ['step_id' => 'c1', 'type' => 'charge', 'schedule' => self::monthly(), 'amount_source' => 'fixed', 'amount_cents' => 9900],
        ]);

        self::assertSame(['amount_cents' => 9900, 'allow_coupons' => true], SubscriptionPlans::firstPayment($plan));
    }

    public function testAChargeThatPaysForShipmentsPaysEachCycleDueBeforeItsNextCharge(): void
    {
        // Charged every 60 days for a shipment every 30: the first charge pays cycles 0 and 1.
        $plan = self::plan([
            self::shipment('s1', [[self::item(2500, 2)], [self::item(3000)]], self::monthly()),
            [
                'step_id' => 'c1',
                'type' => 'charge',
                'schedule' => ['start_day' => 0, 'recurrence' => 'ongoing', 'interval_days' => 60, 'occurrence_count' => 1],
                'amount_source' => 'from_products',
                'pays_for_step_ids' => ['s1'],
            ],
        ]);

        self::assertSame(['amount_cents' => 8000, 'allow_coupons' => true], SubscriptionPlans::firstPayment($plan));
    }

    public function testALabTestPaidForAddsItsPrice(): void
    {
        $plan = self::plan([
            ['step_id' => 'l1', 'type' => 'lab_test', 'schedule' => self::monthly(0, 'once'), 'price_cents' => 4500],
            [
                'step_id' => 'c1',
                'type' => 'charge',
                'schedule' => self::monthly(0, 'once'),
                'amount_source' => 'from_lab_tests',
                'pays_for_step_ids' => ['l1'],
            ],
        ]);

        self::assertSame(['amount_cents' => 4500, 'allow_coupons' => true], SubscriptionPlans::firstPayment($plan));
    }

    public function testAPreAuthTakesItsCouponRuleFromTheStepThatCapturesIt(): void
    {
        $plan = self::plan([
            [
                'step_id' => 'hold',
                'type' => 'charge',
                'schedule' => self::monthly(0, 'once'),
                'payment_mode' => 'pre_auth',
                'amount_source' => 'fixed',
                'amount_cents' => 12000,
                'allow_coupons' => true,
            ],
            [
                'step_id' => 'take',
                'type' => 'charge',
                'schedule' => self::monthly(0, 'once'),
                'captures_step_id' => 'hold',
                'allow_coupons' => false,
            ],
        ]);

        self::assertSame(['amount_cents' => 12000, 'allow_coupons' => false], SubscriptionPlans::firstPayment($plan));
    }

    public function testAPlanWithNoChargeDueOnDayZeroChargesNothingAtCheckout(): void
    {
        $plan = self::plan([
            ['step_id' => 'c1', 'type' => 'charge', 'schedule' => self::monthly(14), 'amount_source' => 'fixed', 'amount_cents' => 9900],
        ]);

        self::assertSame(['amount_cents' => 0, 'allow_coupons' => true], SubscriptionPlans::firstPayment($plan));
    }

    public function testAnUnreadablePriceCannotBePriced(): void
    {
        $plan = self::plan([
            self::shipment('s1', [[['product_id' => self::PRODUCT_ID, 'price_cents' => 'free', 'quantity' => 1]]], self::monthly()),
            ['step_id' => 'c1', 'type' => 'charge', 'schedule' => self::monthly(), 'amount_source' => 'from_products', 'pays_for_step_ids' => ['s1']],
        ]);

        self::assertNull(SubscriptionPlans::firstPayment($plan));
    }

    public function testTheVariantIsTheOneTheFirstShipmentCycleShipsElseTheProductItself(): void
    {
        $ships = self::plan([self::shipment('s1', [[self::item(2500)]], self::monthly())]);
        self::assertSame(['product_id' => self::PRODUCT_ID, 'variant_id' => self::VARIANT_ID], SubscriptionPlans::productRef($ships));

        $shipsNothing = self::plan([]);
        self::assertSame(['product_id' => self::PRODUCT_ID, 'variant_id' => self::PRODUCT_ID], SubscriptionPlans::productRef($shipsNothing));

        self::assertNull(SubscriptionPlans::productRef(self::plan([], ['product_id' => null])));
    }

    public function testTheChannelsSubscriptionsBecomeTheRowsTheCatalogSellsOn(): void
    {
        $priced = self::plan([
            self::shipment('s1', [[self::item(2500)]], self::monthly()),
            ['step_id' => 'c1', 'type' => 'charge', 'schedule' => self::monthly(), 'amount_source' => 'fixed', 'amount_cents' => 9900, 'allow_coupons' => false],
        ]);
        $noProduct = self::plan([], ['_id' => '6adf4668a103373c607b85a2', 'product_id' => null]);
        $unpriceable = self::plan(
            [['step_id' => 'c1', 'type' => 'charge', 'schedule' => self::monthly(), 'amount_source' => 'fixed', 'amount_cents' => -1]],
            ['_id' => '6adf4668a103373c607b85a3'],
        );

        $rows = SubscriptionPlans::fromChannelDetails(['subscriptions' => [
            ['plan_id' => self::PLAN_ID, 'plan' => $priced],
            ['plan_id' => '6adf4668a103373c607b85a2', 'plan' => $noProduct],
            ['plan_id' => '6adf4668a103373c607b85a3', 'plan' => $unpriceable],
            ['plan_id' => '6adf4668a103373c607b85a4', 'plan' => null],
        ]]);

        self::assertSame([[
            'plan_id' => self::PLAN_ID,
            'name' => 'tadalafil-monthly',
            'display_name' => 'Tadalafil — monthly',
            'product_id' => self::PRODUCT_ID,
            'variant_id' => self::VARIANT_ID,
            'first_payment_cents' => 9900,
            'allow_coupons' => false,
        ]], $rows);
    }

    public function testAChannelWithoutSubscriptionsSellsNoPlans(): void
    {
        self::assertSame([], SubscriptionPlans::fromChannelDetails([]));
    }
}
