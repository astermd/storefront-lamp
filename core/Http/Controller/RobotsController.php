<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Controller;

use AsterMD\Storefront\Seo\MetaResolver;
use AsterMD\Storefront\Seo\RobotsPolicy;
use AsterMD\Storefront\Support\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `robots.txt`, generated from the same configuration the meta tags and the
 * sitemap read (`[24.4]`).
 *
 * Two rules shape the whole file.
 *
 * `[24.7]` — under `discourage_indexing` this is a blanket `Disallow: /` for
 * every agent and nothing else, because the master switch has to be able to
 * stop a staging deployment leaking regardless of any per-route rule. The
 * sitemap is still linked and still served; it is simply empty, so the two
 * documents cannot tell a crawler different stories.
 *
 * `[24.13]` — whether answer engines may ingest the catalog is a **commercial
 * decision, not a technical default**, so the AI crawler policy is
 * configuration rather than a shipped opinion. The named agents are listed
 * explicitly instead of being folded into `*`, because a client who disallows
 * them still wants ordinary search crawlers, and a wildcard cannot express
 * that.
 */
final class RobotsController
{
    public function __construct(
        private readonly Config $config,
        private readonly MetaResolver $meta,
        private readonly RobotsPolicy $robots,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write($this->render());

        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    private function render(): string
    {
        $lines = [];

        if ($this->robots->indexingDiscouraged()) {
            $lines[] = 'User-agent: *';
            $lines[] = 'Disallow: /';
        } else {
            $lines[] = 'User-agent: *';

            $refused = $this->refusedPaths();
            foreach ($refused as $path) {
                $lines[] = 'Disallow: ' . $path;
            }

            // A `User-agent` group with no directive under it is an
            // incomplete record, and a deployment that fences nothing off is
            // an ordinary one — so the permissive case is stated rather than
            // left as a bare group a parser has to guess at.
            if ($refused === []) {
                $lines[] = 'Allow: /';
            }

            $lines = array_merge($lines, $this->aiCrawlerLines());
        }

        $lines[] = '';
        $lines[] = 'Sitemap: ' . $this->meta->canonical('/') . 'sitemap.xml';

        return implode("\n", $lines) . "\n";
    }

    /**
     * The paths crawlers are asked to stay out of, taken from the same rules
     * that produce the `noindex` directives — so a funnel step is excluded
     * here for the same reason and at the same time as it is excluded from
     * the sitemap.
     *
     * **Having a rule is not the same as being refused**, which is why each
     * candidate is put back through {@see RobotsPolicy::refusesIndexing()}
     * rather than being disallowed for merely appearing in the rules; that
     * method says why the distinction matters. This file used to classify the
     * directive text itself, which made it a second reader of a decision the
     * policy owns — and the sitemap, reading it the other way, would have
     * dropped a path this file left crawlable. Routing the question through
     * the policy also means the most specific rule wins here exactly as it
     * does everywhere else.
     *
     * A trailing `*` is dropped because `robots.txt` paths are already
     * prefixes: `Disallow: /checkout/` covers everything beneath it. That
     * makes this file slightly stricter than the per-page directive for an
     * exact-path rule, which is the safe direction to differ in.
     *
     * @return list<string>
     */
    private function refusedPaths(): array
    {
        /** @var array<string, string> $rules */
        $rules = (array) $this->config->get('app.seo.robots_rules', []);

        $paths = [];
        foreach (array_keys($rules) as $pattern) {
            // A rule with no path to match is skipped here for the same
            // reason {@see RobotsPolicy} skips it: an integer key is what a
            // `robots_rules` written as a list produces, and coercing it to
            // `"0"` would put a `Disallow: 0` in front of crawlers instead of
            // the fence the deployment meant to write.
            if (!is_string($pattern)) {
                continue;
            }

            $path = str_ends_with($pattern, '*') ? substr($pattern, 0, -1) : $pattern;

            if ($this->robots->refusesIndexing($path)) {
                $paths[] = $path;
            }
        }

        sort($paths);

        return array_values(array_unique($paths));
    }

    /**
     * `[24.13]`. An allowing deployment emits nothing — silence is already
     * permission, and a redundant `Allow` for every named agent is noise that
     * has to stay correct as the list of agents changes.
     *
     * @return list<string>
     */
    private function aiCrawlerLines(): array
    {
        if ((bool) $this->config->get('app.seo.ai_crawlers.allow', true)) {
            return [];
        }

        $lines = [];
        foreach ((array) $this->config->get('app.seo.ai_crawlers.agents', []) as $agent) {
            $agent = trim((string) $agent);
            if ($agent === '') {
                continue;
            }

            $lines[] = '';
            $lines[] = 'User-agent: ' . $agent;
            $lines[] = 'Disallow: /';
        }

        return $lines;
    }
}
