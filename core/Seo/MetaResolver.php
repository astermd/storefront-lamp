<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Seo;

use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Support\Url;

/**
 * Builds every page's metadata from configuration and catalog data, so a
 * client gets correct titles, descriptions, canonical links and social cards
 * without writing any code (`[24.4]`).
 *
 * The resolution order is the whole point of the class, and it is the same
 * for every field: **a per-page override, then the page's own data, then the
 * configured default.** `[24.5]` requires the override layer to be a
 * supported path that survives a re-sync, which is why the per-product
 * overrides come from `config/products.overrides.php` and never from the
 * generated catalog.
 *
 * One consequence is load-bearing rather than theoretical: **every product
 * synced from this EMR carries an empty description**, so a chain that stops
 * at the product's own value renders `<meta name="description" content="">`
 * on every product page. An empty string therefore falls *through* to the
 * next source rather than satisfying the chain — absent and blank mean the
 * same thing to a crawler, so they mean the same thing here.
 */
final class MetaResolver
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * The page title, wrapped in the configured template.
     *
     * A page that supplies nothing gets `default_title` **verbatim, without
     * the template**: templating it would render "AsterMD — AsterMD" on the
     * homepage, and a title that repeats the site name is worse than one that
     * simply is the site name.
     *
     * `%s` is substituted rather than formatted, and the difference is the
     * whole reason this is not `sprintf()`. A title template is copy, written
     * by whoever writes the rest of the marketing copy, and copy contains per
     * cent signs — `'%s | 50% off'` is an ordinary thing to type. Read as a
     * format string it is two directives with one argument, which throws, and
     * `base.twig` reaches this method for every page that has a title of its
     * own, so one stray character would take the storefront down. `[20.1]`
     * puts metadata on the side that may never do that. Substitution replaces
     * every `%s`, so a template with two of them repeats the title instead of
     * failing — odd copy is a copy problem, not a 500.
     */
    public function title(?string $pageTitle = null): string
    {
        $pageTitle = self::firstNonBlank($pageTitle);
        $default = (string) $this->config->get('app.seo.default_title', $this->siteName());

        if ($pageTitle === null) {
            return $default;
        }

        $template = (string) $this->config->get('app.seo.title_template', '%s');

        return str_contains($template, '%s') ? str_replace('%s', $pageTitle, $template) : $pageTitle;
    }

    /**
     * The meta description (`[24.4]`).
     *
     * $candidates are tried in order — a per-page override first, then the
     * product's own description — before the configured default. Blank
     * candidates are skipped, which is the case that actually fires on this
     * deployment.
     */
    public function description(?string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            $value = self::firstNonBlank($candidate);
            if ($value !== null) {
                return $value;
            }
        }

        return (string) $this->config->get('app.seo.default_description', '');
    }

    /**
     * The absolute canonical URL for $path (`[23.6]`).
     *
     * Absolute rather than root-relative because a canonical link is a
     * statement about which host owns the content, and a relative one cannot
     * make it — which is the whole reason `[23.6]` exists alongside the 301
     * canonicalisation that already normalises the path. The path is put
     * through the same {@see Url::canonicalizePath()} the redirect uses, so a
     * link built by hand in the non-canonical form still names the canonical
     * one (`[23.7]`).
     */
    public function canonical(string $path): string
    {
        $base = rtrim((string) $this->config->get('app.url', ''), '/');

        return $base . Url::canonicalizePath($path);
    }

    /**
     * Everything wrong with the URLs this deployment would publish.
     *
     * One check, and it is the one that cannot be made at request time.
     * `app.url` is the only source of the host in a canonical link, a sitemap
     * `<loc>` and `robots.txt`'s `Sitemap:` line, and all three are required
     * to be absolute — `[23.6]` says why for the canonical link, and the
     * sitemap schema says so for the other two. Unset, every one of them
     * comes out root-relative: still well-formed, still served, and wrong in
     * a way no page renders visibly.
     *
     * Falling back to the request's own host would be worse than reporting
     * it. A canonical link exists to state which host owns the content, so
     * one derived from whoever asked answers the question with the question.
     * That leaves the deployment check as the only honest place for this,
     * which is why it lives here as data rather than as a runtime guard.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $url = trim((string) $this->config->get('app.url', ''));

        if (preg_match('#^https?://#', $url) === 1) {
            return [];
        }

        return [sprintf(
            'app.url is not an absolute URL (%s) — every canonical link, sitemap <loc> and the Sitemap: '
            . 'line in robots.txt would be relative, and all three have to name a host',
            var_export($url, true),
        )];
    }

    public function siteName(): string
    {
        return (string) $this->config->get('app.seo.site_name', $this->config->get('app.name', ''));
    }

    /**
     * The social sharing card (`[24.4]`).
     *
     * Returns the fields a template needs for Open Graph and the Twitter
     * card, sharing the resolved title and description rather than resolving
     * them a second time — a social card that disagrees with the page it
     * describes is worse than none.
     *
     * `image` is null when the deployment configured none. A null is rendered
     * as an omitted tag rather than an empty one, because a social card
     * pointing at a broken image is what a crawler will cache.
     *
     * @return array{title: string, description: string, url: string, site_name: string, image: ?string, type: string}
     */
    public function social(string $path, string $title, string $description, string $type = 'website'): array
    {
        return [
            'title' => $title,
            'description' => $description,
            'url' => $this->canonical($path),
            'site_name' => $this->siteName(),
            'image' => $this->socialImage(),
            'type' => $type,
        ];
    }

    /** The absolute URL of the configured social image, or null when none is set. */
    private function socialImage(): ?string
    {
        $image = self::firstNonBlank($this->config->get('app.seo.social_image'));
        if ($image === null) {
            return null;
        }

        if (str_starts_with($image, 'http://') || str_starts_with($image, 'https://')) {
            return $image;
        }

        return rtrim((string) $this->config->get('app.url', ''), '/') . '/' . ltrim($image, '/');
    }

    /**
     * $value as a trimmed string, or null when it is absent, not a string, or
     * blank. Blank counts as absent throughout this class — see the class
     * docblock for why that is the case that matters here.
     */
    private static function firstNonBlank(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
