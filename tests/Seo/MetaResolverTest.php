<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Seo;

use AsterMD\Storefront\Seo\MetaResolver;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Tests\Support\ConfigVariant;
use AsterMD\Storefront\Tests\Support\ShippedCatalog;
use PHPUnit\Framework\TestCase;

/**
 * The `[24.4]` resolution chain, pinned against the shipped configuration and
 * the shipped catalog rather than against a fixture.
 *
 * A fixture would give every product a description and every case in here
 * would pass without the chain existing at all. The deployment this theme
 * ships against has the opposite shape: **every product synced from the EMR
 * carries an empty description**, so the fallback is not a nicety here, it is
 * the only thing standing between a product page and
 * `<meta name="description" content="">`.
 */
final class MetaResolverTest extends TestCase
{
    use ConfigVariant;

    private const string DEFAULT_TITLE = 'Default Title';

    private const string DEFAULT_DESCRIPTION = 'What this storefront is, said once.';

    public function testAPageWithNoTitleOfItsOwnGetsTheConfiguredDefaultVerbatim(): void
    {
        $resolver = $this->resolver();

        // Not "Default Title — AsterMD": templating the default renders the
        // site name twice on the one page that has nothing else to say.
        self::assertSame(self::DEFAULT_TITLE, $resolver->title());
        self::assertSame(self::DEFAULT_TITLE, $resolver->title(null));
        self::assertSame(self::DEFAULT_TITLE, $resolver->title('   '));
    }

    public function testAPageWithATitleOfItsOwnGetsItThroughTheOneTemplate(): void
    {
        self::assertSame('Treatments — AsterMD', $this->resolver()->title('Treatments'));
    }

    /**
     * A template is written by hand, so it can carry a per cent sign that
     * was never meant as a placeholder — `%s | 50% off` is a plausible thing
     * for a marketing team to type. Every page that supplies a title reaches
     * this method from the layout, so reading the template as a format string
     * turns one stray character into a site-wide 500.
     */
    public function testAPerCentSignInTheTemplateIsCopyRatherThanAPlaceholder(): void
    {
        $resolver = $this->resolver(['title_template' => '%s | 50% off']);

        self::assertSame('Treatments | 50% off', $resolver->title('Treatments'));
    }

    /**
     * The same reading, for the other shape a hand-written template takes:
     * a second placeholder has no second argument to consume.
     */
    public function testASecondPlaceholderTakesTheSameTitleRatherThanFailing(): void
    {
        self::assertSame(
            'Treatments — Treatments',
            $this->resolver(['title_template' => '%s — %s'])->title('Treatments'),
        );
    }

    /**
     * `app.url` is what makes a canonical link, a sitemap `<loc>` and the
     * `Sitemap:` line absolute, and all three must be. Nothing at request
     * time can stand in for it — deriving the host from the request would
     * make the canonical URL depend on who asked, which is the one thing a
     * canonical URL exists to settle — so an unset value is a deployment
     * fault, reported where a deployment check can see it.
     */
    public function testASiteUrlThatNamesNoHostIsReported(): void
    {
        foreach (['', '   ', '/', 'example.test'] as $url) {
            $problems = (new MetaResolver($this->configWith(['app' => ['url' => $url, 'seo' => []]])))->problems();

            self::assertCount(1, $problems, var_export($url, true));
            self::assertStringContainsString('app.url', $problems[0]);
        }
    }

    public function testAConfiguredSiteUrlIsNoProblem(): void
    {
        self::assertSame([], $this->resolver()->problems());
    }

    public function testEveryProductInTheShippedCatalogFallsThroughToTheDefaultDescription(): void
    {
        $resolver = $this->resolver();

        /** @var array{products: array<string, array<string, mixed>>} $catalog */
        $catalog = ShippedCatalog::catalog();
        self::assertNotSame([], $catalog['products']);

        foreach ($catalog['products'] as $slug => $product) {
            // The premise, stated rather than assumed: if a re-sync ever
            // brings real copy with it, this case has to be re-argued instead
            // of quietly passing for a different reason.
            self::assertSame('', (string) ($product['description'] ?? ''), "product {$slug} description");
            self::assertSame(
                self::DEFAULT_DESCRIPTION,
                $resolver->description((string) ($product['description'] ?? '')),
                "product {$slug} meta description",
            );
        }
    }

    public function testAnOverrideBeatsTheProductAndTheProductBeatsTheDefault(): void
    {
        $resolver = $this->resolver();

        self::assertSame('Page copy', $resolver->description('Page copy', 'Product copy'));
        self::assertSame('Product copy', $resolver->description(null, 'Product copy'));
        self::assertSame('Product copy', $resolver->description('', 'Product copy'));
        self::assertSame(self::DEFAULT_DESCRIPTION, $resolver->description('', ''));
    }

    public function testTheCanonicalUrlIsAbsoluteAndCanonicalised(): void
    {
        // `[23.6]` alongside `[23.7]`: a link built by hand in the
        // non-canonical form still names the canonical one.
        self::assertSame('https://example.test/treatments/', $this->resolver()->canonical('/Treatments'));
        self::assertSame('https://example.test/', $this->resolver()->canonical('/'));
    }

    public function testASocialCardWithNoConfiguredImageCarriesNoneAtAll(): void
    {
        $card = $this->resolver()->social('/treatments/', 'Treatments — AsterMD', self::DEFAULT_DESCRIPTION);

        self::assertNull($card['image'], 'a null image must be omitted, never rendered empty');
        self::assertSame('Treatments — AsterMD', $card['title']);
        self::assertSame('https://example.test/treatments/', $card['url']);
        self::assertSame('website', $card['type']);
    }

    public function testAConfiguredRelativeSocialImageIsMadeAbsolute(): void
    {
        $card = $this->resolver(['social_image' => '/assets/img/card.png'])
            ->social('/', 'AsterMD', 'x');

        self::assertSame('https://example.test/assets/img/card.png', $card['image']);
    }

    /** @param array<string, mixed> $seo */
    private function resolver(array $seo = []): MetaResolver
    {
        return new MetaResolver($this->seoConfig($seo));
    }

    /** @param array<string, mixed> $seo */
    private function seoConfig(array $seo = []): Config
    {
        return $this->configWith([
            'app' => [
                'name' => 'AsterMD',
                'url' => 'https://example.test',
                'seo' => $seo + [
                    'site_name' => 'AsterMD',
                    'title_template' => '%s — AsterMD',
                    'default_title' => self::DEFAULT_TITLE,
                    'default_description' => self::DEFAULT_DESCRIPTION,
                    'social_image' => null,
                ],
            ],
        ]);
    }
}
