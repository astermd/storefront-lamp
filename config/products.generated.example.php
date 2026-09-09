<?php

/**
 * Example of config/products.generated.php — the real file is what
 * `bin/console theme:sync --apply` writes from your live EMR channel, and it
 * is gitignored (it carries channel-specific product ids, prices and payment
 * provider identifiers). This example is committed so the shape is documented
 * without putting one deployment's catalog in every deployment's checkout.
 *
 * It is a *shape*, not a store. The ids below resolve to nothing in any EMR
 * and the variants carry no provider identifiers, so a clone renders a catalog
 * it cannot take an order for. Run `theme:sync --apply` to replace it with
 * yours; `bin/console config:validate` and `catalog:validate` both report an
 * unsynced deployment.
 *
 * Overrides belong in `config/products.overrides.php`, which `theme:sync`
 * never writes — see that file's own header for the merge rules.
 *
 * Two things about this file are load-bearing for the test suite, which reads
 * it whenever the real catalog is absent (`tests/Support/ShippedCatalog.php`):
 * every `description` is `''`, so the meta-description fallback has something
 * to fall back from, and at least one product is a listed `kind`, so the
 * sitemap and `/treatments/` have something to list.
 */

return [
    'channel' => [
        'id' => 'REPLACE_ME_CHANNEL_ID',
        'name' => 'Example Channel',
        'currency' => 'USD',
    ],
    'products' => [
        'example-monthly-therapy' => [
            'slug' => 'example-monthly-therapy',
            'name' => 'Example Monthly Therapy',
            'subtitle' => null,
            'kind' => 'rx',
            'categories' => [
                'Example Category',
            ],
            'condition_treated' => [],
            // Blank on purpose: the EMR channel supplies no product copy, so
            // the storefront falls through to `app.seo.description`. Real
            // copy belongs in the override layer.
            'description' => '',
            'price_cents' => 5000,
            'price_unit' => '/ month',
            // `public/assets/media/` is gitignored — the images are fetched by
            // `theme:sync --apply`, so these two paths 404 until you sync.
            'image' => '/assets/media/example-monthly-therapy.jpg',
            'gallery' => [
                '/assets/media/example-monthly-therapy.jpg',
            ],
            'badges' => [],
            'emr_product_id' => 'REPLACE_ME_PRODUCT_ID',
            'remote_image' => null,
            'teleform_id' => 'REPLACE_ME_TELEFORM_ID',
            'max_buy_qty' => null,
            'min_buy_qty' => null,
            'restrict_multiple' => false,
            'variants' => [
                [
                    'id' => 'REPLACE_ME_VARIANT_ID_1',
                    'name' => '1 Month',
                    'price_cents' => 5000,
                    // `null` rather than invented ids: `catalog:validate`
                    // reports this as the `[2.18]` warning ("needs provider
                    // identifiers in the override layer"), which is the truth
                    // about an unsynced catalog. A placeholder `offer_id`
                    // would instead claim a charge could be raised against it.
                    'provider' => null,
                ],
                [
                    'id' => 'REPLACE_ME_VARIANT_ID_2',
                    'name' => '3 Months',
                    'price_cents' => 13500,
                    'provider' => null,
                ],
            ],
            'bundles' => [],
            'attachments' => [],
        ],
        'example-one-time-treatment' => [
            'slug' => 'example-one-time-treatment',
            'name' => 'Example One-Time Treatment',
            'subtitle' => null,
            'kind' => 'otc',
            'categories' => [
                'Example Category',
            ],
            'condition_treated' => [],
            'description' => '',
            'price_cents' => 2500,
            'price_unit' => null,
            'image' => '/assets/media/example-one-time-treatment.jpg',
            'gallery' => [
                '/assets/media/example-one-time-treatment.jpg',
            ],
            'badges' => [],
            'emr_product_id' => 'REPLACE_ME_PRODUCT_ID_2',
            'remote_image' => null,
            'teleform_id' => null,
            'max_buy_qty' => null,
            'min_buy_qty' => null,
            'restrict_multiple' => true,
            'variants' => [
                [
                    'id' => 'REPLACE_ME_VARIANT_ID_3',
                    'name' => 'One-time',
                    'price_cents' => 2500,
                    'provider' => null,
                ],
            ],
            'bundles' => [],
            'attachments' => [],
        ],
    ],
];
