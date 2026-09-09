<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Attribution;

use AsterMD\Storefront\Attribution\DerivedAttribution;
use PHPUnit\Framework\TestCase;

final class DerivedAttributionTest extends TestCase
{
    public function testSourceCategoryPrefersAffiliateThenEmailThenSocialThenReferral(): void
    {
        self::assertSame(
            ['affiliate', '4412'],
            self::category(['affiliate_id' => '4412', 'utm_medium' => 'email'], 'https://news.example/'),
        );
        self::assertSame(
            ['email_campaign', 'spring-sale'],
            self::category(['utm_medium' => 'newsletter', 'utm_campaign' => 'spring-sale'], 'https://news.example/'),
        );
        self::assertSame(
            ['email_campaign', 'mailer'],
            self::category(['utm_medium' => 'email', 'utm_source' => 'mailer'], null),
        );
        self::assertSame(
            ['social_media', 'facebook'],
            self::category(['utm_source' => 'facebook'], null),
        );
        self::assertSame(
            ['referral', 'https://blog.example/post/'],
            self::category([], 'https://blog.example/post/'),
        );
        self::assertSame([null, null], self::category([], null));
        self::assertSame([null, null], self::category([], ''));
    }

    public function testDeviceTabletIsTestedBeforeMobile(): void
    {
        $ipad = 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148';
        self::assertSame('tablet', DerivedAttribution::from([], null, $ipad)['device_type']);

        $iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148';
        self::assertSame('mobile', DerivedAttribution::from([], null, $iphone)['device_type']);

        $desktop = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
        self::assertSame('desktop', DerivedAttribution::from([], null, $desktop)['device_type']);
    }

    public function testBrowserDetectionOrderSurvivesImpersonatingUserAgents(): void
    {
        $edge = 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120.0 Safari/537.36 Edg/120.0';
        self::assertSame('Edge', DerivedAttribution::from([], null, $edge)['browser']);

        $opera = 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120.0 Safari/537.36 OPR/106.0';
        self::assertSame('Opera', DerivedAttribution::from([], null, $opera)['browser']);

        $chrome = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/120.0 Safari/537.36';
        self::assertSame('Chrome', DerivedAttribution::from([], null, $chrome)['browser']);
        self::assertSame('macOS', DerivedAttribution::from([], null, $chrome)['os']);

        $safari = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/17.0 Safari/605.1.15';
        self::assertSame('Safari', DerivedAttribution::from([], null, $safari)['browser']);
    }

    public function testAnEmptyUserAgentYieldsNulls(): void
    {
        $derived = DerivedAttribution::from([], null, null);

        self::assertNull($derived['device_type']);
        self::assertNull($derived['browser']);
        self::assertNull($derived['os']);
    }

    /** @return array{0: ?string, 1: ?string} */
    private static function category(array $params, ?string $referrer): array
    {
        $derived = DerivedAttribution::from($params, $referrer, null);

        return [$derived['source_category'], $derived['source_detail']];
    }

    /**
     * A visitor moving between our own pages is not a referral source.
     *
     * The bug this pins was found in a live wire log: a session minted on an
     * internal navigation recorded `source_category: referral` with the
     * storefront's own URL as the source. Under `[5.12]`'s first-touch rule
     * that is not a cosmetic mislabel — whatever is captured first stands, so
     * one such capture pins the journey's attribution to the site itself for
     * as long as the journey lives.
     */
    public function testAReferrerFromOurOwnHostIsNotATrafficSource(): void
    {
        $derived = DerivedAttribution::from([], 'http://127.0.0.1:8123/', null, '127.0.0.1');

        self::assertNull($derived['source_category']);
        self::assertNull($derived['source_detail']);
    }

    /** Scheme, port and path differ; only the host decides. */
    public function testTheHostAloneDecidesWhetherAReferrerIsOurs(): void
    {
        $derived = DerivedAttribution::from([], 'https://Example.test/products/nad/', null, 'example.test');

        self::assertNull($derived['source_category'], 'case and port must not make a self-referral external');
    }

    /**
     * The absence that stops the guard swallowing real traffic.
     *
     * A genuine external referrer must survive, and so must one we cannot
     * parse — a malformed header is not evidence of a same-origin visit, and
     * dropping it would lose a real source.
     */
    public function testAnExternalOrUnparseableReferrerIsStillASource(): void
    {
        $external = DerivedAttribution::from([], 'https://www.google.com/', null, 'example.test');
        self::assertSame('referral', $external['source_category']);
        self::assertSame('https://www.google.com/', $external['source_detail']);

        $malformed = DerivedAttribution::from([], 'not-a-url', null, 'example.test');
        self::assertSame('referral', $malformed['source_category']);
    }

    /**
     * Nothing changes when the caller cannot say what host it is serving.
     */
    public function testWithNoSelfHostKnownEveryReferrerIsStillASource(): void
    {
        $derived = DerivedAttribution::from([], 'http://127.0.0.1:8123/', null, null);

        self::assertSame('referral', $derived['source_category']);
    }
}
