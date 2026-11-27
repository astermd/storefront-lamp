<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Emr;

use AsterMD\Sdk\Enum\PageType;

/**
 * One page a visitor opened, as the EMR's `page_view` event describes it.
 *
 * `type` and `path` are required by the API and always present; the rest
 * are left out of the request when unknown rather than sent blank, because a
 * blank `product_id` is a claim about a product that does not exist.
 *
 * A `product_id` that is not a 24-hex object id is left out too: the EMR
 * refuses the whole event with a 400 for it, so a catalog line carrying a
 * placeholder id would otherwise cost the page view itself.
 */
final readonly class PageView
{
    private const string OBJECT_ID = '/^[a-f\d]{24}$/i';

    public function __construct(
        public PageType $type,
        public string $path,
        public ?string $url = null,
        public ?string $title = null,
        public ?string $referrer = null,
        public ?string $productId = null,
    ) {
    }

    /** @return array{url?: string, title?: string, referrer?: string, product_id?: string} */
    public function details(): array
    {
        return array_filter([
            'url' => $this->url,
            'title' => $this->title,
            'referrer' => $this->referrer,
            'product_id' => $this->productId !== null && preg_match(self::OBJECT_ID, $this->productId) === 1
                ? $this->productId
                : null,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }
}
