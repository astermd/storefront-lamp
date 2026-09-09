<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Controller;

use AsterMD\Storefront\Catalog\CatalogProvider;
use AsterMD\Storefront\Seo\MetaResolver;
use AsterMD\Storefront\Seo\RobotsPolicy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `sitemap.xml` — every indexable route and every catalog product (`[24.4]`).
 *
 * **What is not in it is the part worth reading.** `[24.6]` excludes the
 * funnel — cart, intake, checkout, upsell, verify, receipt — and this class
 * does not carry its own list of exclusions to achieve that. It asks
 * {@see RobotsPolicy}, the same object that decides whether the page emits
 * `noindex`. Two lists would drift, and the failure mode when they do is
 * silent and bad: a checkout page advertised in a sitemap while claiming to
 * be unindexable. Adding a funnel step later means adding one robots rule,
 * not two entries in two files.
 *
 * `[24.7]`'s master switch is honoured here too. Under
 * `discourage_indexing` the document is still served — a 404 would tell a
 * crawler something different from what `robots.txt` is telling it, which
 * still announces this URL — but it lists nothing, because no path is
 * indexable. An empty `<urlset>` is the weaker of two imperfect answers: the
 * sitemap schema wants at least one entry, while a 404 behind a live
 * `Sitemap:` line is a contradiction a crawler will report. `[24.7]` asks for
 * the two documents to agree, so they agree.
 */
final class SitemapController
{
    /**
     * Static routes that are candidates for the sitemap. Funnel steps are
     * absent by construction as well as by policy: a path that {@see RobotsPolicy}
     * refuses never reaches the document, but listing only the marketing
     * surface here means a new funnel route cannot be added to the sitemap by
     * forgetting a rule.
     */
    private const array STATIC_PATHS = [
        '/',
        '/treatments/',
        '/terms/',
        '/privacy/',
        '/telehealth-consent/',
    ];

    public function __construct(
        private readonly CatalogProvider $catalog,
        private readonly MetaResolver $meta,
        private readonly RobotsPolicy $robots,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->render($this->paths());
        $response->getBody()->write($body);

        return $response->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }

    /**
     * Every path the document advertises, in a stable order.
     *
     * @return list<string>
     */
    private function paths(): array
    {
        $paths = self::STATIC_PATHS;

        foreach ($this->catalog->listedProducts() as $product) {
            $slug = (string) ($product['slug'] ?? '');
            // A slug that is not valid UTF-8 has no URL that can be written
            // down here. It arrives through a catalog re-sync, so nothing in
            // this repository chose those bytes, and there is no repair worth
            // making: substituting the unreadable bytes produces a URL that
            // resolves to nothing, so the entry would point a crawl at a 404
            // while claiming to be the product's canonical address. Dropping
            // it leaves the product to be found the way every unadvertised
            // page is, through a link.
            if ($slug !== '' && mb_check_encoding($slug, 'UTF-8')) {
                $paths[] = '/products/' . $slug . '/';
            }
        }

        return array_values(array_filter(
            $paths,
            fn (string $path): bool => $this->robots->isIndexable($path),
        ));
    }

    /**
     * The document. `<loc>` and nothing else, deliberately.
     *
     * Nothing in this application records when a page's content last
     * changed — a catalog re-sync rewrites every product whether or not
     * anything about it moved — so a `<lastmod>` here could only be
     * fabricated, and a sitemap claiming every page changed today is one a
     * crawler learns to disregard. An absent timestamp is read as "unknown",
     * which is the truth. `<changefreq>` and `<priority>` are hints the major
     * crawlers ignore outright.
     *
     * @param list<string> $paths
     */
    private function render(array $paths): string
    {
        $lines = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];

        foreach ($paths as $path) {
            // `ENT_SUBSTITUTE` guarantees the invariant rather than handling
            // a case: without it `htmlspecialchars()` answers invalid UTF-8
            // with the empty string, and an empty `<loc>` leaves the document
            // schema-invalid while staying perfectly well-formed — so a
            // crawler may discard the whole file and no parser here would say
            // why. {@see self::paths()} already keeps such a path out; this is
            // what stops a future one slipping past it from breaking the
            // document, which `[20.1]` does not allow.
            $encoded = htmlspecialchars(
                $this->meta->canonical($path),
                ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
            );

            $lines[] = '  <url><loc>' . $encoded . '</loc></url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines) . "\n";
    }
}
