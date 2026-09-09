<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Catalog;

use AsterMD\Storefront\Catalog\CatalogProvider;
use AsterMD\Storefront\Catalog\CatalogValidator;
use AsterMD\Storefront\Tests\Support\ConfigVariant;
use AsterMD\Storefront\Tests\Support\ShippedCatalog;
use PHPUnit\Framework\TestCase;

/**
 * The validator, run over the configuration this repository actually ships.
 *
 * It closes a gap that let a real defect through: the sample `lab` product in
 * `config/products.overrides.php` once shipped with an empty `variants` list,
 * which {@see CatalogValidator} treats as an error. Every other validator case
 * runs against a synthetic fixture, so `bin/console config:validate` exited 2
 * on a clean checkout while nothing here went red.
 *
 * It merges the shipped catalog and the shipped override file exactly as
 * {@see CatalogProvider} does at runtime and asserts zero errors. Warnings are
 * expected and allowed — the sample lab variant deliberately produces the
 * missing-provider-identifier warning of `[2.18]`.
 *
 * The catalog arrives through {@see ShippedCatalog} rather than from the
 * config directory alone, because `config/products.generated.php` is
 * gitignored: on a clone the directory holds only the example, and validating
 * the override layer against an absent catalog would assert far less than this
 * reads as asserting.
 */
final class ShippedConfigValidationTest extends TestCase
{
    use ConfigVariant;

    public function testShippedConfigHasNoValidationErrors(): void
    {
        $config = $this->configWith(['products.generated' => ShippedCatalog::catalog()]);
        $provider = new CatalogProvider($config);

        $result = (new CatalogValidator())->validate($provider->catalog());

        self::assertSame([], $result['errors']);
    }

    /**
     * The precondition the case above depends on: an empty catalog would
     * satisfy it for the wrong reason, since a product that is not there
     * cannot be invalid.
     */
    public function testTheShippedCatalogIsNotEmpty(): void
    {
        $config = $this->configWith(['products.generated' => ShippedCatalog::catalog()]);

        self::assertNotSame([], (new CatalogProvider($config))->products());
    }
}
