<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Catalog\CatalogProvider;
use AsterMD\Storefront\Tests\Support\SampleCatalog;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ProductPageTest extends TestCase
{
    /**
     * `ProductDetailController` and `ProductListController` depend on the
     * concrete, final `CatalogProvider`, so the sample catalog is injected
     * as a real instance rather than the `ProductCatalog`-interface
     * `FakeCatalog` other tests use — see {@see SampleCatalog::provider()}.
     */
    private function app(): App
    {
        return AppFactory::create(dirname(__DIR__, 2), [CatalogProvider::class => SampleCatalog::provider()]);
    }

    public function testKnownProductRendersFromCatalog(): void
    {
        $app = $this->app();
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/products/semaglutide/'));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Semaglutide', $body);
        self::assertStringContainsString('GLP-1 Receptor Agonist', $body);
        // The displayed price is the first variant's ('1 Month' at 5000 cents),
        // not the product-level price_cents (4600) — variants are the plans now.
        self::assertStringContainsString('$50.00', $body);
        self::assertStringContainsString('3 Months', $body);
        self::assertStringNotContainsString('Dosage', $body);
    }

    public function testSingleVariantProductHasNoTreatmentPlanSection(): void
    {
        $app = $this->app();
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/products/finasteride/'));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('Treatment Plan', $body);
    }

    public function testFooterLinksToProductPage(): void
    {
        $app = $this->app();
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/products/finasteride/'));
        $body = (string) $response->getBody();

        self::assertStringContainsString('href="/products/finasteride/"', $body);
    }

    public function testUnknownSlugIsNotFound(): void
    {
        $app = $this->app();
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/products/nope/'));

        self::assertSame(404, $response->getStatusCode());
    }

    public function testMixedCasePathRedirectsToCanonicalSlug(): void
    {
        $app = $this->app();
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/Products/Semaglutide'));

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/products/semaglutide/', $response->getHeaderLine('Location'));
    }
}
