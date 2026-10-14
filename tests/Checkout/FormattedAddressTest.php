<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Checkout;

use AsterMD\Storefront\Checkout\FormattedAddress;
use PHPUnit\Framework\TestCase;

final class FormattedAddressTest extends TestCase
{
    public function testTheVerifiersAddressFillsAllFourFieldsWithAFiveDigitZip(): void
    {
        self::assertSame(
            ['address_line' => '350 5th Avenue', 'city' => 'New York', 'territory' => 'NY', 'postal_code' => '10118'],
            FormattedAddress::parse('350 5th Avenue, New York, NY 10118-0110, USA'),
        );
    }

    public function testASuggestionWithNoZipFillsEverythingElse(): void
    {
        self::assertSame(
            ['address_line' => '350 5th Ave', 'city' => 'New York', 'territory' => 'NY', 'postal_code' => ''],
            FormattedAddress::parse('350 5th Ave, New York, NY, USA'),
        );
    }

    public function testAStreetThatHoldsACommaKeepsAllOfIt(): void
    {
        self::assertSame(
            'Suite 5, 123 Main St',
            FormattedAddress::parse('Suite 5, 123 Main St, Springfield, IL 62701, USA')['address_line'] ?? null,
        );
    }

    public function testAnythingOutsideTheUsOrNotInThatShapeFillsNothing(): void
    {
        self::assertNull(FormattedAddress::parse('1600 Amphitheatre Road, Mount Lonarch VIC, Australia'));
        // The state spelled out rather than as its code: not a field the form can take.
        self::assertNull(FormattedAddress::parse('350 5th Ave, Brooklyn, New York, USA'));
        self::assertNull(FormattedAddress::parse('New York, NY, USA'));
        self::assertNull(FormattedAddress::parse(''));
    }

    public function testOnlyAnAddressEndingInTheUsCountsAsOne(): void
    {
        self::assertTrue(FormattedAddress::isUnitedStates('350 5th Ave, New York, NY, USA'));
        self::assertTrue(FormattedAddress::isUnitedStates('1 Main St, Austin, TX, United States'));
        self::assertFalse(FormattedAddress::isUnitedStates('1600 Amphitheatre Parkway, Mountain View, CA 94043, Nice, Hello, Nigeria'));
    }
}
