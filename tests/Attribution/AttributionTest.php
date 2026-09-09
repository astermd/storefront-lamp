<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Attribution;

use AsterMD\Storefront\Attribution\Attribution;
use PHPUnit\Framework\TestCase;

final class AttributionTest extends TestCase
{
    public function testEncryptedValuesWinPerKeyAndPlainValuesFillTheGaps(): void
    {
        $attribution = Attribution::capture(
            ['aff_id' => 'plain-affiliate', 'utm_source' => 'plain-source'],
            ['affiliate_id' => 'encrypted-affiliate', 'utm_medium' => 'cpc'],
            null,
            null,
        );

        self::assertSame('encrypted-affiliate', $attribution->get('affiliate_id'));
        self::assertSame('plain-source', $attribution->get('utm_source'));
        self::assertSame('cpc', $attribution->get('utm_medium'));
    }

    public function testPrecedenceHoldsWhenTheTwoSidesSpellTheSameParameterDifferently(): void
    {
        // Plain side sends 'aff_id', encrypted side sends 'affiliate_id': the
        // precedence decision happens after normalisation, so encrypted wins.
        $attribution = Attribution::capture(['aff_id' => 'plain'], ['affiliate_id' => 'encrypted'], null, null);

        self::assertSame('encrypted', $attribution->get('affiliate_id'));
    }

    public function testFirstTouchNeverOverwritesAnEarlierCaptureIncludingAnEmptyOne(): void
    {
        $existing = Attribution::capture([], [], '', null);
        self::assertTrue($existing->isEmpty());

        $later = Attribution::capture(['aff_id' => '4412'], [], 'https://blog.example/', null);

        self::assertSame($existing, Attribution::firstTouch($existing, $later));
        self::assertSame($later, Attribution::firstTouch(null, $later));
    }

    public function testRoundTripsThroughItsStoredArrayForm(): void
    {
        $attribution = Attribution::capture(
            ['aff_id' => '4412', 'sub2' => 'creativeB'],
            ['utm_source' => 'facebook'],
            'https://l.facebook.com/',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148',
        );

        $restored = Attribution::fromArray($attribution->toArray());

        self::assertSame($attribution->params, $restored->params);
        self::assertSame($attribution->referrer, $restored->referrer);
        self::assertSame($attribution->derived, $restored->derived);
        self::assertSame('affiliate', $restored->derived['source_category']);
        self::assertSame('mobile', $restored->derived['device_type']);
    }

    public function testEmrSessionPayloadCarriesReferrerUtmTripleAndFirstFiveSubIdentifiers(): void
    {
        $attribution = Attribution::capture([
            'utm_source' => 'partner',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'spring',
            'utm_term' => 'dropped-from-payload',
            'sub1' => 'one', 'sub2' => 'two', 'sub3' => 'three', 'sub4' => 'four', 'sub5' => 'five', 'sub6' => 'six',
        ], [], 'https://partner.example/', null);

        self::assertSame([
            'referrer' => 'https://partner.example/',
            'utm' => ['source' => 'partner', 'medium' => 'cpc', 'campaign' => 'spring'],
            'custom_params' => ['c1' => 'one', 'c2' => 'two', 'c3' => 'three', 'c4' => 'four', 'c5' => 'five'],
        ], $attribution->emrSessionPayload());
    }

    public function testEmrSessionPayloadOmitsEmptySectionsAndNeverSendsChannelOrGeo(): void
    {
        $payload = Attribution::capture([], [], null, null)->emrSessionPayload();

        self::assertSame([], $payload);
        self::assertArrayNotHasKey('channel_id', $payload);
        self::assertArrayNotHasKey('geo', $payload);
    }

    public function testDerivationSeesPlainParametersAndEncryptedWinsPerKey(): void
    {
        // The common case: an affiliate link with no encrypted payload at all.
        // Derivation must see the plain affiliate_id and categorize correctly.
        $attribution = Attribution::capture(['aff_id' => '4412'], [], null, null);

        self::assertSame('affiliate', $attribution->derived['source_category']);
        self::assertSame('4412', $attribution->derived['source_detail']);

        // When both plain and encrypted have affiliate_id with different values,
        // encrypted still wins the key in stored params, and that wins value drives derivation.
        $both = Attribution::capture(['aff_id' => 'plain'], ['affiliate_id' => 'encrypted'], null, null);
        self::assertSame('encrypted', $both->derived['source_detail']);
    }
}
