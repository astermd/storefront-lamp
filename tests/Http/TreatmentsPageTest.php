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
    private function app(): App
    {
        return AppFactory::create(dirname(__DIR__, 2), [CatalogProvider::class => SampleCatalog::provider()]);
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
