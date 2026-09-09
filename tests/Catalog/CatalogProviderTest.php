<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Catalog;

use AsterMD\Storefront\Catalog\CatalogProvider;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Tests\Support\SampleCatalog;
use PHPUnit\Framework\TestCase;

final class CatalogProviderTest extends TestCase
{
    /**
     * Built from {@see SampleCatalog}'s fixture rather than the real
     * `config/` directory: `bin/console theme:sync --apply` overwrites
     * `config/products.generated.php` with whatever the configured EMR
     * channel currently sells, so a provider built from the live directory
     * would assert against a catalog that changes out from under it.
     */
    private function provider(): CatalogProvider
    {
        return SampleCatalog::provider();
    }

    public function testProductsListsAllSixSampleSeedProductsPlusTheSampleLabOverride(): void
    {
        // config/products.overrides.php hand-adds one sample `lab` product
        // (net-new, per [2.18]) on top of the six generated-seed rx/otc
        // products, so products() — which is unfiltered by kind — sees
        // seven; see testListedProductsExcludesTheSampleLabOverride() below
        // for the [6.1] filtered view.
        $products = $this->provider()->products();

        self::assertCount(7, $products);
        $slugs = array_map(static fn (array $product): string => $product['slug'], $products);
        sort($slugs);
        self::assertSame(
            [
                'comprehensive-metabolic-panel',
                'enclomiphene',
                'finasteride',
                'ramelteon',
                'semaglutide',
                'sermorelin',
                'tadalafil',
            ],
            $slugs,
        );
    }

    public function testListedProductsExcludesTheSampleLabOverride(): void
    {
        // [6.1]: the sample `lab` product hand-added via
        // config/products.overrides.php must never appear in the listed
        // (rx/otc) view, even though products() sees it.
        $slugs = array_map(
            static fn (array $product): string => $product['slug'],
            $this->provider()->listedProducts(),
        );

        self::assertNotContains('comprehensive-metabolic-panel', $slugs);
        self::assertCount(6, $slugs);
    }

    public function testProductReturnsBySlug(): void
    {
        $product = $this->provider()->product('semaglutide');

        self::assertNotNull($product);
        self::assertSame('Semaglutide', $product['name']);
        self::assertSame(4600, $product['price_cents']);
    }

    public function testProductReturnsNullForUnknownSlug(): void
    {
        self::assertNull($this->provider()->product('does-not-exist'));
    }

    public function testCategoriesAreSortedAndUnique(): void
    {
        $categories = $this->provider()->categories();

        $sorted = $categories;
        sort($sorted);
        self::assertSame($sorted, $categories);
        self::assertSame(array_values(array_unique($categories)), $categories);
        self::assertContains('Hormone Balance', $categories);
        self::assertContains('Weight Loss', $categories);
    }

    /**
     * `assertSame()` on two arrays is VALUE equality, not identity — it
     * can't distinguish "catalog() served the memoized value" from "catalog()
     * recomputed and happened to get an equal result both times", so it
     * can't actually prove memoization. To prove the memo field is really
     * the serving path, this poisons it via reflection with a sentinel
     * `merge()` could never produce, then confirms catalog()/products()/
     * product() hand that exact sentinel back — which only happens if
     * catalog() short-circuits on the (non-null) memo instead of re-merging
     * from config. Reflection is used deliberately here, specifically
     * because value-equality assertions on plain arrays can't observe
     * "was this recomputed" any other way.
     */
    public function testCatalogIsMemoizedAcrossCalls(): void
    {
        $provider = $this->provider();
        $provider->catalog(); // populate the memo from real config first

        $sentinel = [
            'channel' => null,
            'products' => [
                'sentinel-slug' => ['slug' => 'sentinel-slug', 'name' => 'Sentinel', 'categories' => []],
            ],
        ];

        // No setAccessible() call: PHP 8.1+ reflection can get/set private
        // properties directly without it (setAccessible() is a deprecated
        // no-op on modern PHP, which is exactly what tripped this up).
        $property = new \ReflectionProperty(CatalogProvider::class, 'catalog');
        $property->setValue($provider, $sentinel);

        self::assertSame($sentinel, $provider->catalog());
        self::assertSame(array_values($sentinel['products']), $provider->products());
        self::assertSame($sentinel['products']['sentinel-slug'], $provider->product('sentinel-slug'));
    }

    public function testLastIgnoredOverrideIdsIsEmptyBeforeCatalogIsComputed(): void
    {
        self::assertSame([], $this->provider()->lastIgnoredOverrideIds());
    }

    public function testLastIgnoredOverrideIdsExposesUnknownPartialOverrideAfterCatalogIsComputed(): void
    {
        $configDir = sys_get_temp_dir() . '/catalog-provider-test-' . bin2hex(random_bytes(4));
        mkdir($configDir);

        file_put_contents(
            $configDir . '/products.generated.php',
            '<?php return ' . var_export([
                'products' => [
                    'known' => [
                        'slug' => 'known',
                        'name' => 'Known Product',
                        'kind' => 'otc',
                        'emr_product_id' => 'known-id',
                        'variants' => [],
                    ],
                ],
            ], true) . ';',
        );
        file_put_contents(
            $configDir . '/products.overrides.php',
            '<?php return ' . var_export([
                'products' => [
                    // Matches no generated emr_product_id and isn't a full product definition.
                    'unknown-id' => ['price_cents' => 100],
                ],
            ], true) . ';',
        );

        try {
            $provider = new CatalogProvider(Config::load($configDir));

            self::assertSame([], $provider->lastIgnoredOverrideIds());

            $provider->catalog();

            self::assertSame(['unknown-id'], $provider->lastIgnoredOverrideIds());
        } finally {
            unlink($configDir . '/products.generated.php');
            unlink($configDir . '/products.overrides.php');
            rmdir($configDir);
        }
    }

    /**
     * [6.1]: listedProducts() must return only `rx`/`otc` products, excluding
     * `lab` and `free-addon` products — while product(slug) keeps resolving
     * them individually, since a direct-slug detail view of a nested product
     * is harmless (carts control purchasability later, per the fix note).
     */
    public function testListedProductsExcludesLabAndFreeAddonProductsButProductStillResolvesThem(): void
    {
        $configDir = sys_get_temp_dir() . '/catalog-provider-test-' . bin2hex(random_bytes(4));
        mkdir($configDir);

        file_put_contents(
            $configDir . '/products.generated.php',
            '<?php return ' . var_export([
                'products' => [
                    'rx-product' => [
                        'slug' => 'rx-product',
                        'name' => 'Rx Product',
                        'kind' => 'rx',
                        'variants' => [],
                    ],
                    'otc-product' => [
                        'slug' => 'otc-product',
                        'name' => 'OTC Product',
                        'kind' => 'otc',
                        'variants' => [],
                    ],
                    'lab-product' => [
                        'slug' => 'lab-product',
                        'name' => 'Lab Product',
                        'kind' => 'lab',
                        'variants' => [],
                    ],
                    'free-addon-product' => [
                        'slug' => 'free-addon-product',
                        'name' => 'Free Addon Product',
                        'kind' => 'free-addon',
                        'variants' => [],
                    ],
                ],
            ], true) . ';',
        );
        file_put_contents(
            $configDir . '/products.overrides.php',
            '<?php return ' . var_export(['products' => []], true) . ';',
        );

        try {
            $provider = new CatalogProvider(Config::load($configDir));

            $listedSlugs = array_map(
                static fn (array $product): string => $product['slug'],
                $provider->listedProducts(),
            );

            self::assertSame(['rx-product', 'otc-product'], $listedSlugs);

            self::assertNotNull($provider->product('lab-product'));
            self::assertSame('Lab Product', $provider->product('lab-product')['name']);
            self::assertNotNull($provider->product('free-addon-product'));
            self::assertSame('Free Addon Product', $provider->product('free-addon-product')['name']);
        } finally {
            unlink($configDir . '/products.generated.php');
            unlink($configDir . '/products.overrides.php');
            rmdir($configDir);
        }
    }
}
