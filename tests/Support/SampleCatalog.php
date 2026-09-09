<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use AsterMD\Storefront\Catalog\CatalogProvider;
use AsterMD\Storefront\Support\Config;

/**
 * The six-product sample catalog the theme originally shipped with
 * (enclomiphene, finasteride, ramelteon, semaglutide, sermorelin, tadalafil),
 * plus the sample lab override — byte-for-byte what `config/products.generated.php`
 * and `config/products.overrides.php` held before the first
 * `bin/console theme:sync --apply` replaced the generated file with live EMR
 * data. Tests written against that seed inject it explicitly through here
 * rather than reading whatever the real generated file currently holds,
 * which a sync overwrites and is never the same catalog twice.
 */
final class SampleCatalog
{
    /** @return array<string, array<string, mixed>> keyed by slug, the original seed's six rx products */
    public static function products(): array
    {
        return [
            'enclomiphene' => [
                'slug' => 'enclomiphene',
                'name' => 'Enclomiphene',
                'subtitle' => null,
                'kind' => 'rx',
                'categories' => ['Hormone Balance'],
                'description' => "Supports the body's natural testosterone production in men",
                'price_cents' => 5000,
                'price_unit' => '/ month',
                'image' => '/assets/img/t1.png',
                'gallery' => ['/assets/img/product-enclomiphene.png'],
                'badges' => [
                    ['label' => 'In Stock', 'variant' => 'primary'],
                    ['label' => 'FDA Approved', 'variant' => 'secondary'],
                    ['label' => 'Free Shipping', 'variant' => 'secondary'],
                ],
                'variants' => [
                    ['id' => 'enclomiphene-1m', 'name' => '1 Month', 'price_cents' => 5000, 'provider' => null],
                ],
                'emr_product_id' => null,
                'teleform_id' => null,
            ],
            'finasteride' => [
                'slug' => 'finasteride',
                'name' => 'Finasteride',
                'subtitle' => null,
                'kind' => 'rx',
                'categories' => ['Hair Loss'],
                'description' => 'A treatment that helps slow hair loss and support hair regrowth in men.',
                'price_cents' => 5000,
                'price_unit' => '/ month',
                'image' => '/assets/img/t2.png',
                'gallery' => ['/assets/img/product-finasteride.png'],
                'badges' => [
                    ['label' => 'In Stock', 'variant' => 'primary'],
                    ['label' => 'FDA Approved', 'variant' => 'secondary'],
                    ['label' => 'Free Shipping', 'variant' => 'secondary'],
                ],
                'variants' => [
                    ['id' => 'finasteride-1m', 'name' => '1 Month', 'price_cents' => 5000, 'provider' => null],
                ],
                'emr_product_id' => null,
                'teleform_id' => null,
            ],
            'ramelteon' => [
                'slug' => 'ramelteon',
                'name' => 'Ramelteon',
                'subtitle' => null,
                'kind' => 'rx',
                'categories' => ['Sleep'],
                'description' => 'A non-habit-forming medication that helps you fall asleep naturally.',
                'price_cents' => 5000,
                'price_unit' => '/ month',
                'image' => '/assets/img/t3.png',
                'gallery' => ['/assets/img/product-ramelteon.png'],
                'badges' => [
                    ['label' => 'In Stock', 'variant' => 'primary'],
                    ['label' => 'FDA Approved', 'variant' => 'secondary'],
                    ['label' => 'Free Shipping', 'variant' => 'secondary'],
                ],
                'variants' => [
                    ['id' => 'ramelteon-1m', 'name' => '1 Month', 'price_cents' => 5000, 'provider' => null],
                ],
                'emr_product_id' => null,
                'teleform_id' => null,
            ],
            'semaglutide' => [
                'slug' => 'semaglutide',
                'name' => 'Semaglutide',
                'subtitle' => 'GLP-1 Receptor Agonist',
                'kind' => 'rx',
                'categories' => ['Weight Loss'],
                'description' => 'Helps reduce appetite and support long-term weight loss.',
                'price_cents' => 4600,
                'price_unit' => '/ month',
                'image' => '/assets/img/t4.png',
                'gallery' => ['/assets/img/product-details.png'],
                'badges' => [
                    ['label' => 'In Stock', 'variant' => 'primary'],
                    ['label' => 'FDA Approved', 'variant' => 'secondary'],
                    ['label' => 'Free Shipping', 'variant' => 'secondary'],
                ],
                'variants' => [
                    ['id' => 'semaglutide-1m', 'name' => '1 Month', 'price_cents' => 5000, 'provider' => null],
                    ['id' => 'semaglutide-3m', 'name' => '3 Months', 'price_cents' => 4600, 'provider' => null],
                    ['id' => 'semaglutide-6m', 'name' => '6 Months', 'price_cents' => 4000, 'provider' => null],
                ],
                'emr_product_id' => null,
                'teleform_id' => '6a8aec0dec745c70f5f3e69a',
            ],
            'sermorelin' => [
                'slug' => 'sermorelin',
                'name' => 'Sermorelin',
                'subtitle' => null,
                'kind' => 'rx',
                'categories' => ['Hormone Balance'],
                'description' => 'A peptide that stimulates natural growth hormone production',
                'price_cents' => 5000,
                'price_unit' => '/ month',
                'image' => '/assets/img/t5.png',
                'gallery' => ['/assets/img/product-sermorelin.png'],
                'badges' => [
                    ['label' => 'In Stock', 'variant' => 'primary'],
                    ['label' => 'FDA Approved', 'variant' => 'secondary'],
                    ['label' => 'Free Shipping', 'variant' => 'secondary'],
                ],
                'variants' => [
                    ['id' => 'sermorelin-1m', 'name' => '1 Month', 'price_cents' => 5000, 'provider' => null],
                ],
                'emr_product_id' => null,
                'teleform_id' => null,
            ],
            'tadalafil' => [
                'slug' => 'tadalafil',
                'name' => 'Tadalafil',
                'subtitle' => null,
                'kind' => 'rx',
                'categories' => ['Sexual Health'],
                'description' => 'Improve erectile function by increasing blood flow when stimulated',
                'price_cents' => 5000,
                'price_unit' => '/ month',
                'image' => '/assets/img/t6.png',
                'gallery' => ['/assets/img/product-tadalafil.png'],
                'badges' => [
                    ['label' => 'In Stock', 'variant' => 'primary'],
                    ['label' => 'FDA Approved', 'variant' => 'secondary'],
                    ['label' => 'Free Shipping', 'variant' => 'secondary'],
                ],
                'variants' => [
                    ['id' => 'tadalafil-1m', 'name' => '1 Month', 'price_cents' => 5000, 'provider' => null],
                ],
                'emr_product_id' => null,
                'teleform_id' => null,
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> the sample lab override from config/products.overrides.php, keyed by its override id */
    public static function labOverride(): array
    {
        return [
            'sample-lab-cmp' => [
                'slug' => 'comprehensive-metabolic-panel',
                'name' => 'Comprehensive Metabolic Panel',
                'kind' => 'lab',
                'categories' => [],
                'price_cents' => 8900,
                'variants' => [
                    ['id' => 'comprehensive-metabolic-panel-panel', 'name' => 'One-time panel', 'price_cents' => 8900, 'provider' => null],
                ],
            ],
        ];
    }

    /**
     * The merged view {@see CatalogProvider::product()} ultimately serves —
     * the six sample seed products plus the lab override, keyed by slug —
     * for `FakeCatalog`-backed tests that add `comprehensive-metabolic-panel`
     * to a cart through the real `/cart/add/` endpoint and so need the
     * override actually present, rather than going through {@see self::provider()}'s
     * generated/overrides merge.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function productsWithLabOverride(): array
    {
        $lab = self::labOverride()['sample-lab-cmp'];

        return [...self::products(), $lab['slug'] => $lab];
    }

    /**
     * A real {@see CatalogProvider} backed by a throwaway config directory
     * holding this sample catalog — for controllers
     * ({@see \AsterMD\Storefront\Http\Controller\ProductDetailController},
     * {@see \AsterMD\Storefront\Http\Controller\ProductListController},
     * {@see \AsterMD\Storefront\Http\Controller\HomeController}) that depend
     * on the concrete, final `CatalogProvider` rather than the
     * `ProductCatalog` interface, so a plain {@see FakeCatalog} can't stand
     * in for them. `Config::load()` only globs the files present in a
     * directory, so this fixture directory needs nothing but the two
     * products files `CatalogProvider` itself reads.
     */
    public static function provider(): CatalogProvider
    {
        $dir = sys_get_temp_dir() . '/astermd-sample-catalog-fixture';
        if (!is_dir($dir)) {
            mkdir($dir);
        }

        file_put_contents(
            $dir . '/products.generated.php',
            '<?php return ' . var_export(['products' => self::products()], true) . ';',
        );
        file_put_contents(
            $dir . '/products.overrides.php',
            '<?php return ' . var_export(['products' => self::labOverride()], true) . ';',
        );

        return new CatalogProvider(Config::load($dir));
    }
}
