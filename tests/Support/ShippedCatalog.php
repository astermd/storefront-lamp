<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

/**
 * The catalog this checkout actually ships: `config/products.generated.php`
 * when the deployment has been synced, and `config/products.generated.example.php`
 * when it has not.
 *
 * The generated file is gitignored — it is one channel's live catalog, written
 * by `theme:sync --apply` — so a fresh clone does not have it, and four test
 * classes read the shipped catalog by path to assert things about whatever
 * this deployment sells. Reading it directly made those cases pass or fail on
 * whether someone had run a sync, which is not what any of them are about.
 *
 * The example is a legitimate stand-in here rather than a fixture of
 * convenience: both files are written by the same generator to the same shape,
 * and the properties these cases assert — every product has a blank
 * description, at least one is a listed kind, none of it produces a validation
 * error — hold of both by construction. A case that needs a *stated* catalog
 * still injects one ({@see SampleCatalog}); this is for the cases whose whole
 * subject is the shipped one.
 */
final class ShippedCatalog
{
    public static function path(): string
    {
        $generated = dirname(__DIR__, 2) . '/config/products.generated.php';

        return is_file($generated)
            ? $generated
            : dirname(__DIR__, 2) . '/config/products.generated.example.php';
    }

    /** @return array{channel?: array<string, mixed>, products: array<string, array<string, mixed>>} */
    public static function catalog(): array
    {
        /** @var array{products: array<string, array<string, mixed>>} $catalog */
        $catalog = require self::path();

        return $catalog;
    }

    /**
     * The first product's slug, for the cases that need one real product page
     * to walk. Whichever file supplied it, it is a product this deployment
     * would actually route to.
     */
    public static function firstSlug(): string
    {
        $products = self::catalog()['products'];
        $first = reset($products);

        if ($first === false || !is_string($first['slug'] ?? null) || $first['slug'] === '') {
            throw new \RuntimeException('the shipped catalog at ' . self::path() . ' has no usable product');
        }

        return $first['slug'];
    }
}
