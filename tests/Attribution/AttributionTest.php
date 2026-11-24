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

    public function testThePlainAffidIsTheAffiliateLinkAndTheSessionPayloadCarriesItTopLevel(): void
    {
        $attribution = Attribution::capture(
            ['affid' => '6a906a42a002ad6ea5037d8e', 'sub1' => '123', 'sub2' => '34'],
            [],
            null,
            null,
        );

        self::assertSame('6a906a42a002ad6ea5037d8e', $attribution->affiliateLinkId);
        self::assertSame([
            'custom_params' => ['c1' => '123', 'c2' => '34'],
            'affiliate_link_id' => '6a906a42a002ad6ea5037d8e',
        ], $attribution->emrSessionPayload());
    }

    public function testTheRawAmdTokenBeatsAPlainAffidAndIsForwardedStillEncrypted(): void
    {
        $attribution = Attribution::capture(
            ['affid' => '6a906a42a002ad6ea5037d8e'],
            [],
            null,
            null,
            null,
            'q2EYh8sCg_P4t5-raw-token',
        );

        self::assertSame('q2EYh8sCg_P4t5-raw-token', $attribution->affiliateLinkId);
        self::assertSame('q2EYh8sCg_P4t5-raw-token', $attribution->emrSessionPayload()['affiliate_link_id']);
    }

    public function testTheTokenNeverEntersTheNormalisedParameters(): void
    {
        $attribution = Attribution::capture([], [], null, null, null, 'q2EYh8sCg_P4t5-raw-token');

        self::assertSame([], $attribution->params, '[5.4]: the wrapper stays out of the tracking parameters');
        self::assertFalse($attribution->isEmpty(), 'a captured link is a capture');
    }

    public function testOtherAffiliateAliasesAreNotALinkId(): void
    {
        $attribution = Attribution::capture(['aff_id' => '4412', 'affiliate_id' => '99'], [], null, null);

        self::assertSame('99', $attribution->get('affiliate_id'), 'the alias was captured');
        self::assertNull($attribution->affiliateLinkId);
        self::assertArrayNotHasKey('affiliate_link_id', $attribution->emrSessionPayload());
    }

    public function testABlankAffidOrTokenIsNoLink(): void
    {
        self::assertNull(Attribution::capture(['affid' => '  '], [], null, null, null, '')->affiliateLinkId);
    }

    public function testTheAffiliateLinkSurvivesTheStoredArrayFormAndAnOlderRowReadsAsNone(): void
    {
        $attribution = Attribution::capture(['affid' => '6a906a42a002ad6ea5037d8e'], [], null, null);

        self::assertSame('6a906a42a002ad6ea5037d8e', Attribution::fromArray($attribution->toArray())->affiliateLinkId);
        self::assertNull(
            Attribution::fromArray(['params' => [], 'referrer' => null, 'derived' => []])->affiliateLinkId,
            'a row stored before the field existed has no link',
        );
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
