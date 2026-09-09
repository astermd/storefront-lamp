<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Catalog;

use AsterMD\Storefront\Catalog\CatalogBuilder;
use PHPUnit\Framework\TestCase;

final class CatalogBuilderTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fixtureData(): array
    {
        $decoded = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/channel-details.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return $decoded['data'];
    }

    public function testChannelMetadataIsCarriedThrough(): void
    {
        $catalog = CatalogBuilder::build(self::fixtureData(), 'USD');

        self::assertSame(
            ['id' => '6a1c2a565f315cee0e41c395', 'name' => 'Flow 1', 'currency' => 'USD'],
            $catalog['channel'],
        );
    }

    public function testSevenTopLevelProductsBecomeExpectedSlugs(): void
    {
        $catalog = CatalogBuilder::build(self::fixtureData());

        $topLevelSlugs = [
            '6a1c29-electrolyte-powder',
            '6a1c29-berberine-500mg',
            '6a1c28-tadalafil',
            '6a1c28-enclomiphene-trt',
            '6a1c35-anti-nausea-kit-ondansetron',
            '6a1c36-men-daily-multivitamin',
            '6a1c26-semaglutide-vial',
        ];

        foreach ($topLevelSlugs as $slug) {
            self::assertArrayHasKey($slug, $catalog['products'], "missing product slug: $slug");
        }
    }

    public function testSlugIsFirstSixIdCharsPlusSlugifiedName(): void
    {
        $catalog = CatalogBuilder::build(self::fixtureData());

        $product = $catalog['products']['6a1c26-semaglutide-vial'];
        self::assertSame('6a1c26-semaglutide-vial', $product['slug']);
        self::assertSame('Semaglutide - Vial', $product['name']);
        self::assertSame('6a1c268d5f315cee0e41c325', $product['emr_product_id']);
    }

    public function testKindMapsPrescriptionToRxAndEverythingElseToOtc(): void
    {
        $catalog = CatalogBuilder::build(self::fixtureData());

        self::assertSame('rx', $catalog['products']['6a1c28-tadalafil']['kind']);
        self::assertSame('rx', $catalog['products']['6a1c28-enclomiphene-trt']['kind']);
        self::assertSame('rx', $catalog['products']['6a1c26-semaglutide-vial']['kind']);
        self::assertSame('otc', $catalog['products']['6a1c29-electrolyte-powder']['kind']);
        self::assertSame('otc', $catalog['products']['6a1c29-berberine-500mg']['kind']);
        self::assertSame('otc', $catalog['products']['6a1c35-anti-nausea-kit-ondansetron']['kind']);
        self::assertSame('otc', $catalog['products']['6a1c36-men-daily-multivitamin']['kind']);
    }

    public function testTadalafilHasThreeVariantsAt4900CentsEach(): void
    {
        $catalog = CatalogBuilder::build(self::fixtureData());
        $tadalafil = $catalog['products']['6a1c28-tadalafil'];

        self::assertCount(3, $tadalafil['variants']);
        foreach ($tadalafil['variants'] as $variant) {
            self::assertSame(4900, $variant['price_cents']);
        }

        // Fixture note: all three Tadalafil mappings share offer_id "337"
        // (one subscription offer with three variant SKUs); what's distinct
        // per variant is the provider product_id.
        $providerProductIds = array_map(
            static fn (array $v) => $v['provider']['product_id'],
            $tadalafil['variants'],
        );
        self::assertSame(['2838', '2839', '2840'], $providerProductIds);
        foreach ($tadalafil['variants'] as $variant) {
            self::assertSame('337', $variant['provider']['offer_id']);
        }

        self::assertSame(4900, $tadalafil['price_cents']);
        self::assertSame('6a0bf7051cb6b9433b12f9be', $tadalafil['teleform_id']);
    }

    public function testEnclomipheneHasTwoVariantsAndOneLabBundle(): void
    {
        $catalog = CatalogBuilder::build(self::fixtureData());
        $enclomiphene = $catalog['products']['6a1c28-enclomiphene-trt'];

        self::assertCount(2, $enclomiphene['variants']);
        foreach ($enclomiphene['variants'] as $variant) {
            self::assertSame(14900, $variant['price_cents']);
        }

        self::assertSame(['6a1c2a-at-home-hormone-lab-panel'], $enclomiphene['bundles']);
        self::assertArrayHasKey('6a1c2a-at-home-hormone-lab-panel', $catalog['products']);

        $lab = $catalog['products']['6a1c2a-at-home-hormone-lab-panel'];
        self::assertSame('lab', $lab['kind']);
        self::assertSame(8900, $lab['price_cents']);
        self::assertNull($lab['variants'][0]['provider']);
    }

    public function testSemaglutideHasThreeVariantsAndOneAttachment(): void
    {
        $catalog = CatalogBuilder::build(self::fixtureData());
        $semaglutide = $catalog['products']['6a1c26-semaglutide-vial'];

        self::assertCount(3, $semaglutide['variants']);
        foreach ($semaglutide['variants'] as $variant) {
            self::assertSame(29900, $variant['price_cents']);
        }

        self::assertSame(['6a1c26-syringe'], $semaglutide['attachments']);
        self::assertArrayHasKey('6a1c26-syringe', $catalog['products']);

        $syringe = $catalog['products']['6a1c26-syringe'];
        self::assertSame('free-addon', $syringe['kind']);
        self::assertSame(200, $syringe['price_cents']);

        // First-match-in-array-order provider resolution (rule 5): each
        // variant is mapped by the vrio entries which precede the
        // checkout_champ duplicates for the same variant_id in the fixture.
        self::assertSame('337', $semaglutide['variants'][0]['provider']['offer_id']);
        self::assertSame('2835', $semaglutide['variants'][0]['provider']['product_id']);
    }

    public function testElectrolytePowderHasNoExplicitVariantsAndSynthesizesOne(): void
    {
        $catalog = CatalogBuilder::build(self::fixtureData());
        $electrolyte = $catalog['products']['6a1c29-electrolyte-powder'];

        self::assertCount(1, $electrolyte['variants']);
        self::assertSame(1900, $electrolyte['variants'][0]['price_cents']);
        self::assertSame(1900, $electrolyte['price_cents']);
        self::assertSame('340', $electrolyte['variants'][0]['provider']['offer_id']);
        self::assertSame('6a1c29ef5f315cee0e41c377', $electrolyte['variants'][0]['id']);
    }

    public function testVariantWithNoMappingHasNullProvider(): void
    {
        // Craft a synthetic minimal channel payload with a variant that has
        // no matching product_mappings entry, so provider resolution must
        // yield null rather than throwing or fabricating data.
        $data = [
            '_id' => 'chan01',
            'name' => 'Synthetic Channel',
            'products' => [
                [
                    '_id' => 'entry01',
                    'product_id' => 'prod01',
                    'add_ons' => [],
                    'product' => [
                        '_id' => 'prod01xxxxxxxxxxxxxxxxxxxx',
                        'name' => 'Unmapped Widget',
                        'type' => 'standard',
                        'status' => 1,
                        'visibility' => 'Public',
                        'categories' => [],
                        'condition_treated' => [],
                        'description_long' => null,
                        'description_short' => null,
                        'image' => null,
                        'labtest' => [],
                        'max_buy_qty' => null,
                        'min_buy_qty' => null,
                        'restrict_multiple' => false,
                        'single' => [['default_price' => 10]],
                        'teleforms' => [],
                        'variants' => [],
                    ],
                    'product_mappings' => [],
                ],
            ],
        ];

        $catalog = CatalogBuilder::build($data);
        $product = $catalog['products']['prod01-unmapped-widget'];

        self::assertNull($product['variants'][0]['provider']);
    }

    public function testProductsFailingStatusOrVisibilityChecksAreSkipped(): void
    {
        $data = [
            '_id' => 'chan01',
            'name' => 'Synthetic Channel',
            'products' => [
                [
                    '_id' => 'entry01',
                    'product_id' => 'prod01',
                    'add_ons' => [],
                    'product' => [
                        '_id' => 'prod01xxxxxxxxxxxxxxxxxxxx',
                        'name' => 'Inactive Widget',
                        'type' => 'standard',
                        'status' => 0,
                        'visibility' => 'Public',
                        'categories' => [],
                        'condition_treated' => [],
                        'description_long' => null,
                        'description_short' => null,
                        'image' => null,
                        'labtest' => [],
                        'max_buy_qty' => null,
                        'min_buy_qty' => null,
                        'restrict_multiple' => false,
                        'single' => [['default_price' => 10]],
                        'teleforms' => [],
                        'variants' => [],
                    ],
                    'product_mappings' => [],
                ],
                [
                    '_id' => 'entry02',
                    'product_id' => 'prod02',
                    'add_ons' => [],
                    'product' => [
                        '_id' => 'prod02xxxxxxxxxxxxxxxxxxxx',
                        'name' => 'Hidden Widget',
                        'type' => 'standard',
                        'status' => 1,
                        'visibility' => 'Private',
                        'categories' => [],
                        'condition_treated' => [],
                        'description_long' => null,
                        'description_short' => null,
                        'image' => null,
                        'labtest' => [],
                        'max_buy_qty' => null,
                        'min_buy_qty' => null,
                        'restrict_multiple' => false,
                        'single' => [['default_price' => 10]],
                        'teleforms' => [],
                        'variants' => [],
                    ],
                    'product_mappings' => [],
                ],
                [
                    '_id' => 'entry03',
                    'product_id' => 'prod03',
                    'add_ons' => [],
                    'product' => [
                        '_id' => 'prod03xxxxxxxxxxxxxxxxxxxx',
                        'name' => 'Live Widget',
                        'type' => 'standard',
                        'status' => 1,
                        'visibility' => 'Public',
                        'categories' => [],
                        'condition_treated' => [],
                        'description_long' => null,
                        'description_short' => null,
                        'image' => null,
                        'labtest' => [],
                        'max_buy_qty' => null,
                        'min_buy_qty' => null,
                        'restrict_multiple' => false,
                        'single' => [['default_price' => 10]],
                        'teleforms' => [],
                        'variants' => [],
                    ],
                    'product_mappings' => [],
                ],
            ],
        ];

        $catalog = CatalogBuilder::build($data);

        self::assertCount(1, $catalog['products']);
        self::assertArrayHasKey('prod03-live-widget', $catalog['products']);
    }

    public function testSameLabReferencedByTwoParentsEmitsOnce(): void
    {
        $sharedLab = [
            '_id' => 'lab001xxxxxxxxxxxxxxxxxxxx',
            'name' => 'Shared Lab Panel',
            'price' => 50,
            'status' => 1,
            'variants' => [],
        ];

        $data = [
            '_id' => 'chan01',
            'name' => 'Synthetic Channel',
            'products' => [
                [
                    '_id' => 'entry01',
                    'product_id' => 'prodA',
                    'add_ons' => [],
                    'product' => [
                        '_id' => 'prodAxxxxxxxxxxxxxxxxxxxxx',
                        'name' => 'Widget A',
                        'type' => 'standard',
                        'status' => 1,
                        'visibility' => 'Public',
                        'categories' => [],
                        'condition_treated' => [],
                        'description_long' => null,
                        'description_short' => null,
                        'image' => null,
                        'labtest' => [$sharedLab],
                        'max_buy_qty' => null,
                        'min_buy_qty' => null,
                        'restrict_multiple' => false,
                        'single' => [['default_price' => 10]],
                        'teleforms' => [],
                        'variants' => [],
                    ],
                    'product_mappings' => [],
                ],
                [
                    '_id' => 'entry02',
                    'product_id' => 'prodB',
                    'add_ons' => [],
                    'product' => [
                        '_id' => 'prodBxxxxxxxxxxxxxxxxxxxxx',
                        'name' => 'Widget B',
                        'type' => 'standard',
                        'status' => 1,
                        'visibility' => 'Public',
                        'categories' => [],
                        'condition_treated' => [],
                        'description_long' => null,
                        'description_short' => null,
                        'image' => null,
                        'labtest' => [$sharedLab],
                        'max_buy_qty' => null,
                        'min_buy_qty' => null,
                        'restrict_multiple' => false,
                        'single' => [['default_price' => 10]],
                        'teleforms' => [],
                        'variants' => [],
                    ],
                    'product_mappings' => [],
                ],
            ],
        ];

        $catalog = CatalogBuilder::build($data);

        $labSlugs = array_filter(array_keys($catalog['products']), static fn ($slug) => str_contains($slug, 'shared-lab-panel'));
        self::assertCount(1, $labSlugs);
        self::assertSame(
            $catalog['products']['prodAx-widget-a']['bundles'],
            $catalog['products']['prodBx-widget-b']['bundles'],
        );
    }

    public function testProductShapeHasAllContractKeys(): void
    {
        $catalog = CatalogBuilder::build(self::fixtureData());
        $product = $catalog['products']['6a1c26-semaglutide-vial'];

        $expectedKeys = [
            'slug', 'name', 'subtitle', 'kind', 'categories', 'condition_treated',
            'description', 'price_cents', 'price_unit', 'image', 'gallery', 'badges',
            'emr_product_id', 'remote_image', 'teleform_id', 'max_buy_qty', 'min_buy_qty',
            'restrict_multiple', 'variants', 'bundles', 'attachments',
        ];

        foreach ($expectedKeys as $key) {
            self::assertArrayHasKey($key, $product, "missing contract key: $key");
        }

        self::assertNull($product['subtitle']);
        self::assertSame('/ month', $product['price_unit']);
        self::assertSame('/assets/img/product-placeholder.svg', $product['image']);
        self::assertSame(['/assets/img/product-placeholder.svg'], $product['gallery']);
        self::assertSame([], $product['badges']);
        self::assertIsBool($product['restrict_multiple']);
        self::assertTrue($product['restrict_multiple']);
        self::assertSame(1, $product['max_buy_qty']);
        self::assertSame(1, $product['min_buy_qty']);
        self::assertSame(
            '69c3cdd306d9a0bfb5143de5/69c3cdd406d9a0bfb5143de7/products/6a1c268d5f315cee0e41c325-b4070feb-c814-435d-a7d1-0d5e7913bd6b.jpg',
            $product['remote_image'],
        );
        self::assertSame(['Weight Loss'], $product['categories']);
        self::assertSame(['Obesity'], $product['condition_treated']);
        self::assertSame('6a1f12de1003c152e76b0190', $product['teleform_id']);
    }

    public function testBuildIsDeterministicAcrossCalls(): void
    {
        $data = self::fixtureData();

        $first = CatalogBuilder::build($data);
        $second = CatalogBuilder::build($data);

        self::assertSame($first, $second);
    }

    /** @return array<string, mixed> minimal valid top-level channel-product entry, name/id overridable */
    private static function minimalEntry(string $entryId, string $productId, string $name): array
    {
        return [
            '_id' => $entryId,
            'product_id' => $productId,
            'add_ons' => [],
            'product' => [
                '_id' => $productId,
                'name' => $name,
                'type' => 'standard',
                'status' => 1,
                'visibility' => 'Public',
                'categories' => [],
                'condition_treated' => [],
                'description_long' => null,
                'description_short' => null,
                'image' => null,
                'labtest' => [],
                'max_buy_qty' => null,
                'min_buy_qty' => null,
                'restrict_multiple' => false,
                'single' => [['default_price' => 10]],
                'teleforms' => [],
                'variants' => [],
            ],
            'product_mappings' => [],
        ];
    }

    public function testTwoTopLevelProductsCollidingOnSlugThrow(): void
    {
        // Same 6-char id prefix + same slugified name -> same slug from two
        // genuinely different EMR products. Silently overwriting one would
        // be catalog data loss, so this must fail loudly and name both ids.
        $data = [
            '_id' => 'chan01',
            'name' => 'Synthetic Channel',
            'products' => [
                self::minimalEntry('entryA', 'coll01xxxxxxxxxxxxxxxxxxxxA', 'Collision Widget'),
                self::minimalEntry('entryB', 'coll01yyyyyyyyyyyyyyyyyyyyB', 'Collision Widget'),
            ],
        ];

        try {
            CatalogBuilder::build($data);
            self::fail('Expected RuntimeException for colliding slug was not thrown.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('coll01xxxxxxxxxxxxxxxxxxxxA', $e->getMessage());
            self::assertStringContainsString('coll01yyyyyyyyyyyyyyyyyyyyB', $e->getMessage());
            self::assertStringContainsString('coll01-collision-widget', $e->getMessage());
        }
    }

    public function testSkippedParentsLabtestAndAddOnDoNotAppear(): void
    {
        // Rule 11 negative case: when the PARENT fails the status/visibility
        // gate, its nested labtest/add_ons must not leak into the catalog
        // either — they are only kept when reached via a kept parent.
        $entry = self::minimalEntry('entry01', 'skipped01xxxxxxxxxxxxxxxxx', 'Skipped Parent');
        $entry['product']['status'] = 0; // fails the gate
        $entry['product']['labtest'] = [[
            '_id' => 'orphanlabxxxxxxxxxxxxxxxxx',
            'name' => 'Orphan Lab',
            'price' => 50,
            'status' => 1,
            'variants' => [],
        ]];
        $entry['add_ons'] = [[
            '_id' => 'orphanaddonxxxxxxxxxxxxxxx',
            'name' => 'Orphan Addon',
            'status' => 1,
            'single' => [['default_price' => 5]],
            'variants' => [],
        ]];

        $data = [
            '_id' => 'chan01',
            'name' => 'Synthetic Channel',
            'products' => [$entry],
        ];

        $catalog = CatalogBuilder::build($data);

        self::assertSame([], $catalog['products']);
        self::assertArrayNotHasKey('orphan-orphan-lab', $catalog['products']);
        self::assertArrayNotHasKey('orphan-orphan-addon', $catalog['products']);
    }

    public function testFractionalDollarPricesRoundCorrectlyBeforeCastToCents(): void
    {
        $entry = self::minimalEntry('entry01', 'fract01xxxxxxxxxxxxxxxxxxx', 'Fractional Widget');
        $entry['product']['variants'] = [
            ['_id' => 'variantA', 'name' => 'A', 'default_price' => 12.99],
            ['_id' => 'variantB', 'name' => 'B', 'default_price' => 0.29],
        ];

        $data = [
            '_id' => 'chan01',
            'name' => 'Synthetic Channel',
            'products' => [$entry],
        ];

        $catalog = CatalogBuilder::build($data);
        $variants = $catalog['products']['fract0-fractional-widget']['variants'];

        self::assertSame(1299, $variants[0]['price_cents']);
        self::assertSame(29, $variants[1]['price_cents']);
    }
}
