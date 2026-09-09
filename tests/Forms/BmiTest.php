<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Forms;

use AsterMD\Storefront\Forms\Bmi;
use PHPUnit\Framework\TestCase;

final class BmiTest extends TestCase
{
    public function testClassifiesTheUnitSystemByKeyword(): void
    {
        self::assertSame(Bmi::METRIC, Bmi::unitSystem('Metric (kg/cm)'));
        self::assertSame(Bmi::METRIC, Bmi::unitSystem('kg'));
        self::assertSame(Bmi::METRIC, Bmi::unitSystem('CM'));
        self::assertSame(Bmi::IMPERIAL, Bmi::unitSystem('Imperial (lbs/inches)'));
        self::assertSame(Bmi::IMPERIAL, Bmi::unitSystem('lb'));
        self::assertSame(Bmi::IMPERIAL, Bmi::unitSystem('inches'));
    }

    public function testDefaultsToImperialWhenAbsentOrUnrecognised(): void
    {
        self::assertSame(Bmi::IMPERIAL, Bmi::unitSystem(null));
        self::assertSame(Bmi::IMPERIAL, Bmi::unitSystem(''));
        self::assertSame(Bmi::IMPERIAL, Bmi::unitSystem('stones and cubits'));
    }

    public function testCalculatesMetricToOneDecimalPlace(): void
    {
        // 180 cm, 80 kg -> 80 / 1.8^2 = 24.691...
        self::assertSame(24.7, Bmi::score(Bmi::METRIC, 180.0, 80.0));
    }

    public function testCalculatesImperialToOneDecimalPlace(): void
    {
        // 70 in, 200 lb -> 200 / 70^2 * 703 = 28.697...
        self::assertSame(28.7, Bmi::score(Bmi::IMPERIAL, 70.0, 200.0));
    }

    public function testAZeroOrNegativeHeightHasNoScoreRatherThanADivisionByZero(): void
    {
        self::assertNull(Bmi::score(Bmi::METRIC, 0.0, 80.0));
        self::assertNull(Bmi::score(Bmi::IMPERIAL, -1.0, 200.0));
    }

    public function testCategoryBands(): void
    {
        self::assertSame('Underweight', Bmi::category(18.4));
        self::assertSame('Normal', Bmi::category(18.5));
        self::assertSame('Normal', Bmi::category(24.9));
        self::assertSame('Overweight', Bmi::category(25.0));
        self::assertSame('Overweight', Bmi::category(29.9));
        self::assertSame('Obese', Bmi::category(30.0));
    }
}
