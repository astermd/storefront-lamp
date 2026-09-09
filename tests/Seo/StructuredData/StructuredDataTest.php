<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Seo\StructuredData;

use AsterMD\Storefront\Catalog\CatalogProvider;
use AsterMD\Storefront\Seo\StructuredData\Product;
use AsterMD\Storefront\Seo\StructuredData\StructuredData;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Tests\Support\ConfigVariant;
use AsterMD\Storefront\Tests\Support\SampleCatalog;
use PHPUnit\Framework\TestCase;

/**
 * `[24.8]`-`[24.11]`. The catalog is the sample seed, because its semaglutide
 * is the one product whose product-level `price_cents` (4600) differs from
 * the variant a visitor is actually quoted (5000) — the divergence `[24.9]`
 * is about, and the same one `HomePageTest` pins for the grid.
 */
final class StructuredDataTest extends TestCase
{
    use ConfigVariant;

    public function testTheOfferPriceIsTheResolvedVariantsNotTheProductLevelOne(): void
    {
        $graph = $this->graphFor('/products/semaglutide/', ['rx' => true]);
        $offer = $this->node($graph, 'Product')['offers'];

        // The product-level price_cents is 4600 and the plan actually sold is
        // 5000. Publishing 46.00 would advertise a price the checkout refuses.
        self::assertSame('50.00', $offer['price']);
        self::assertSame('USD', $offer['priceCurrency']);
        self::assertStringNotContainsString('46.00', json_encode($graph, JSON_THROW_ON_ERROR));
    }

    public function testAVariantWithNoProviderMappingIsPublishedAsOutOfStock(): void
    {
        // Genuine availability (`[24.9]`): every sample variant is unmapped,
        // so no checkout could place an order for one.
        $offer = $this->node($this->graphFor('/products/semaglutide/', ['rx' => true]), 'Product')['offers'];

        self::assertSame(Product::OUT_OF_STOCK, $offer['availability']);
    }

    public function testAMappedVariantIsPublishedAsInStock(): void
    {
        $offer = $this->node($this->graphFor('/products/metabolic-support/'), 'Product')['offers'];

        self::assertSame(Product::IN_STOCK, $offer['availability']);
        self::assertSame('50.00', $offer['price']);
    }

    public function testAPrescriptionProductPublishesNothingByDefault(): void
    {
        // `[24.10]`: claims and availability constraints vary by jurisdiction,
        // so the conservative option is the default.
        $graph = $this->graphFor('/products/semaglutide/');

        self::assertNull($this->find($graph, 'Product'));
        self::assertStringNotContainsString('Semaglutide', json_encode($graph, JSON_THROW_ON_ERROR));
    }

    public function testAPrescriptionProductPublishesItsOfferOnceTheDeploymentAllowsIt(): void
    {
        $graph = $this->graphFor('/products/semaglutide/', ['rx' => true]);

        self::assertSame('Semaglutide', $this->node($graph, 'Product')['name']);
    }

    public function testAnOmittedPrescriptionProductTakesItsBreadcrumbTrailWithIt(): void
    {
        // The last crumb is the product's own name and URL, so emitting the
        // trail would restate exactly what the omission withheld.
        self::assertNull($this->find($this->graphFor('/products/semaglutide/'), 'BreadcrumbList'));
        self::assertNotNull($this->find($this->graphFor('/products/semaglutide/', ['rx' => true]), 'BreadcrumbList'));
    }

    public function testANonPrescriptionProductIsUnaffectedByThePrescriptionSwitch(): void
    {
        self::assertSame('Metabolic Support', $this->node($this->graphFor('/products/metabolic-support/'), 'Product')['name']);
    }

    public function testAnEmptyCatalogDescriptionFallsThroughToTheConfiguredDefault(): void
    {
        // Every product this EMR syncs carries `description: ""`, so a chain
        // that stopped at the product's own value would publish an empty key.
        $node = $this->node($this->graphFor('/products/blank-description/'), 'Product');

        self::assertArrayHasKey('description', $node);
        self::assertNotSame('', trim($node['description']));
    }

    public function testTheBreadcrumbTrailRunsFromTheRootToTheProduct(): void
    {
        $crumbs = $this->node($this->graphFor('/products/metabolic-support/'), 'BreadcrumbList')['itemListElement'];

        self::assertSame(['Home', 'Treatments', 'Metabolic Support'], array_column($crumbs, 'name'));
        self::assertSame([1, 2, 3], array_column($crumbs, 'position'));
        self::assertSame('http://localhost:8080/products/metabolic-support/', $crumbs[2]['item']);
    }

    public function testTheHomePagePublishesNoBreadcrumbTrail(): void
    {
        // A one-crumb trail is a breadcrumb list with nothing to navigate.
        self::assertNull($this->find($this->graphFor('/'), 'BreadcrumbList'));
    }

    public function testTheSiteNodesNameTheOrganisationAsPublisher(): void
    {
        $graph = $this->graphFor('/');

        self::assertSame('http://localhost:8080/#organisation', $this->node($graph, 'Organization')['@id']);
        self::assertSame(
            ['@id' => 'http://localhost:8080/#organisation'],
            $this->node($graph, 'WebSite')['publisher'],
        );
    }

    /**
     * Both halves name the template explicitly rather than leaning on the
     * shipped value, because the shipped value is `null` — this storefront has
     * no search endpoint, so `config/app.php` deliberately publishes no action.
     * A case that read the default would prove only what the default happens
     * to be today, and would silently stop testing the positive branch the day
     * a deployment turned it on.
     */
    public function testTheSearchActionIsDroppedWhenTheDeploymentNamesNoSearchEndpoint(): void
    {
        $configured = $this->configFor(['search_url_template' => '/treatments/?q={search_term_string}']);
        $withAction = $this->node($this->nodes($configured, '/'), 'WebSite');

        self::assertSame('required name=search_term_string', $withAction['potentialAction']['query-input']);
        self::assertSame(
            'http://localhost:8080/treatments/?q={search_term_string}',
            $withAction['potentialAction']['target']['urlTemplate'],
        );

        $config = $this->configFor(['search_url_template' => null]);
        $suppressed = $this->node($this->nodes($config, '/'), 'WebSite');

        self::assertArrayNotHasKey('potentialAction', $suppressed);
    }

    /** The shipped configuration publishes no search action, and that is the point of it. */
    public function testTheShippedConfigurationPublishesNoSearchAction(): void
    {
        self::assertArrayNotHasKey('potentialAction', $this->node($this->graphFor('/'), 'WebSite'));
    }

    public function testNothingIsPublishedWhenEveryEmitterIsSwitchedOff(): void
    {
        $config = $this->configFor([
            'structured_data' => ['organisation' => false, 'website' => false, 'product' => false, 'breadcrumbs' => false, 'rx' => false],
        ]);

        self::assertSame([], $this->nodes($config, '/products/metabolic-support/'));
        self::assertNull($this->structuredData($config)->json('/products/metabolic-support/'));
    }

    public function testAnUnconfiguredDeploymentPublishesNothing(): void
    {
        // Absent means off: the toggles are how a client says yes.
        $config = $this->configWith(['app' => ['url' => 'http://localhost:8080', 'seo' => []], 'products.generated' => $this->catalog()]);

        self::assertSame([], $this->nodes($config, '/'));
    }

    public function testStructuredDataIsStillPublishedWhileIndexingIsDiscouraged(): void
    {
        // `[24.7]`'s master switch governs what a crawler may index, not what
        // the page says about itself — and it is on throughout the suite, so
        // gating one on the other would make every case below vacuous.
        $config = $this->configFor(['discourage_indexing' => true]);

        self::assertNotSame([], $this->nodes($config, '/'));
    }

    public function testTheEncodedDocumentCannotCloseTheScriptElementItSitsIn(): void
    {
        $products = $this->catalog();
        $products['products']['metabolic-support']['name'] = 'Metabolic </script><script>alert(1)</script>';

        $config = $this->configWith([
            'app' => $this->appConfig([]),
            'products.generated' => $products,
        ]);

        $json = (string) $this->structuredData($config)->json('/products/metabolic-support/');

        self::assertStringNotContainsString('</script>', $json);
        self::assertStringContainsString('\u003C', $json);
    }

    public function testMoneyIsConvertedFromIntegerCentsWithoutFloatRounding(): void
    {
        self::assertSame('0.00', Product::decimal(0));
        self::assertSame('0.07', Product::decimal(7));
        self::assertSame('1.05', Product::decimal(105));
        self::assertSame('12345678901.23', Product::decimal(1234567890123));
    }

    // --- `[24.11]` -------------------------------------------------------

    public function testTheShippedDeploymentPublishesNothingMalformed(): void
    {
        $config = Config::load(dirname(__DIR__, 3) . '/config', $_ENV);

        self::assertSame([], StructuredData::fromConfig($config, new CatalogProvider($config))->problems());
    }

    public function testACatalogChannelWithNoCurrencyIsADeployTimeError(): void
    {
        $catalog = $this->catalog();
        unset($catalog['channel']['currency']);

        $config = $this->configWith(['app' => $this->appConfig([]), 'products.generated' => $catalog]);
        $problems = $this->structuredData($config)->problems();

        self::assertNotSame([], $problems);
        self::assertStringContainsString('names no currency', implode("\n", $problems));
    }

    public function testAnUnsetApplicationUrlIsADeployTimeError(): void
    {
        $config = $this->configWith([
            'app' => ['url' => ''] + $this->appConfig([]),
            'products.generated' => $this->catalog(),
        ]);

        self::assertStringContainsString('is not an absolute URL', implode("\n", $this->structuredData($config)->problems()));
    }

    public function testAListedProductPricedAtNothingIsADeployTimeError(): void
    {
        $catalog = $this->catalog();
        $catalog['products']['metabolic-support']['variants'][0]['price_cents'] = 0;

        $config = $this->configWith(['app' => $this->appConfig([]), 'products.generated' => $catalog]);

        self::assertStringContainsString('published as free', implode("\n", $this->structuredData($config)->problems()));
    }

    // --- fixtures --------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function graphFor(string $path, array $structuredData = []): array
    {
        return $this->nodes($this->configFor($structuredData === [] ? [] : ['structured_data' => $structuredData]), $path);
    }

    /** @return list<array<string, mixed>> */
    private function nodes(Config $config, string $path): array
    {
        return $this->structuredData($config)->nodesFor($path);
    }

    private function structuredData(Config $config): StructuredData
    {
        return StructuredData::fromConfig($config, new CatalogProvider($config));
    }

    /** @param array<string, mixed> $seo */
    private function configFor(array $seo): Config
    {
        return $this->configWith(['app' => $this->appConfig($seo), 'products.generated' => $this->catalog()]);
    }

    /**
     * The shipped `app.php` with `seo` keys replaced, so a case states the one
     * setting it varies rather than restating a deployment.
     *
     * @param array<string, mixed> $seo
     *
     * @return array<string, mixed>
     */
    private function appConfig(array $seo): array
    {
        /** @var array<string, mixed> $shipped */
        $shipped = (array) Config::load(dirname(__DIR__, 3) . '/config', $_ENV)->get('app');
        $shipped['url'] = 'http://localhost:8080';
        /** @var array<string, mixed> $shippedSeo */
        $shippedSeo = (array) $shipped['seo'];

        foreach ($seo as $key => $value) {
            $shippedSeo[$key] = is_array($value) && is_array($shippedSeo[$key] ?? null)
                ? [...$shippedSeo[$key], ...$value]
                : $value;
        }

        $shipped['seo'] = $shippedSeo;

        return $shipped;
    }

    /**
     * The sample seed plus two products it has no equivalent of: an `otc` one,
     * so the prescription switch can be shown not to govern it, and one with
     * the empty description every real synced product carries.
     *
     * @return array<string, mixed>
     */
    private function catalog(): array
    {
        $products = SampleCatalog::products();

        $otc = $products['semaglutide'];
        $otc['slug'] = 'metabolic-support';
        $otc['name'] = 'Metabolic Support';
        $otc['kind'] = 'otc';
        $otc['variants'][0]['provider'] = ['offer_id' => '337', 'product_id' => '3411'];
        $products['metabolic-support'] = $otc;

        $blank = $otc;
        $blank['slug'] = 'blank-description';
        $blank['name'] = 'Blank Description';
        $blank['description'] = '';
        $products['blank-description'] = $blank;

        return [
            'channel' => ['id' => 'channel-123', 'name' => 'Demo Store', 'currency' => 'USD'],
            'products' => $products,
        ];
    }

    /**
     * @param list<array<string, mixed>> $graph
     *
     * @return array<string, mixed>
     */
    private function node(array $graph, string $type): array
    {
        $node = $this->find($graph, $type);
        self::assertNotNull($node, sprintf('no %s node in the graph', $type));

        return $node;
    }

    /**
     * @param list<array<string, mixed>> $graph
     *
     * @return array<string, mixed>|null
     */
    private function find(array $graph, string $type): ?array
    {
        foreach ($graph as $node) {
            if (($node['@type'] ?? null) === $type) {
                return $node;
            }
        }

        return null;
    }
}
