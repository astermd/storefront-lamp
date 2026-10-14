<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Catalog\CatalogProvider;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Tests\Support\SampleCatalog;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

final class TreatmentsPageTest extends TestCase
{
    /** @see \AsterMD\Storefront\Tests\Http\ProductPageTest::app() for why the sample catalog is injected as a concrete CatalogProvider */
    /** @param array<string, array<string, mixed>>|null $products */
    private function app(?array $products = null): App
    {
        return AppFactory::create(dirname(__DIR__, 2), [CatalogProvider::class => SampleCatalog::provider($products)]);
    }

    private function page(string $path, ?array $products = null): string
    {
        $response = $this->app($products)->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    /** The sample catalog with one product made non-prescription, since the sample holds none. */
    private static function withOtc(string $slug): array
    {
        $products = SampleCatalog::products();
        $products[$slug]['kind'] = 'otc';
        $products[$slug]['teleform_id'] = null;

        return $products;
    }

    public function testEachCardCarriesWhatTheSidebarFiltersOn(): void
    {
        // The filter script compares checkbox values with these attributes, so
        // the two have to be spelled from the same source: the category name
        // and the catalog kind, untransformed.
        $body = $this->page('/treatments/', self::withOtc('tadalafil'));

        self::assertStringContainsString('name="filter-condition" value="Weight Loss"', $body);
        self::assertStringContainsString('name="filter-type" value="rx"', $body);
        self::assertStringContainsString('name="filter-type" value="otc"', $body);
        self::assertStringContainsString('data-kind="rx" data-categories="[&quot;Weight Loss&quot;]"', $body);
        self::assertStringContainsString('data-kind="otc" data-categories="[&quot;Sexual Health&quot;]"', $body);
        self::assertStringContainsString('id="treatments-empty" hidden', $body);
    }

    public function testAPrescriptionsGetStartedStartsTheAssessmentOnItsFirstPlan(): void
    {
        $body = $this->page('/treatments/');

        self::assertMatchesRegularExpression(
            '#name="slug" value="semaglutide" />\s*<input type="hidden" name="variant_id" value="semaglutide-1m" />.*?name="intent" value="assessment"#s',
            $body,
        );
    }

    public function testANonPrescriptionsGetStartedGoesStraightToCheckout(): void
    {
        // There is no questionnaire to start, so its first plan is added and
        // the buyer goes on to checkout, where the plan can still be changed.
        $body = $this->page('/treatments/', self::withOtc('tadalafil'));

        self::assertMatchesRegularExpression(
            '#name="slug" value="tadalafil" />\\s*<input type="hidden" name="variant_id" value="tadalafil-1m" />.*?name="intent" value="checkout"#s',
            $body,
        );
    }

    public function testTheHeaderTakesTreatmentsToTheHomeSectionAndTheHomeSectionLinksOnToTheListing(): void
    {
        $home = $this->page('/');

        self::assertSame(2, substr_count($home, 'href="/#treatments"'), 'desktop and mobile navigation');
        self::assertStringContainsString('<section id="treatments"', $home);
        self::assertStringContainsString('href="/treatments/"', $home);
    }

    public function testTreatmentsPageListsCatalogProducts(): void
    {
        $app = $this->app();
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/treatments/'));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Enclomiphene', $body);
        self::assertStringContainsString('Finasteride', $body);
        self::assertStringContainsString('Ramelteon', $body);
        self::assertStringContainsString('Semaglutide', $body);
        self::assertStringContainsString('Sermorelin', $body);
        self::assertStringContainsString('Tadalafil', $body);
        self::assertStringContainsString('Starting from $50.00/mo', $body);
        // Every card's "from" price is the first variant's price_cents (spec [6.2]),
        // falling back to product.price_cents only when variants are absent/empty.
        // Semaglutide's first variant is the 5000-cent '1 Month' plan, so its card
        // reads $50.00 like the rest — the product-level 4600 never surfaces here.
        self::assertStringNotContainsString('$46.00', $body);
        self::assertStringContainsString('href="/products/semaglutide/"', $body);
    }

    public function testLabProductsAreNeverListed(): void
    {
        // [6.1]: the sample `lab` product hand-added via
        // config/products.overrides.php ("Comprehensive Metabolic Panel")
        // must never surface on the treatments listing.
        $app = $this->app();
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/treatments/'));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('Comprehensive Metabolic Panel', $body);
    }

    public function testUnknownQueryParamsAreIgnored(): void
    {
        $app = $this->app();
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/treatments/?foo=bar'));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testCatalogSeedIsReachableViaDotNotation(): void
    {
        // config/products.generated.php is keyed by basename($file, '.php'), i.e. the flat
        // top-level array key 'products.generated'. Config::get() resolves this via a
        // greedy longest-prefix match against top-level keys, so ordinary dot notation
        // reaches it. This is the path every caller must use.
        //
        // Written into a throwaway directory rather than read from the real config/ —
        // this asserts Config's dot-resolution mechanism, not whatever price the
        // currently-synced catalog happens to hold.
        $dir = sys_get_temp_dir() . '/treatments-page-dot-notation-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents(
            $dir . '/products.generated.php',
            '<?php return ' . var_export(['products' => SampleCatalog::products()], true) . ';',
        );

        try {
            $config = Config::load($dir);

            self::assertSame(4600, $config->get('products.generated.products.semaglutide.price_cents'));
        } finally {
            unlink($dir . '/products.generated.php');
            rmdir($dir);
        }
    }
}
