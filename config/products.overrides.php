<?php

/**
 * Your customisations on top of config/products.generated.php (which
 * `bin/console theme:sync` overwrites wholesale — this file never is).
 * AsterMD\Storefront\Catalog\CatalogProvider merges this over the generated
 * catalog at runtime via CatalogMerger; nothing here touches the generated
 * file, so re-syncing never loses an override.
 *
 * Shape: ['products' => ['<emr_product_id>' => [ ...fields... ]]].
 *
 * - Products are matched by `emr_product_id` — the EMR's own id for the
 *   product, found in the generated catalog's `emr_product_id` field.
 *   NEVER key an override by slug or name; those can change on re-sync,
 *   the EMR id doesn't.
 * - Any field you set (other than `variants`) replaces the generated
 *   field wholesale — e.g. overriding `categories` replaces the whole
 *   list, it doesn't merge entries into it.
 * - `variants` is special: key it by variant id, and only the fields you
 *   set there are merged into that one variant. Variants you don't
 *   mention are left exactly as generated.
 * - Setting `slug` re-keys the product to the new slug (and updates its
 *   `slug` field to match) — every other product is unaffected.
 * - An id that doesn't match any generated product is ignored unless the
 *   override is itself a full product definition (at minimum `slug`,
 *   `name`, `kind`, and `variants`), in which case it's added as a
 *   brand-new product.
 * - CAUTION: never re-use an existing product's slug in a `slug` re-key or
 *   in a net-new product definition — sync will fail loudly (a thrown
 *   exception naming both colliding products), not silently overwrite one.
 * - `geo_blocks`: a list of two-letter territory codes this product cannot
 *   ship to. The EMR channel payload carries no territory restrictions, so
 *   this is an override-layer field; absent means the product blocks nothing.
 *   The cart refuses to add a blocked product once a shipping territory is
 *   known; once checkout sets one, every line is meant to be re-checked
 *   against it the same way.
 * - `requires_prequalification`: `true` gives this product a dedicated
 *   eligibility step before intake. The default is false — pre-qualification
 *   questions are otherwise folded into the intake form itself.
 * - `prequalification_teleform_id`: which questionnaire that dedicated
 *   eligibility step should collect. Only consulted when
 *   `requires_prequalification` is true. Absent means there is no separate
 *   pre-qualification form, so the step has nothing to ask and the visitor
 *   goes straight to intake — which is the common case, since most channels
 *   author one combined intake form rather than two.
 *
 * Example (commented out):
 *
 * return [
 *     'products' => [
 *         // Override one field and re-key the slug for an existing product:
 *         '6a1c268d5f315cee0e41c325' => [
 *             'price_cents' => 4999,
 *             'slug' => 'semaglutide-injectable',
 *         ],
 *         // Attach a payment-provider mapping to one variant, leaving its
 *         // siblings and every other field untouched:
 *         '6a1c28e2f315cee0e41c330' => [
 *             'variants' => [
 *                 '6a1c28e2f315cee0e41c331' => [
 *                     'provider' => ['offer_id' => 'off_123', 'product_id' => 'prod_456'],
 *                 ],
 *             ],
 *         ],
 *     ],
 * ];
 */
return ['products' => [
    // Sample `lab` product (net-new, per the "full product definition" rule
    // above): demonstrates a hand-added lab/free-addon override per spec
    // [2.18] ("Provider identifiers that the channel cannot supply — notably
    // for lab and free-addon products — must be hand-added to the override
    // layer"), and gives the listing-filter behaviour ([6.1]: lab/free-addon
    // products are never listed) something real to exclude on /treatments/.
    'sample-lab-cmp' => [
        'slug' => 'comprehensive-metabolic-panel',
        'name' => 'Comprehensive Metabolic Panel',
        'kind' => 'lab',
        'categories' => [],
        'price_cents' => 8900,
        'variants' => [
            // No 'provider' yet — CatalogValidator surfaces that as the
            // documented [2.18] warning ("needs provider identifiers in the
            // override layer"), not an error; an empty variants list would
            // be an error instead ("variants list is empty").
            ['id' => 'comprehensive-metabolic-panel-panel', 'name' => 'One-time panel', 'price_cents' => 8900, 'provider' => null],
        ],
    ],
]];
