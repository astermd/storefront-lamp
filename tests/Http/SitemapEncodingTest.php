<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Tests\Support\ConfigVariant;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * What `sitemap.xml` does with a slug that is not valid UTF-8.
 *
 * A slug is EMR data that arrives through a re-sync, so nothing in this
 * repository decides what bytes it holds. The failure this pins down is the
 * quiet kind: the document stays **well-formed** either way, so the parser
 * every other sitemap case runs through raises nothing, while what is served
 * is a `<loc>` a crawler must reject — and a sitemap with one invalid entry
 * is a sitemap a crawler may discard whole, taking the valid entries with it.
 *
 * Kept apart from the rest of the sitemap cases because it is about the
 * document's encoding rather than about which paths belong in it.
 */
final class SitemapEncodingTest extends TestCase
{
    use ConfigVariant;
    use TempDatabase;

    private const string BASE = 'https://storefront.example';

    private const string SITEMAP_NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /** A lone `0xE9` — `é` in Latin-1, which is not a UTF-8 sequence at all. */
    private const string INVALID_SLUG = "caf\xE9-blend";

    private const string VALID_SLUG = 'nad-500mg';

    public function testASlugThatIsNotUtf8AdvertisesNoEmptyLocation(): void
    {
        foreach ($this->locations() as $location) {
            self::assertNotSame('', $location, 'an empty <loc> is not a valid sitemap entry');
        }
    }

    /**
     * The entry is dropped rather than patched up. A URL built from bytes a
     * crawler cannot read names no page, so advertising a repaired version of
     * it points the crawl at a 404; saying nothing about the product leaves it
     * to be found the way every unadvertised page is, through a link.
     */
    public function testTheUnreadableEntryIsDroppedRatherThanRepaired(): void
    {
        $locations = $this->locations();

        foreach ($locations as $location) {
            self::assertTrue(
                mb_check_encoding($location, 'UTF-8'),
                'an advertised URL is not valid UTF-8: ' . rawurlencode($location),
            );
            self::assertStringNotContainsString("\u{FFFD}", $location, 'a substituted character names no page');
        }
    }

    /** One bad slug must not cost the rest of the catalog its entries. */
    public function testTheRestOfTheCatalogIsStillAdvertised(): void
    {
        $locations = $this->locations();

        self::assertContains(self::BASE . '/products/' . self::VALID_SLUG . '/', $locations);
        self::assertContains(self::BASE . '/', $locations);
    }

    /**
     * Every `<loc>` in the served document, read through an XML parser.
     *
     * @return list<string>
     */
    private function locations(): array
    {
        $app = AppFactory::create(dirname(__DIR__, 2), [
            Config::class => $this->catalogConfig(),
            \PDO::class => $this->tempPdo(),
        ]);

        $body = (string) $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/sitemap.xml'),
        )->getBody();

        $xml = simplexml_load_string($body);
        self::assertNotFalse($xml, 'sitemap.xml is not well-formed XML');

        $locations = [];
        foreach ($xml->children(self::SITEMAP_NS)->url as $url) {
            $locations[] = (string) $url->loc;
        }

        return $locations;
    }

    /** An indexable deployment whose catalog holds one readable slug and one that is not. */
    private function catalogConfig(): Config
    {
        $root = dirname(__DIR__, 2);

        /** @var array<string, mixed> $app */
        $app = (array) require $root . '/config/app.php';
        $app['url'] = self::BASE;
        /** @var array<string, mixed> $seo */
        $seo = $app['seo'];
        $app['seo'] = ['discourage_indexing' => false] + $seo;

        return $this->configWith([
            'app' => $app,
            'products.generated' => [
                'channel' => ['id' => 'test-channel', 'name' => 'Test', 'currency' => 'USD'],
                'products' => [
                    self::VALID_SLUG => [
                        'slug' => self::VALID_SLUG,
                        'name' => 'NAD+ 500mg',
                        'kind' => 'otc',
                        'price_cents' => 1000,
                    ],
                    self::INVALID_SLUG => [
                        'slug' => self::INVALID_SLUG,
                        'name' => 'Cafe blend',
                        'kind' => 'otc',
                        'price_cents' => 1000,
                    ],
                ],
            ],
            'products.overrides' => ['products' => []],
        ]);
    }
}
