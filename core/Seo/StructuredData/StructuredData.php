<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Seo\StructuredData;

use AsterMD\Storefront\Catalog\CatalogProvider;
use AsterMD\Storefront\Seo\MetaResolver;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Support\Url;

/**
 * Assembles the schema.org graph a given path publishes, and validates the
 * whole set at deploy time (`[24.8]`, `[24.11]`).
 *
 * Every emitter is independently switchable in `app.seo.structured_data`,
 * because what is publishable differs by client and by territory — and each
 * toggle defaults to **off** when the key is absent, so a deployment that has
 * not configured structured data publishes none rather than publishing
 * whatever the catalog happens to contain.
 *
 * The graph is assembled **from the path**, the same input
 * {@see \AsterMD\Storefront\Seo\RobotsPolicy} decides indexing from, so the
 * two answers are derived from one fact rather than from two hand-kept lists.
 * It is deliberately *not* gated on indexability: `[24.7]`'s master switch is
 * about what a crawler may index, not about what the page says about itself,
 * and gating one on the other would mean a staging deployment rendered
 * structurally different HTML from the production one it is meant to
 * rehearse.
 *
 * `search_url_template` names the endpoint {@see WebSite}'s search action
 * submits to. It is configuration rather than a constant because whether a
 * deployment has a query-aware listing endpoint is a property of that
 * deployment; naming nothing suppresses the action.
 */
final class StructuredData
{
    /** Where a path with no more specific trail starts (`[24.8]`'s breadcrumb trails). */
    private const string LISTING_PATH = '/treatments/';

    private const string LISTING_LABEL = 'Treatments';

    private const string ROOT_LABEL = 'Home';

    /** Read when the deployment names no search endpoint of its own. */
    private const string DEFAULT_SEARCH_TEMPLATE = '/treatments/?q=' . WebSite::QUERY_PLACEHOLDER;

    public function __construct(
        private readonly Config $config,
        private readonly CatalogProvider $catalog,
        private readonly MetaResolver $meta,
    ) {
    }

    /**
     * The resolution chain lives in {@see MetaResolver}, so one is built here
     * rather than reimplemented — a description that falls through
     * differently in structured data than in the meta tag would publish two
     * answers to one question.
     */
    public static function fromConfig(Config $config, CatalogProvider $catalog): self
    {
        return new self($config, $catalog, new MetaResolver($config));
    }

    /**
     * Every node $path publishes, in graph order.
     *
     * @return list<array<string, mixed>>
     */
    public function nodesFor(string $path): array
    {
        $path = Url::canonicalizePath($path);

        $nodes = [];
        foreach ($this->emittersFor($path) as $emitter) {
            $node = $emitter->emit();
            if ($node !== null) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * The `application/ld+json` document for $path, or **null when there is
     * nothing to publish**.
     *
     * Null rather than an empty graph so the template can omit the
     * `<script>` element entirely: an empty `application/ld+json` block is a
     * parse error to some consumers and noise to the rest.
     *
     * `<` and `&` are escaped so no catalog string can close the script
     * element it is embedded in, which is why the encoding happens here and
     * not in the template.
     */
    public function json(string $path): ?string
    {
        $nodes = $this->nodesFor($path);
        if ($nodes === []) {
            return null;
        }

        $json = json_encode(
            ['@context' => 'https://schema.org', '@graph' => $nodes],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP,
        );

        return $json === false ? null : $json;
    }

    /**
     * Everything wrong with what this deployment would publish (`[24.11]`).
     *
     * Every path that can carry structured data is assembled and inspected —
     * the site-level nodes once, and each listed product's page — so a
     * malformed emission is an error at deploy time rather than a search
     * console surprise weeks later. The catalog is the input that changes
     * without anyone editing this repository, which is exactly why the check
     * runs against the merged catalog rather than against a fixture.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        foreach ($this->pathsToInspect() as $path) {
            foreach ($this->nodesFor($path) as $node) {
                foreach (self::inspect($node) as $problem) {
                    $problems[] = $path . ': ' . $problem;
                }
            }

            if ($this->json($path) === null && $this->nodesFor($path) !== []) {
                $problems[] = $path . ': the graph cannot be encoded as JSON — a catalog value is not valid UTF-8';
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * The site root plus every listed product page, which between them cover
     * every emitter this deployment can reach. Lab and free-addon products
     * are never listing surfaces (`[6.1]`) and have no page of their own to
     * publish.
     *
     * @return list<string>
     */
    private function pathsToInspect(): array
    {
        $paths = ['/', self::LISTING_PATH];

        foreach ($this->catalog->listedProducts() as $product) {
            $slug = trim((string) ($product['slug'] ?? ''));
            if ($slug !== '') {
                $paths[] = '/products/' . $slug . '/';
            }
        }

        return $paths;
    }

    /**
     * @return list<Emitter>
     */
    private function emittersFor(string $path): array
    {
        $base = $this->baseUrl();
        $organisation = $this->enabled('organisation');

        $emitters = [];

        if ($organisation) {
            $emitters[] = new Organisation($base, $this->meta->siteName(), $this->meta->description());
        }

        if ($this->enabled('website')) {
            $emitters[] = new WebSite($base, $this->meta->siteName(), $this->searchUrlTemplate(), $organisation);
        }

        $product = $this->productFor($path);
        if ($product === null) {
            if ($path === self::LISTING_PATH && $this->enabled('breadcrumbs')) {
                $emitters[] = new BreadcrumbList($this->trail());
            }

            return $emitters;
        }

        $node = $this->productEmitter($product, $path);

        // `[24.10]`: when a prescription product publishes nothing, its
        // breadcrumb trail publishes nothing either. The last crumb is the
        // product's own name and URL, so emitting the trail would restate in
        // one node exactly what the omission of the other withheld.
        if ($node->emit() === null) {
            return $emitters;
        }

        if ($this->enabled('product')) {
            $emitters[] = $node;
        }

        if ($this->enabled('breadcrumbs')) {
            $emitters[] = new BreadcrumbList([
                ...$this->trail(),
                ['name' => trim((string) ($product['name'] ?? '')), 'url' => $this->meta->canonical($path)],
            ]);
        }

        return $emitters;
    }

    /** @param array<string, mixed> $product */
    private function productEmitter(array $product, string $path): Product
    {
        return new Product(
            $product,
            $this->meta->canonical($path),
            $this->baseUrl(),
            $this->meta->description(is_string($product['description'] ?? null) ? $product['description'] : null),
            $this->meta->siteName(),
            $this->currency(),
            $this->enabled('rx'),
        );
    }

    /** @return list<array{name: string, url: string}> */
    private function trail(): array
    {
        return [
            ['name' => self::ROOT_LABEL, 'url' => $this->baseUrl() . '/'],
            ['name' => self::LISTING_LABEL, 'url' => $this->meta->canonical(self::LISTING_PATH)],
        ];
    }

    /** @return array<string, mixed>|null the catalog product $path is the detail page of */
    private function productFor(string $path): ?array
    {
        if (preg_match('#^/products/([^/]+)/$#', $path, $matches) !== 1) {
            return null;
        }

        return $this->catalog->product($matches[1]);
    }

    /**
     * The catalog channel's currency, or null when it names none.
     *
     * Null is propagated rather than defaulted: an offer priced in a guessed
     * currency is exactly the disagreement with the checkout that `[24.9]`
     * forbids, so an unconfigured channel publishes no offer and
     * {@see self::problems()} says so at deploy time.
     */
    private function currency(): ?string
    {
        $channel = $this->catalog->catalog()['channel'] ?? null;
        $currency = is_array($channel) ? trim((string) ($channel['currency'] ?? '')) : '';

        return $currency === '' ? null : strtoupper($currency);
    }

    private function searchUrlTemplate(): ?string
    {
        $template = $this->config->get('app.seo.search_url_template', self::DEFAULT_SEARCH_TEMPLATE);

        return is_string($template) && trim($template) !== '' ? trim($template) : null;
    }

    private function baseUrl(): string
    {
        return rtrim((string) $this->config->get('app.url', ''), '/');
    }

    /** Absent means off: an unconfigured deployment publishes nothing. */
    private function enabled(string $emitter): bool
    {
        return $this->config->get('app.seo.structured_data.' . $emitter, false) === true;
    }

    /**
     * What is wrong with one node, in the terms a deploy-time operator can
     * act on.
     *
     * The checks are the ones whose failure is silent everywhere else: a
     * blank name, a relative URL (which means `app.url` is unset and every
     * emitted URL is meaningless), a price that is not the decimal form
     * schema.org requires, a currency that is not an ISO code, and an
     * availability outside the vocabulary. Each of them renders a node that
     * looks fine in the page source and is rejected or misread downstream.
     *
     * @param array<string, mixed> $node
     *
     * @return list<string>
     */
    private static function inspect(array $node): array
    {
        $type = trim((string) ($node['@type'] ?? ''));
        if ($type === '') {
            return ['a node has no @type'];
        }

        $problems = [];

        foreach (['name', 'url', '@id'] as $key) {
            if (array_key_exists($key, $node) && trim((string) $node[$key]) === '') {
                $problems[] = sprintf('%s has a blank %s', $type, $key);
            }
        }

        foreach (['url', '@id'] as $key) {
            $value = (string) ($node[$key] ?? '');
            if ($value !== '' && !preg_match('#^https?://#', $value)) {
                $problems[] = sprintf('%s.%s is not an absolute URL (%s) — app.url names no host', $type, $key, $value);
            }
        }

        $offer = $node['offers'] ?? null;
        if (is_array($offer)) {
            $problems = [...$problems, ...self::inspectOffer($type, $offer)];
        } elseif ($type === 'Product') {
            // A published `Product` with no `offers` is a listing with no
            // price, which is the shape `[24.9]` is about: the page quotes an
            // amount and the structured data withholds it. The only way to
            // reach it is a catalog channel that names no currency.
            $problems[] = 'Product publishes no offer — the catalog channel names no currency, so no price could be stated in one';
        }

        return $problems;
    }

    /**
     * @param array<string, mixed> $offer
     *
     * @return list<string>
     */
    private static function inspectOffer(string $type, array $offer): array
    {
        $problems = [];

        $price = (string) ($offer['price'] ?? '');
        if (preg_match('/^\d+\.\d{2}$/', $price) !== 1) {
            $problems[] = sprintf('%s offer price "%s" is not a decimal amount', $type, $price);
        } elseif ((float) $price <= 0.0) {
            $problems[] = sprintf('%s is offered at %s — a listed product priced at nothing is published as free', $type, $price);
        }

        $currency = (string) ($offer['priceCurrency'] ?? '');
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $problems[] = sprintf('%s offer currency "%s" is not an ISO 4217 code — the catalog channel names none', $type, $currency);
        }

        $availability = (string) ($offer['availability'] ?? '');
        if (!in_array($availability, [Product::IN_STOCK, Product::OUT_OF_STOCK], true)) {
            $problems[] = sprintf('%s offer availability "%s" is not a schema.org availability', $type, $availability);
        }

        return $problems;
    }
}
