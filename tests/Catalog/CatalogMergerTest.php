<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Catalog;

use AsterMD\Storefront\Catalog\CatalogMerger;
use PHPUnit\Framework\TestCase;

final class CatalogMergerTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function sampleGenerated(): array
    {
        return [
            'channel' => ['id' => 'chan-1', 'name' => 'Flow 1', 'currency' => 'USD'],
            'products' => [
                'abc123-tadalafil' => [
                    'slug' => 'abc123-tadalafil',
                    'name' => 'Tadalafil',
                    'kind' => 'rx',
                    'categories' => ['Sexual Health'],
                    'price_cents' => 5000,
                    'emr_product_id' => 'abc123',
                    'variants' => [
                        ['id' => 'v1', 'name' => '10mg', 'price_cents' => 5000, 'provider' => null],
                        ['id' => 'v2', 'name' => '20mg', 'price_cents' => 6000, 'provider' => null],
                    ],
                ],
                'def456-semaglutide' => [
                    'slug' => 'def456-semaglutide',
                    'name' => 'Semaglutide',
                    'kind' => 'rx',
                    'categories' => ['Weight Loss'],
                    'price_cents' => 4600,
                    'emr_product_id' => 'def456',
                    'variants' => [
                        ['id' => 'v3', 'name' => 'Vial', 'price_cents' => 4600, 'provider' => null],
                    ],
                ],
            ],
        ];
    }

    public function testEmptyOverridesIsIdentity(): void
    {
        $generated = self::sampleGenerated();
        $merged = (new CatalogMerger())->merge($generated, ['products' => []]);

        self::assertSame($generated, $merged);
    }

    public function testScalarFieldOverrideReplacesWholesale(): void
    {
        $generated = self::sampleGenerated();
        $overrides = [
            'products' => [
                'abc123' => [
                    'price_cents' => 9999,
                    'categories' => ['Sexual Health', 'Featured'],
                ],
            ],
        ];

        $merged = (new CatalogMerger())->merge($generated, $overrides);
        $product = $merged['products']['abc123-tadalafil'];

        self::assertSame(9999, $product['price_cents']);
        self::assertSame(['Sexual Health', 'Featured'], $product['categories']);
        // untouched fields survive
        self::assertSame('Tadalafil', $product['name']);
    }

    public function testOtherProductsUnaffectedByOverride(): void
    {
        $generated = self::sampleGenerated();
        $overrides = ['products' => ['abc123' => ['price_cents' => 9999]]];

        $merged = (new CatalogMerger())->merge($generated, $overrides);

        self::assertSame($generated['products']['def456-semaglutide'], $merged['products']['def456-semaglutide']);
    }

    public function testVariantPriceOverridePreservesOrderAndLeavesOtherVariantsUntouched(): void
    {
        $generated = self::sampleGenerated();
        $overrides = [
            'products' => [
                'abc123' => [
                    'variants' => [
                        'v2' => ['price_cents' => 7500],
                    ],
                ],
            ],
        ];

        $merged = (new CatalogMerger())->merge($generated, $overrides);
        $variants = $merged['products']['abc123-tadalafil']['variants'];

        self::assertCount(2, $variants);
        self::assertSame('v1', $variants[0]['id']);
        self::assertSame(5000, $variants[0]['price_cents']);
        self::assertSame('v2', $variants[1]['id']);
        self::assertSame(7500, $variants[1]['price_cents']);
        self::assertSame('20mg', $variants[1]['name']); // untouched field on the overridden variant
    }

    public function testUnmentionedVariantsStayUntouchedAndNoneDropped(): void
    {
        $generated = self::sampleGenerated();
        $overrides = [
            'products' => [
                'def456' => [
                    'variants' => [
                        'v3' => ['name' => 'Vial (renamed)'],
                    ],
                ],
            ],
        ];

        $merged = (new CatalogMerger())->merge($generated, $overrides);
        $variants = $merged['products']['def456-semaglutide']['variants'];

        self::assertCount(1, $variants);
        self::assertSame('Vial (renamed)', $variants[0]['name']);
        self::assertSame(4600, $variants[0]['price_cents']);
    }

    public function testSlugRekeyMovesProductAndUpdatesSlugFieldOtherProductsUnaffected(): void
    {
        $generated = self::sampleGenerated();
        $overrides = [
            'products' => [
                'abc123' => ['slug' => 'tadalafil-new'],
            ],
        ];

        $merged = (new CatalogMerger())->merge($generated, $overrides);

        self::assertArrayNotHasKey('abc123-tadalafil', $merged['products']);
        self::assertArrayHasKey('tadalafil-new', $merged['products']);
        self::assertSame('tadalafil-new', $merged['products']['tadalafil-new']['slug']);
        self::assertSame($generated['products']['def456-semaglutide'], $merged['products']['def456-semaglutide']);
    }

    public function testUnmatchedFullProductDefinitionIsAddedAsNetNewProduct(): void
    {
        $generated = self::sampleGenerated();
        $overrides = [
            'products' => [
                'ghi789' => [
                    'slug' => 'brand-new-product',
                    'name' => 'Brand New Product',
                    'kind' => 'otc',
                    'variants' => [
                        ['id' => 'v9', 'name' => 'Default', 'price_cents' => 1000, 'provider' => null],
                    ],
                ],
            ],
        ];

        $merged = (new CatalogMerger())->merge($generated, $overrides);

        self::assertArrayHasKey('brand-new-product', $merged['products']);
        self::assertSame('Brand New Product', $merged['products']['brand-new-product']['name']);
        self::assertCount(3, $merged['products']);
    }

    public function testRekeyCollidingWithExistingProductSlugThrows(): void
    {
        $generated = self::sampleGenerated();
        $overrides = [
            'products' => [
                // abc123's slug re-key lands on def456's existing slug.
                'abc123' => ['slug' => 'def456-semaglutide'],
            ],
        ];

        try {
            (new CatalogMerger())->merge($generated, $overrides);
            self::fail('Expected RuntimeException for slug collision');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('def456-semaglutide', $e->getMessage());
            self::assertStringContainsString('abc123', $e->getMessage());
            self::assertStringContainsString('def456', $e->getMessage());
        }
    }

    public function testNetNewProductCollidingWithExistingSlugThrows(): void
    {
        $generated = self::sampleGenerated();
        $overrides = [
            'products' => [
                'new-id-999' => [
                    // Net-new product's slug collides with an existing product's slug.
                    'slug' => 'def456-semaglutide',
                    'name' => 'Impostor',
                    'kind' => 'otc',
                    'variants' => [
                        ['id' => 'v9', 'name' => 'Default', 'price_cents' => 100, 'provider' => null],
                    ],
                ],
            ],
        ];

        try {
            (new CatalogMerger())->merge($generated, $overrides);
            self::fail('Expected RuntimeException for slug collision');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('def456-semaglutide', $e->getMessage());
            self::assertStringContainsString('new-id-999', $e->getMessage());
            self::assertStringContainsString('def456', $e->getMessage());
        }
    }

    public function testUnmatchedPartialOverrideIsSilentlyIgnoredButCollected(): void
    {
        $generated = self::sampleGenerated();
        $overrides = [
            'products' => [
                'nonexistent-id' => ['price_cents' => 100],
            ],
        ];

        $merger = new CatalogMerger();
        $merged = $merger->merge($generated, $overrides);

        self::assertSame($generated, $merged);
        self::assertSame(['nonexistent-id'], $merger->lastIgnoredOverrideIds());
    }

    public function testNonArrayOverrideValueForAMatchedProductIdIsCollectedAsIgnored(): void
    {
        // abc123 exists (its emr_product_id matches), but the override value
        // itself is a scalar, not an array — it can't be applied as a field
        // override AND it fails the full-product-definition check (which
        // requires an array), so it falls through to the ignored-ids list
        // rather than silently vanishing or crashing.
        $generated = self::sampleGenerated();
        $overrides = ['products' => ['abc123' => 'not-an-array']];

        $merger = new CatalogMerger();
        $merged = $merger->merge($generated, $overrides);

        self::assertSame($generated['products']['abc123-tadalafil'], $merged['products']['abc123-tadalafil']);
        self::assertSame(['abc123'], $merger->lastIgnoredOverrideIds());
    }

    public function testUnmatchedVariantOverrideIsCollectedAsProductIdColonVariantId(): void
    {
        $generated = self::sampleGenerated();
        $overrides = [
            'products' => [
                'abc123' => [
                    'variants' => [
                        'does-not-exist' => ['price_cents' => 1],
                    ],
                ],
            ],
        ];

        $merger = new CatalogMerger();
        $merger->merge($generated, $overrides);

        self::assertSame(['abc123:does-not-exist'], $merger->lastIgnoredOverrideIds());
    }

    public function testLastIgnoredOverrideIdsResetsBetweenCalls(): void
    {
        $generated = self::sampleGenerated();
        $merger = new CatalogMerger();

        $merger->merge($generated, ['products' => ['nope' => ['price_cents' => 1]]]);
        self::assertSame(['nope'], $merger->lastIgnoredOverrideIds());

        $merger->merge($generated, ['products' => []]);
        self::assertSame([], $merger->lastIgnoredOverrideIds());
    }

    public function testGeneratedProductWithoutVariantsKeyIsToleratedAsEmptyList(): void
    {
        $generated = [
            'products' => [
                'no-variants' => [
                    'slug' => 'no-variants',
                    'name' => 'No Variants',
                    'kind' => 'otc',
                    'emr_product_id' => 'zzz',
                ],
            ],
        ];
        $overrides = ['products' => ['zzz' => ['price_cents' => 1200]]];

        $merged = (new CatalogMerger())->merge($generated, $overrides);

        self::assertSame(1200, $merged['products']['no-variants']['price_cents']);
    }

    public function testMissingChannelKeyIsTolerated(): void
    {
        $generated = ['products' => []];
        $merged = (new CatalogMerger())->merge($generated, ['products' => []]);

        self::assertArrayNotHasKey('channel', $merged);
        self::assertSame([], $merged['products']);
    }
}
