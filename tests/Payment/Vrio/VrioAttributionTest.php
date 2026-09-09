<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Payment\Vrio;

use AsterMD\Storefront\Payment\Vrio\VrioAttribution;
use PHPUnit\Framework\TestCase;

final class VrioAttributionTest extends TestCase
{
    public function testTheAffiliateIdentifierTakesTheFirstSlot(): void
    {
        [$slots, $dropped] = VrioAttribution::map(['affiliate_id' => 'aff-9']);

        self::assertSame('aff-9', $slots['tracking1']);
        self::assertSame([], $dropped);
    }

    public function testSubIdentifiersOneToThirteenTakeSlotsTwoToFourteen(): void
    {
        [$slots] = VrioAttribution::map(['c1' => 'a', 'c7' => 'g', 'c13' => 'm']);

        self::assertSame('a', $slots['tracking2']);
        self::assertSame('g', $slots['tracking8']);
        self::assertSame('m', $slots['tracking14']);
    }

    public function testTheFiveUtmParametersTakeSlotsFifteenToNineteenInOrder(): void
    {
        // Confirmed by live data on this account: real orders carry
        // tracking15 "fb", tracking16 "email", tracking17 "summar-sale".
        [$slots] = VrioAttribution::map([
            'utm_source' => 'fb',
            'utm_medium' => 'email',
            'utm_campaign' => 'summer-sale',
            'utm_term' => 'glp1',
            'utm_content' => 'hero',
        ]);

        self::assertSame('fb', $slots['tracking15']);
        self::assertSame('email', $slots['tracking16']);
        self::assertSame('summer-sale', $slots['tracking17']);
        self::assertSame('glp1', $slots['tracking18']);
        self::assertSame('hero', $slots['tracking19']);
    }

    public function testSubIdentifierFourteenTakesTheLastSlot(): void
    {
        [$slots] = VrioAttribution::map(['c14' => 'n']);

        self::assertSame('n', $slots['tracking20']);
    }

    public function testSubIdentifiersFifteenToNineteenAreDroppedAndReported(): void
    {
        // [14.7]: silent loss of attribution is not acceptable.
        [$slots, $dropped] = VrioAttribution::map(['c15' => 'o', 'c19' => 's', 'affiliate_id' => 'aff-9']);

        self::assertArrayNotHasKey('tracking21', $slots);
        self::assertSame(['c15', 'c19'], $dropped);
    }

    public function testEmptyValuesAreOmittedRatherThanSentBlank(): void
    {
        // [14.6]: an empty slot and an absent slot mean different things to a
        // reporting tool.
        [$slots, $dropped] = VrioAttribution::map(['affiliate_id' => '', 'utm_source' => 'fb']);

        self::assertArrayNotHasKey('tracking1', $slots);
        self::assertSame('fb', $slots['tracking15']);
        self::assertSame([], $dropped, 'an empty value is not a dropped value');
    }

    public function testAnUnknownCanonicalKeyIsDroppedAndReported(): void
    {
        [$slots, $dropped] = VrioAttribution::map(['gclid' => 'abc123']);

        self::assertSame([], $slots);
        self::assertSame(['gclid'], $dropped);
    }

    public function testTheSlotMapNeverCollides(): void
    {
        // Every canonical key that has a slot must have a distinct one; two
        // keys sharing tracking17 would silently overwrite one another.
        [$slots] = VrioAttribution::map([
            'affiliate_id' => '1',
            'c1' => '2', 'c2' => '3', 'c3' => '4', 'c4' => '5', 'c5' => '6', 'c6' => '7', 'c7' => '8',
            'c8' => '9', 'c9' => '10', 'c10' => '11', 'c11' => '12', 'c12' => '13', 'c13' => '14',
            'c14' => '15',
            'utm_source' => '16', 'utm_medium' => '17', 'utm_campaign' => '18', 'utm_term' => '19', 'utm_content' => '20',
        ]);

        self::assertCount(20, $slots);
        self::assertCount(20, array_unique(array_keys($slots)));
    }
}
