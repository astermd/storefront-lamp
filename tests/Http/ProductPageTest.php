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

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function productPage(string $slug): string
    {
        $response = $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', '/products/' . $slug . '/'));
        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    /** @return array<string, mixed> */
    private static function rxLine(string $slug): array
    {
        return [
            'slug' => $slug, 'name' => ucfirst($slug), 'kind' => 'rx',
            'emr_product_id' => null, 'parent_slug' => null,
            'quantity' => 1, 'unit_price_cents' => 5000, 'variant_id' => $slug . '-1m',
        ];
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

    /**
     * `[8.0i]`: a second, different prescription would leave the cart with
     * nothing to assess on the storefront, so the page offers checkout alone
     * and says, softly, that there may be steps after it.
     */
    public function testTheAssessmentButtonIsHiddenWhenTheCartHoldsADifferentPrescription(): void
    {
        $_SESSION['cart'] = ['session' => null, 'territory' => null, 'lines' => [self::rxLine('ramelteon')]];

        $body = $this->productPage('semaglutide');

        self::assertStringNotContainsString('value="assessment"', $body);
        self::assertStringContainsString('value="checkout"', $body);
        self::assertStringContainsString('data-post-checkout-steps', $body);
    }

    /** The precondition the test above varies: the same page offers the assessment on an empty cart. */
    public function testTheAssessmentButtonIsOfferedWhenThePrescriptionWouldBeTheOnlyOne(): void
    {
        $empty = $this->productPage('semaglutide');

        $_SESSION['cart'] = ['session' => null, 'territory' => null, 'lines' => [self::rxLine('semaglutide')]];
        $same = $this->productPage('semaglutide');

        foreach (['an empty cart' => $empty, 'the same prescription' => $same] as $case => $body) {
            self::assertStringContainsString('value="assessment"', $body, $case);
            self::assertStringNotContainsString('data-post-checkout-steps', $body, $case);
        }
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
