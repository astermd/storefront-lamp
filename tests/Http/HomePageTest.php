<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Tests\Support\ConfigVariant;
use AsterMD\Storefront\Tests\Support\SampleCatalog;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class HomePageTest extends TestCase
{
    use ConfigVariant;

    public function testHomepageRendersConvertedSections(): void
    {
        $app = AppFactory::create(dirname(__DIR__, 2));
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/'));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Our Treatments', $body);
        self::assertStringContainsString('How Your Treatment Works', $body);
        self::assertStringContainsString('Frequently Asked', $body);
        self::assertStringContainsString('id="cart-panel"', $body);
        self::assertStringContainsString('/assets/build/app.', $body);

        self::assertStringNotContainsString('cdn.tailwindcss.com', $body);
        self::assertStringNotContainsString('fonts.googleapis.com', $body);
        self::assertStringNotContainsString('unpkg.com', $body);
    }

    /**
     * `[6.2]`: the figure on a grid card is the first variant's, not the
     * product-level one.
     *
     * A product carries a `price_cents` of its own *and* a list of variants
     * that each carry one, and the two are free to disagree — the product
     * level is a headline figure, while the variant is the plan a visitor is
     * actually quoted and charged. A card that read the product level would
     * advertise a price the checkout refuses, which is the same divergence
     * `[24.9]` forbids publishing as structured data.
     *
     * The catalog is injected rather than read from the deployment because
     * this needs a product where the two figures **differ**, and the real
     * `config/products.generated.php` is whatever the last
     * `bin/console theme:sync --apply` wrote: every product currently in it
     * has a product-level price identical to its first variant's, so a card
     * reading the wrong field would render exactly the same page and this
     * would pass against a broken template. The sample seed's semaglutide is
     * priced 4600 at the product level and 5000 on the plan it sells, which is
     * the only shape that can tell the two readings apart —
     * {@see \AsterMD\Storefront\Tests\Seo\StructuredData\StructuredDataTest}
     * uses it for the same reason.
     */
    public function testAGridCardQuotesTheFirstVariantsPriceAndNotTheProductLevelOne(): void
    {
        // Semaglutide alone, so the grid holds exactly one card and the figure
        // read off the page is unambiguously that card's. Every other product
        // in the seed is priced 5000 at both levels, and leaving them in would
        // let a broken card be masked by a correct one quoting the same money.
        $product = SampleCatalog::products()['semaglutide'];

        // The guard that keeps this from going quiet: if the seed is ever
        // levelled so the two figures agree, the assertion below stops
        // distinguishing anything and this says so rather than passing.
        self::assertNotSame(
            $product['price_cents'],
            $product['variants'][0]['price_cents'],
            'the catalog under test cannot express the divergence, so nothing below asserts it',
        );

        $body = (string) $this->app(['semaglutide' => $product])
            ->handle((new ServerRequestFactory())->createServerRequest('GET', '/'))
            ->getBody();

        self::assertSame(
            1,
            preg_match_all('#Starting from (\$[\d,.]+)/mo#', $body, $quoted),
            'the grid drew exactly one card, so the figure below is that card\'s',
        );
        // $50.00 is the plan; $46.00 is the product-level headline the card
        // must never reach for.
        self::assertSame(['$50.00'], $quoted[1]);
    }

    /**
     * The home page over a stated catalog.
     *
     * {@see \AsterMD\Storefront\Http\Controller\HomeController} depends on the
     * concrete, final {@see \AsterMD\Storefront\Catalog\CatalogProvider}
     * rather than on the {@see \AsterMD\Storefront\Domain\ProductCatalog}
     * interface, so a fake cannot stand in for it and the catalog has to be
     * varied where the provider reads it: the configuration.
     *
     * @param array<string, array<string, mixed>> $products
     */
    private function app(array $products): \Slim\App
    {
        return AppFactory::create(dirname(__DIR__, 2), [
            Config::class => $this->configWith([
                'products.generated' => [
                    'channel' => ['id' => 'test-channel', 'name' => 'Test', 'currency' => 'USD'],
                    'products' => $products,
                ],
                // Emptied with it, so what the page shows comes from the
                // stated catalog alone rather than half from a file this case
                // has nothing to say about.
                'products.overrides' => ['products' => []],
            ]),
        ]);
    }
}
