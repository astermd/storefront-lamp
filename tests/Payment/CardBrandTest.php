<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Payment;

use AsterMD\Storefront\Payment\CardBrand;
use PHPUnit\Framework\TestCase;

final class CardBrandTest extends TestCase
{
    public function testTheSixBrandsTheApiNamesAreTheOnlyValues(): void
    {
        self::assertSame(
            ['amex', 'visa', 'mastercard', 'discover', 'diners_club', 'jcb'],
            array_map(static fn (CardBrand $b): string => $b->value, CardBrand::cases()),
        );
    }

    public function testEachBrandIsReadFromItsOwnPrefix(): void
    {
        self::assertSame(CardBrand::Visa, CardBrand::fromNumber('4111111111111111'));
        self::assertSame(CardBrand::Mastercard, CardBrand::fromNumber('5500000000000004'));
        self::assertSame(CardBrand::Mastercard, CardBrand::fromNumber('2223000048400011'));
        self::assertSame(CardBrand::Amex, CardBrand::fromNumber('378282246310005'));
        self::assertSame(CardBrand::Discover, CardBrand::fromNumber('6011111111111117'));
        self::assertSame(CardBrand::DinersClub, CardBrand::fromNumber('36227206271667'));
        self::assertSame(CardBrand::Jcb, CardBrand::fromNumber('3530111333300000'));
    }

    public function testTheRangeEdgesFallOnTheSideTheSchemeAssignsThem(): void
    {
        // Mastercard's second range is 2221-2720 inclusive. One digit outside
        // it on either side belongs to nobody this table knows.
        self::assertNull(CardBrand::fromNumber('2220000000000000'));
        self::assertSame(CardBrand::Mastercard, CardBrand::fromNumber('2221000000000000'));
        self::assertSame(CardBrand::Mastercard, CardBrand::fromNumber('2720000000000000'));
        self::assertNull(CardBrand::fromNumber('2721000000000000'));

        // Discover's 644-649 block.
        self::assertNull(CardBrand::fromNumber('6439000000000000'));
        self::assertSame(CardBrand::Discover, CardBrand::fromNumber('6440000000000000'));
        self::assertSame(CardBrand::Discover, CardBrand::fromNumber('6499000000000000'));

        // JCB's 3528-3589 block, which sits inside Diners Club's 35-prefix
        // neighbourhood and must not be swallowed by it.
        self::assertNull(CardBrand::fromNumber('3527000000000000'));
        self::assertSame(CardBrand::Jcb, CardBrand::fromNumber('3528000000000000'));
        self::assertSame(CardBrand::Jcb, CardBrand::fromNumber('3589000000000000'));
        self::assertNull(CardBrand::fromNumber('3590000000000000'));
    }

    public function testSpacesAndDashesAreHowACardIsPrintedAndDoNotChangeItsBrand(): void
    {
        self::assertSame(CardBrand::Amex, CardBrand::fromNumber('3782 822463 10005'));
        self::assertSame(CardBrand::Visa, CardBrand::fromNumber('4111-1111-1111-1111'));
    }

    public function testAnUnrecognisedPrefixIsNullRatherThanAGuess(): void
    {
        // A provider's own sandbox number, which belongs to no scheme. Naming
        // a brand for it would put a fabrication on the wire.
        self::assertNull(CardBrand::fromNumber('1444444444444440'));
        self::assertNull(CardBrand::fromNumber(''));
        self::assertNull(CardBrand::fromNumber('not a card'));
    }

    public function testTheUnionPayCoBrandRangeIsDeliberatelyAbsent(): void
    {
        // 622126-622925 is a real Discover assignment and is still not in this
        // table. Adding it would change what Vrio receives for those cards as
        // a side effect, so it belongs to its own change with its own evidence.
        self::assertNull(CardBrand::fromNumber('6221260000000000'));
    }
}
