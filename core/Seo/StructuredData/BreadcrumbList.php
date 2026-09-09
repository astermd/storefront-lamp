<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Seo\StructuredData;

/**
 * The trail from the storefront root to the current page, as schema.org
 * `BreadcrumbList` (`[24.8]`).
 *
 * The trail is derived from the URL contract (§23) rather than from a
 * hand-kept table, so a page whose path changes cannot keep advertising the
 * old trail. A trail of one crumb is not emitted at all: "Home" on the home
 * page is a breadcrumb list with nothing to navigate, and publishing it says
 * nothing a crawler did not already know from the URL.
 */
final class BreadcrumbList implements Emitter
{
    /** @param list<array{name: string, url: string}> $crumbs root first, current page last */
    public function __construct(private readonly array $crumbs)
    {
    }

    public function emit(): ?array
    {
        if (count($this->crumbs) < 2) {
            return null;
        }

        $items = [];
        foreach (array_values($this->crumbs) as $index => $crumb) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $crumb['name'],
                'item' => $crumb['url'],
            ];
        }

        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }
}
