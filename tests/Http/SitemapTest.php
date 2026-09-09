<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Catalog\CatalogProvider;
use AsterMD\Storefront\Seo\RobotsPolicy;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Tests\Support\ConfigVariant;
use AsterMD\Storefront\Tests\Support\ShippedCatalog;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * `sitemap.xml` (`[24.4]`, `[24.6]`, `[24.7]`).
 *
 * The document is parsed rather than string-matched. A sitemap is consumed by
 * a machine that will reject the whole file over one unescaped ampersand, so
 * a test that greps for a substring can pass against a document no crawler
 * can read.
 *
 * The suite's own configuration discourages indexing, which empties the
 * document — so the cases that carry the weight here vary the configuration
 * through the `Config::class` container seam to reach the deployment a live
 * storefront runs in.
 */
final class SitemapTest extends TestCase
{
    use ConfigVariant;
    use TempDatabase;

    private const string BASE = 'https://storefront.example';

    private const string SITEMAP_NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /** `[24.6]`'s list, plus the two steps the funnel gained after it was written. */
    private const array FUNNEL_PREFIXES = [
        '/cart/',
        '/intake/',
        '/verify/',
        '/checkout/',
        '/upsell/',
        '/thank-you/',
        '/not-eligible/',
    ];

    public function testTheDocumentIsServedAsXml(): void
    {
        $response = $this->fetch(null);

        self::assertSame(200, $response['status']);
        self::assertSame('application/xml; charset=utf-8', $response['type']);
    }

    /**
     * `[24.7]`. The document is still served — a 404 would contradict the
     * `Sitemap:` line `robots.txt` still carries — but it advertises nothing,
     * because under the master switch no path is indexable.
     */
    public function testTheMasterSwitchEmptiesTheDocumentWithoutRemovingIt(): void
    {
        $response = $this->fetch($this->seoConfig(['discourage_indexing' => true]));

        self::assertSame(200, $response['status']);
        self::assertSame([], $this->locations($response['body']));
    }

    public function testTheDocumentIsWellFormedXmlInBothConfigurations(): void
    {
        foreach ([true, false] as $discouraged) {
            $body = $this->fetch($this->seoConfig(['discourage_indexing' => $discouraged]))['body'];
            $xml = simplexml_load_string($body);

            self::assertNotFalse($xml, 'sitemap.xml is not well-formed XML');
            self::assertSame('urlset', $xml->getName());
            self::assertSame(self::SITEMAP_NS, (string) ($xml->getDocNamespaces()[''] ?? ''));
        }
    }

    public function testTheMarketingSurfaceIsAdvertised(): void
    {
        $locations = $this->locations($this->fetch($this->indexable())['body']);

        foreach (['/', '/treatments/', '/terms/', '/privacy/', '/telehealth-consent/'] as $path) {
            self::assertContains(self::BASE . $path, $locations);
        }
    }

    /**
     * `[24.4]` — the products come from the catalog rather than from a list
     * kept in this file, so a re-sync that adds a product adds it here.
     */
    public function testEveryListedCatalogProductIsAdvertised(): void
    {
        $config = $this->indexable();
        $locations = $this->locations($this->fetch($config)['body']);
        $listed = (new CatalogProvider($config))->listedProducts();

        self::assertNotSame([], $listed, 'the catalog under test lists no products, so this asserts nothing');

        foreach ($listed as $product) {
            self::assertContains(self::BASE . '/products/' . $product['slug'] . '/', $locations);
        }
    }

    /**
     * `[6.1]`'s non-listed kinds stay out. A free add-on reaches a cart only
     * by being attached to a listed product and appears on no navigation
     * surface, so advertising its detail page would send a crawler to a page
     * nothing links to and nobody can buy from.
     */
    public function testProductsThatAreNotIndependentlySoldAreNotAdvertised(): void
    {
        $config = $this->indexable();
        $locations = $this->locations($this->fetch($config)['body']);
        $provider = new CatalogProvider($config);

        $unlisted = array_diff(
            array_column($provider->products(), 'slug'),
            array_column($provider->listedProducts(), 'slug'),
        );

        self::assertNotSame([], $unlisted, 'the catalog under test has no unlisted product, so this asserts nothing');

        foreach ($unlisted as $slug) {
            self::assertNotContains(self::BASE . '/products/' . $slug . '/', $locations);
        }
    }

    /** `[24.6]` — asserted by path, because that is the form the rule is written in. */
    public function testNoFunnelPathIsAdvertised(): void
    {
        $locations = $this->locations($this->fetch($this->indexable())['body']);

        foreach ($locations as $location) {
            foreach (self::FUNNEL_PREFIXES as $prefix) {
                self::assertStringStartsNotWith(self::BASE . $prefix, $location);
            }
        }
    }

    /**
     * `[24.6]`'s real requirement is that the two decisions cannot drift, so
     * this asks the policy rather than restating the list: whatever the
     * document advertises must be a path the policy would let be indexed.
     */
    public function testEveryAdvertisedPathIsOneThePolicyWouldIndex(): void
    {
        $config = $this->indexable();
        $policy = RobotsPolicy::fromConfig($config);

        foreach ($this->locations($this->fetch($config)['body']) as $location) {
            $path = substr($location, strlen(self::BASE));
            self::assertTrue($policy->isIndexable($path), $location . ' is advertised but the policy refuses it');
        }
    }

    /**
     * The guard for the case a rule cannot catch: a path nobody wrote a rule
     * for is indexable by default, so a funnel step added without one would
     * be refused by nothing. This walks the application's real route table,
     * so the suite breaks on the commit that adds such a route rather than
     * when a crawler finds it.
     */
    public function testNoRegisteredFunnelRouteEscapesTheIndexingPolicy(): void
    {
        $config = $this->indexable();
        $app = AppFactory::create(dirname(__DIR__, 2), [Config::class => $config, \PDO::class => $this->tempPdo()]);
        $policy = RobotsPolicy::fromConfig($config);

        $checked = 0;
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            $pattern = $route->getPattern();
            foreach (self::FUNNEL_PREFIXES as $prefix) {
                if (!str_starts_with($pattern, $prefix)) {
                    continue;
                }

                $checked++;
                self::assertFalse(
                    $policy->isIndexable($pattern),
                    $pattern . ' is a funnel route with no rule refusing it — add one to app.seo.robots_rules',
                );
            }
        }

        self::assertGreaterThan(0, $checked, 'no funnel route was found, so this asserts nothing');
    }

    /**
     * The static half of the document is a list of pages that may be
     * advertised, not a list of pages that may not. Even with every rule
     * removed the funnel stays out, so a missing rule costs a `noindex` tag
     * rather than an indexed checkout page.
     */
    public function testTheFunnelStaysOutEvenWithNoRulesAtAll(): void
    {
        $locations = $this->locations($this->fetch($this->indexable(['robots_rules' => []]))['body']);

        foreach ($locations as $location) {
            foreach (self::FUNNEL_PREFIXES as $prefix) {
                self::assertStringStartsNotWith(self::BASE . $prefix, $location);
            }
        }
    }

    /**
     * `[23.6]` — every entry is an absolute URL on the configured host and in
     * the canonical form the 301 redirect would send a visitor to, so a
     * crawler following the sitemap never gets redirected on arrival.
     */
    public function testEveryEntryIsAbsoluteAndAlreadyCanonical(): void
    {
        $locations = $this->locations($this->fetch($this->indexable())['body']);

        self::assertNotSame([], $locations);

        foreach ($locations as $location) {
            self::assertStringStartsWith(self::BASE . '/', $location);
            $path = substr($location, strlen(self::BASE));
            self::assertSame(\AsterMD\Storefront\Support\Url::canonicalizePath($path), $path);
        }
    }

    /**
     * A slug is EMR data, so it can carry anything. XML escaping is asserted
     * by round-tripping through the parser: the escaped document must parse,
     * and the value that comes back out must be the original.
     */
    public function testEntriesAreXmlEscaped(): void
    {
        $config = $this->catalogWith('tirzepatide-5mg&10mg');
        $locations = $this->locations($this->fetch($config)['body']);

        self::assertSame([self::BASE . '/products/tirzepatide-5mg&10mg/'], array_values(array_filter(
            $locations,
            static fn (string $location): bool => str_contains($location, '/products/'),
        )));
    }

    /**
     * No `lastmod`, `changefreq` or `priority`, and their absence is the
     * decision rather than an omission. Nothing in this application records
     * when a page's content last changed — a catalog re-sync rewrites every
     * product — so any timestamp emitted here would be invented, and a
     * sitemap that claims every page changed today is one a crawler learns to
     * distrust. `changefreq` and `priority` are hints the major crawlers
     * ignore outright.
     */
    public function testNoFabricatedFreshnessOrPriorityHintsAreEmitted(): void
    {
        $body = $this->fetch($this->indexable())['body'];

        foreach (['lastmod', 'changefreq', 'priority'] as $element) {
            self::assertStringNotContainsString('<' . $element . '>', $body);
        }
    }

    /**
     * `[24.7]`'s consistency requirement, asserted as the equivalence it
     * actually is: the document is empty exactly when `robots.txt` is a
     * blanket refusal. Either half on its own can be right while the pair
     * tells a crawler two different stories.
     */
    public function testTheDocumentIsEmptyExactlyWhenRobotsRefusesEverything(): void
    {
        foreach ([true, false] as $discouraged) {
            $config = $this->seoConfig(['discourage_indexing' => $discouraged]);
            $app = AppFactory::create(dirname(__DIR__, 2), [Config::class => $config, \PDO::class => $this->tempPdo()]);

            $sitemap = $this->locations((string) $app->handle(
                (new ServerRequestFactory())->createServerRequest('GET', '/sitemap.xml'),
            )->getBody());
            $robots = (string) $app->handle(
                (new ServerRequestFactory())->createServerRequest('GET', '/robots.txt'),
            )->getBody();

            $blanket = str_contains($robots, "User-agent: *\nDisallow: /\n");

            self::assertSame(
                $blanket,
                $sitemap === [],
                'robots.txt and sitemap.xml disagree about whether anything may be indexed',
            );
        }
    }

    /**
     * @return array{status: int, type: string, body: string}
     */
    private function fetch(?Config $config): array
    {
        $overrides = [\PDO::class => $this->tempPdo()];
        if ($config !== null) {
            $overrides[Config::class] = $config;
        }

        $app = AppFactory::create(dirname(__DIR__, 2), $overrides);
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/sitemap.xml'));

        return [
            'status' => $response->getStatusCode(),
            'type' => $response->getHeaderLine('Content-Type'),
            'body' => (string) $response->getBody(),
        ];
    }

    /**
     * Every `<loc>` in the document, read through an XML parser so a
     * malformed document fails here rather than silently matching nothing.
     *
     * @return list<string>
     */
    private function locations(string $body): array
    {
        $xml = simplexml_load_string($body);
        self::assertNotFalse($xml, 'sitemap.xml is not well-formed XML');

        $locations = [];
        foreach ($xml->children(self::SITEMAP_NS)->url as $url) {
            $locations[] = (string) $url->loc;
        }

        return $locations;
    }

    /**
     * The shipped configuration with indexing switched on.
     *
     * @param array<string, mixed> $seo
     */
    private function indexable(array $seo = []): Config
    {
        return $this->seoConfig($seo + ['discourage_indexing' => false]);
    }

    /** @param array<string, mixed> $seo */
    private function seoConfig(array $seo): Config
    {
        return $this->configWith([
            'app' => $this->appConfig($seo),
            // `config/products.generated.php` is gitignored, so an unsynced
            // clone has only the example. The cases here are about which
            // paths are advertised, not about which products a particular
            // channel sells, so they run against whichever catalog ships.
            'products.generated' => ShippedCatalog::catalog(),
        ]);
    }

    /** An indexable deployment whose catalog holds one listed product with the given slug. */
    private function catalogWith(string $slug): Config
    {
        return $this->configWith([
            'app' => $this->appConfig(['discourage_indexing' => false]),
            'products.generated' => [
                'channel' => ['id' => 'test-channel', 'name' => 'Test', 'currency' => 'USD'],
                'products' => [
                    $slug => ['slug' => $slug, 'name' => 'Test product', 'kind' => 'otc', 'price_cents' => 1000],
                ],
            ],
            'products.overrides' => ['products' => []],
        ]);
    }

    /**
     * @param array<string, mixed> $seo
     * @return array<string, mixed>
     */
    private function appConfig(array $seo): array
    {
        $root = dirname(__DIR__, 2);
        $env = $_ENV;

        /** @var array<string, mixed> $app */
        $app = (static function () use ($root, $env): array {
            return (array) require $root . '/config/app.php';
        })();

        $app['url'] = self::BASE;
        $app['seo'] = $seo + $app['seo'];

        return $app;
    }
}
