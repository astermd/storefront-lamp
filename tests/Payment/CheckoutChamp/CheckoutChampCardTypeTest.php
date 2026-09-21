<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Payment\CheckoutChamp;

use AsterMD\Storefront\Payment\CardBrand;
use AsterMD\Storefront\Payment\CheckoutChamp\CheckoutChampCardType;
use PHPUnit\Framework\TestCase;

final class CheckoutChampCardTypeTest extends TestCase
{
    public function testTheProvidersOwnSpellingOfEachBrandIsRead(): void
    {
        self::assertSame(CardBrand::Visa, CheckoutChampCardType::brandFor('VISA'));
        self::assertSame(CardBrand::Mastercard, CheckoutChampCardType::brandFor('MASTERCARD'));
        self::assertSame(CardBrand::Amex, CheckoutChampCardType::brandFor('AMEX'));
        self::assertSame(CardBrand::Discover, CheckoutChampCardType::brandFor('DISCOVER'));
        self::assertSame(CardBrand::DinersClub, CheckoutChampCardType::brandFor('DINERS'));
        self::assertSame(CardBrand::Jcb, CheckoutChampCardType::brandFor('JCB'));
    }

    public function testCaseAndSurroundingSpaceDoNotChangeTheBrand(): void
    {
        self::assertSame(CardBrand::Visa, CheckoutChampCardType::brandFor(' visa '));
    }

    public function testTheProvidersSandboxValueNamesNoBrand(): void
    {
        // The sandbox answers `TESTCARD` beside a zeroed bin and last four.
        // It is a real recorded value and it is not a scheme.
        self::assertNull(CheckoutChampCardType::brandFor('TESTCARD'));
    }

    public function testAMissingOrNonStringValueIsNull(): void
    {
        self::assertNull(CheckoutChampCardType::brandFor(null));
        self::assertNull(CheckoutChampCardType::brandFor(''));
        self::assertNull(CheckoutChampCardType::brandFor(7));
    }
}
