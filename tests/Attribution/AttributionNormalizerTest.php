<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Attribution;

use AsterMD\Storefront\Attribution\AttributionNormalizer;
use AsterMD\Storefront\Attribution\CanonicalKeys;
use PHPUnit\Framework\TestCase;

final class AttributionNormalizerTest extends TestCase
{
    public function testMapsVendorAliasesOntoCanonicalKeys(): void
    {
        $normalized = AttributionNormalizer::normalize([
            'aff_id' => '4412',
            'nid' => '77',
            'gclid' => 'Cj0KEQ',
            'utm_medium' => 'cpc',
            'unknown_param' => 'ignored',
        ]);

        self::assertSame('4412', $normalized['affiliate_id']);
        self::assertSame('77', $normalized['network_id']);
        self::assertSame('Cj0KEQ', $normalized['gclid']);
        self::assertSame('cpc', $normalized['utm_medium']);
        self::assertArrayNotHasKey('unknown_param', $normalized);
    }

    public function testFirstAliasPresentWinsAndAliasMatchingIsCaseSensitive(): void
    {
        // 'affiliate_id' precedes 'aff_id' in the alias list, so it wins.
        $normalized = AttributionNormalizer::normalize(['aff_id' => 'second', 'affiliate_id' => 'first']);
        self::assertSame('first', $normalized['affiliate_id']);

        // 'AFF_ID' is not an alias vendors send; it must not be picked up.
        self::assertSame([], AttributionNormalizer::normalize(['AFF_ID' => '4412']));
    }

    public function testOneRawParameterMayFeedTwoCanonicalKeys(): void
    {
        $normalized = AttributionNormalizer::normalize(['utm_source' => 'newsletter', 'sub1' => 'creativeA']);

        self::assertSame('newsletter', $normalized['utm_source']);
        self::assertSame('newsletter', $normalized['source_id']);
        self::assertSame('creativeA', $normalized['c1']);
        self::assertSame('creativeA', $normalized['sub_affiliate_id']);
    }

    public function testProviderSlotNamesAreAcceptedInbound(): void
    {
        $normalized = AttributionNormalizer::normalize(['tracking3' => 'x', 'sourceValue2' => 'y']);

        self::assertSame('x', $normalized['c3']);
        self::assertSame('y', $normalized['c2']);
    }

    public function testEmptyBlankAndNonScalarValuesAreDropped(): void
    {
        $normalized = AttributionNormalizer::normalize([
            'aff_id' => '',
            'nid' => '   ',
            'c1' => ['array'],
            'gclid' => 'kept',
        ]);

        self::assertSame(['gclid' => 'kept'], $normalized);
    }

    public function testCustomSubIdentifiersAreSupportedThroughC19(): void
    {
        $normalized = AttributionNormalizer::normalize(['c19' => 'nineteen', 'sub14' => 'fourteen']);

        self::assertSame('nineteen', $normalized['c19']);
        self::assertSame('fourteen', $normalized['c14']);
        self::assertContains('c19', CanonicalKeys::all());
    }
}
