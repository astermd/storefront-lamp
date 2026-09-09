<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Seo\StructuredData;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Catalog\CatalogProvider;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Tests\Support\ConfigVariant;
use AsterMD\Storefront\Tests\Support\SampleCatalog;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;

/**
 * What a request actually publishes: the `structured_data` global the request
 * pipeline produces, rendered through
 * `theme/templates/partials/structured-data.twig`, plus `[24.14]`'s
 * non-regression.
 *
 * The partial is rendered directly rather than read out of a page body so
 * that this stays a test of the emission and the partial, not of whichever
 * layout happens to include them.
 */
final class StructuredDataRenderingTest extends TestCase
{
    use ConfigVariant;

    public function testAProductPageRendersItsGraphAsLinkedData(): void
    {
        $rendered = $this->render('/products/metabolic-support/');

        self::assertStringContainsString('<script type="application/ld+json">', $rendered);
        self::assertStringContainsString('</script>', $rendered);

        $graph = json_decode($this->payload($rendered), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('https://schema.org', $graph['@context']);
        self::assertSame(
            ['Organization', 'WebSite', 'Product', 'BreadcrumbList'],
            array_column($graph['@graph'], '@type'),
        );
    }

    public function testThePublishedPriceIsTheOneTheCheckoutWouldCharge(): void
    {
        $graph = json_decode($this->payload($this->render('/products/metabolic-support/')), true, 512, JSON_THROW_ON_ERROR);
        $product = $graph['@graph'][2];

        self::assertSame('50.00', $product['offers']['price']);
        self::assertStringNotContainsString('46.00', $this->payload($this->render('/products/metabolic-support/')));
    }

    public function testNoScriptElementIsRenderedWhenThereIsNothingToPublish(): void
    {
        // An empty `application/ld+json` block is a parse error to some
        // consumers and noise to the rest, so the element is omitted rather
        // than emitted empty.
        $rendered = $this->render('/products/metabolic-support/', [
            'organisation' => false,
            'website' => false,
            'product' => false,
            'breadcrumbs' => false,
        ]);

        self::assertSame('', trim($rendered));
    }

    public function testAPrescriptionProductPageRendersOnlyTheSiteLevelNodes(): void
    {
        $graph = json_decode($this->payload($this->render('/products/semaglutide/')), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['Organization', 'WebSite'], array_column($graph['@graph'], '@type'));
    }

    // --- `[24.14]` -------------------------------------------------------

    public function testProductContentIsInTheServerRenderedHtmlRatherThanInjectedByScript(): void
    {
        // `[24.14]` is a non-regression, not a build: the architecture already
        // renders on the server, and the requirement is that it stays that
        // way. Everything inside a <script> element is removed before the
        // assertions, so a page that moved its product content into a
        // client-side payload — structured data included — would fail here.
        $app = AppFactory::create(dirname(__DIR__, 3), [CatalogProvider::class => SampleCatalog::provider()]);
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/products/semaglutide/'));

        $markup = (string) preg_replace('#<script\b[^>]*>.*?</script>#si', '', (string) $response->getBody());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Semaglutide', $markup);
        self::assertStringContainsString('GLP-1 Receptor Agonist', $markup);
        self::assertStringContainsString('Helps reduce appetite', $markup);
        // The first variant's price, which is what `[24.9]` publishes too.
        self::assertStringContainsString('$50.00', $markup);
    }

    // --- fixtures --------------------------------------------------------

    /** @param array<string, bool> $structuredData */
    private function render(string $path, array $structuredData = []): string
    {
        $config = $this->configWith([
            'app' => $this->appConfig($structuredData),
            'products.generated' => $this->catalog(),
        ]);

        $app = AppFactory::create(dirname(__DIR__, 3), [Config::class => $config]);
        $app->handle((new ServerRequestFactory())->createServerRequest('GET', $path));

        $twig = $app->getContainer()?->get(Twig::class);
        self::assertInstanceOf(Twig::class, $twig);

        return $twig->getEnvironment()->render('partials/structured-data.twig');
    }

    private function payload(string $rendered): string
    {
        self::assertSame(
            1,
            preg_match('#<script type="application/ld\+json">(.*)</script>#s', $rendered, $matches),
            'no linked-data element was rendered',
        );

        return $matches[1];
    }

    /**
     * @param array<string, bool> $structuredData
     *
     * @return array<string, mixed>
     */
    private function appConfig(array $structuredData): array
    {
        /** @var array<string, mixed> $shipped */
        $shipped = (array) Config::load(dirname(__DIR__, 3) . '/config', $_ENV)->get('app');
        $shipped['url'] = 'http://localhost:8080';
        /** @var array<string, mixed> $seo */
        $seo = (array) $shipped['seo'];
        /** @var array<string, bool> $toggles */
        $toggles = (array) $seo['structured_data'];
        $seo['structured_data'] = [...$toggles, ...$structuredData];
        $shipped['seo'] = $seo;

        return $shipped;
    }

    /** @return array<string, mixed> the sample seed plus one `otc` product, so both sides of `[24.10]` are reachable */
    private function catalog(): array
    {
        $products = SampleCatalog::products();

        $otc = $products['semaglutide'];
        $otc['slug'] = 'metabolic-support';
        $otc['name'] = 'Metabolic Support';
        $otc['kind'] = 'otc';
        $otc['variants'][0]['provider'] = ['offer_id' => '337', 'product_id' => '3411'];
        $products['metabolic-support'] = $otc;

        return [
            'channel' => ['id' => 'channel-123', 'name' => 'Demo Store', 'currency' => 'USD'],
            'products' => $products,
        ];
    }
}
