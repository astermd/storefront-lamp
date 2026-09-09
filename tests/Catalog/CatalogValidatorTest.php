<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Catalog;

use AsterMD\Storefront\Catalog\CatalogBuilder;
use AsterMD\Storefront\Catalog\CatalogValidator;
use AsterMD\Storefront\Tests\Support\ShippedCatalog;
use PHPUnit\Framework\TestCase;

final class CatalogValidatorTest extends TestCase
{
    private function validator(): CatalogValidator
    {
        return new CatalogValidator();
    }

    /** @return array<string, mixed> a minimal, fully valid single-product catalog */
    private static function validCatalog(): array
    {
        return [
            'channel' => ['id' => 'chan-1', 'name' => 'Flow 1', 'currency' => 'USD'],
            'products' => [
                'abc123-tadalafil' => [
                    'slug' => 'abc123-tadalafil',
                    'name' => 'Tadalafil',
                    'kind' => 'rx',
                    'emr_product_id' => 'abc123',
                    'bundles' => [],
                    'attachments' => [],
                    'variants' => [
                        ['id' => 'v1', 'name' => '10mg', 'price_cents' => 5000, 'provider' => ['offer_id' => 'o1', 'product_id' => 'p1']],
                    ],
                ],
            ],
        ];
    }

    public function testMinimalValidCatalogHasNoErrorsOrWarnings(): void
    {
        $result = $this->validator()->validate(self::validCatalog());

        self::assertSame([], $result['errors']);
        self::assertSame([], $result['warnings']);
    }

    // --- ERRORS -------------------------------------------------------

    public function testMissingSlugIsError(): void
    {
        $catalog = self::validCatalog();
        unset($catalog['products']['abc123-tadalafil']['slug']);

        $result = $this->validator()->validate($catalog);

        self::assertNotEmpty(array_filter($result['errors'], static fn (string $e): bool => str_contains($e, 'slug')));
    }

    public function testBlankNameIsError(): void
    {
        $catalog = self::validCatalog();
        $catalog['products']['abc123-tadalafil']['name'] = '   ';

        $result = $this->validator()->validate($catalog);

        self::assertNotEmpty(array_filter($result['errors'], static fn (string $e): bool => str_contains($e, 'name')));
    }

    public function testWrongTypeKindIsError(): void
    {
        $catalog = self::validCatalog();
        $catalog['products']['abc123-tadalafil']['kind'] = 123;

        $result = $this->validator()->validate($catalog);

        self::assertNotEmpty(array_filter($result['errors'], static fn (string $e): bool => str_contains($e, 'kind')));
    }

    public function testKindNotInAllowedSetIsError(): void
    {
        $catalog = self::validCatalog();
        $catalog['products']['abc123-tadalafil']['kind'] = 'supplement';

        $result = $this->validator()->validate($catalog);

        self::assertSame(
            ['product abc123-tadalafil: kind "supplement" is not one of rx, otc, lab, free-addon'],
            $result['errors'],
        );
    }

    public function testUnresolvableBundleSlugIsError(): void
    {
        $catalog = self::validCatalog();
        $catalog['products']['abc123-tadalafil']['bundles'] = ['no-such-product'];

        $result = $this->validator()->validate($catalog);

        self::assertSame(
            ['product abc123-tadalafil: bundles slug "no-such-product" resolves to no product'],
            $result['errors'],
        );
    }

    public function testUnresolvableAttachmentSlugIsError(): void
    {
        $catalog = self::validCatalog();
        $catalog['products']['abc123-tadalafil']['attachments'] = ['ghost-addon'];

        $result = $this->validator()->validate($catalog);

        self::assertSame(
            ['product abc123-tadalafil: attachments slug "ghost-addon" resolves to no product'],
            $result['errors'],
        );
    }

    public function testResolvableBundleSlugIsNotAnError(): void
    {
        $catalog = self::validCatalog();
        $catalog['products']['abc123-tadalafil']['bundles'] = ['abc123-tadalafil'];

        $result = $this->validator()->validate($catalog);

        self::assertSame([], $result['errors']);
    }

    public function testExplicitEmptyVariantsListIsError(): void
    {
        $catalog = self::validCatalog();
        $catalog['products']['abc123-tadalafil']['variants'] = [];

        $result = $this->validator()->validate($catalog);

        self::assertSame(
            ['product abc123-tadalafil: variants list is empty'],
            $result['errors'],
        );
    }

    public function testVariantsKeyPresentButNotAListIsErrorWithDistinctMessage(): void
    {
        $catalog = self::validCatalog();
        $catalog['products']['abc123-tadalafil']['variants'] = 'not-a-list';

        $result = $this->validator()->validate($catalog);

        self::assertSame(
            ['product abc123-tadalafil: variants must be a list'],
            $result['errors'],
        );
    }

    public function testNegativePriceCentsIsError(): void
    {
        $catalog = self::validCatalog();
        $catalog['products']['abc123-tadalafil']['variants'][0]['price_cents'] = -100;

        $result = $this->validator()->validate($catalog);

        self::assertSame(
            ['product abc123-tadalafil: variant "10mg" price_cents is not a non-negative int'],
            $result['errors'],
        );
    }

    public function testNonIntPriceCentsIsError(): void
    {
        $catalog = self::validCatalog();
        $catalog['products']['abc123-tadalafil']['variants'][0]['price_cents'] = '5000';

        $result = $this->validator()->validate($catalog);

        self::assertSame(
            ['product abc123-tadalafil: variant "10mg" price_cents is not a non-negative int'],
            $result['errors'],
        );
    }

    public function testCurrencyNotThreeUppercaseLettersIsError(): void
    {
        $catalog = self::validCatalog();
        $catalog['channel']['currency'] = 'us';

        $result = $this->validator()->validate($catalog);

        self::assertSame(
            ['channel currency "us" is not a 3-letter uppercase ISO code'],
            $result['errors'],
        );
    }

    public function testMissingCurrencyIsNotAnError(): void
    {
        $catalog = self::validCatalog();
        unset($catalog['channel']['currency']);

        $result = $this->validator()->validate($catalog);

        self::assertSame([], $result['errors']);
    }

    // --- WARNINGS -------------------------------------------------------

    public function testVariantWithNullProviderIsGroupedWarning(): void
    {
        $catalog = self::validCatalog();
        $catalog['products']['abc123-tadalafil']['variants'][0]['provider'] = null;

        $result = $this->validator()->validate($catalog);

        self::assertSame(
            ['product abc123-tadalafil: 1 variant(s) need provider identifiers in the override layer — 10mg'],
            $result['warnings'],
        );
    }

    public function testTwoVariantsWithDifferentPricesIsWarning(): void
    {
        $catalog = self::validCatalog();
        $catalog['products']['abc123-tadalafil']['variants'][] = [
            'id' => 'v2',
            'name' => '20mg',
            'price_cents' => 6000,
            'provider' => ['offer_id' => 'o2', 'product_id' => 'p2'],
        ];

        $result = $this->validator()->validate($catalog);

        self::assertSame(
            ['product abc123-tadalafil: variants differ in price — verify prices vary by plan length only, never by dose (spec [29.3])'],
            $result['warnings'],
        );
    }

    public function testTwoVariantsWithSamePriceIsNotAWarning(): void
    {
        $catalog = self::validCatalog();
        $catalog['products']['abc123-tadalafil']['variants'][] = [
            'id' => 'v2',
            'name' => '20mg',
            'price_cents' => 5000,
            'provider' => ['offer_id' => 'o2', 'product_id' => 'p2'],
        ];

        $result = $this->validator()->validate($catalog);

        self::assertSame([], $result['warnings']);
    }

    public function testMissingVariantsKeyIsToleratedLegacySeedWarning(): void
    {
        $catalog = self::validCatalog();
        unset($catalog['products']['abc123-tadalafil']['variants']);

        $result = $this->validator()->validate($catalog);

        self::assertSame([], $result['errors']);
        self::assertSame(
            ['product abc123-tadalafil: legacy-seed product lacks variants'],
            $result['warnings'],
        );
    }

    public function testChannelKeyAbsentIsWarning(): void
    {
        $catalog = self::validCatalog();
        unset($catalog['channel']);

        $result = $this->validator()->validate($catalog);

        self::assertSame([], $result['errors']);
        self::assertContains('channel key absent', $result['warnings']);
    }

    public function testChannelKeyPresentDoesNotWarn(): void
    {
        $result = $this->validator()->validate(self::validCatalog());

        self::assertNotContains('channel key absent', $result['warnings']);
    }

    // --- Real fixture-built and sample-seed catalogs -------------------

    public function testFixtureBuiltCatalogPassesWithZeroErrorsAndSurfacesLabProviderWarning(): void
    {
        $data = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/channel-details.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        )['data'];

        $catalog = CatalogBuilder::build($data, 'USD');
        $result = $this->validator()->validate($catalog);

        self::assertSame([], $result['errors']);
        self::assertNotEmpty(array_filter(
            $result['warnings'],
            static fn (string $w): bool => str_contains($w, 'at-home-hormone-lab-panel') && str_contains($w, 'provider identifiers'),
        ));
    }

    public function testSampleSeedCatalogPassesWithZeroErrors(): void
    {
        /** @var array<string, mixed> $catalog */
        $catalog = ShippedCatalog::catalog();

        $result = $this->validator()->validate($catalog);

        self::assertSame([], $result['errors']);
    }
}
