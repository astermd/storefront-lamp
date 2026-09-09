<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Seo;

/**
 * One page's resolved metadata — everything the layout renders into `<head>`
 * and nothing else.
 *
 * It exists so the layout has a single object to read from rather than a
 * resolution chain to re-run per tag. The values are resolved once, on
 * construction, so the `<title>`, the description, the canonical link and the
 * social card cannot disagree with each other: the card shares the same two
 * strings the page itself renders, and a card that describes a different page
 * from the one it is attached to is worse than none.
 *
 * A page contributes its own title and description by setting `page_title`
 * and `page_description` before the layout renders, and {@see self::with()}
 * folds them in. Everything absent or blank falls through to the configured
 * default, which is `[24.4]`'s chain and — because every product this EMR
 * syncs carries an empty description — the case that fires on real data.
 */
final class PageMeta
{
    /** Open Graph's default object type. Pages describing one product say so instead. */
    private const string DEFAULT_TYPE = 'website';

    /**
     * @param array{title: string, description: string, url: string, site_name: string, image: ?string, type: string} $social
     */
    private function __construct(
        private readonly MetaResolver $resolver,
        private readonly string $path,
        public readonly string $title,
        public readonly string $description,
        public readonly string $canonical,
        public readonly ?string $robots,
        public readonly array $social,
    ) {
    }

    /**
     * The metadata a request has before any page has spoken: configured
     * defaults, the canonical URL of the path being served, and the indexing
     * directive {@see RobotsPolicy} decided for it.
     */
    public static function forPath(MetaResolver $resolver, string $path, ?string $robots): self
    {
        return self::resolve($resolver, $path, $robots, null, null, self::DEFAULT_TYPE);
    }

    /**
     * The same metadata with this page's own contributions folded in.
     *
     * Null or blank arguments keep the default, so a page states only what it
     * knows better than the configuration does.
     */
    public function with(?string $title = null, ?string $description = null, ?string $type = null): self
    {
        return self::resolve(
            $this->resolver,
            $this->path,
            $this->robots,
            $title,
            $description,
            $type ?? self::DEFAULT_TYPE,
        );
    }

    private static function resolve(
        MetaResolver $resolver,
        string $path,
        ?string $robots,
        ?string $title,
        ?string $description,
        string $type,
    ): self {
        $resolvedTitle = $resolver->title($title);
        $resolvedDescription = $resolver->description($description);

        return new self(
            $resolver,
            $path,
            $resolvedTitle,
            $resolvedDescription,
            $resolver->canonical($path),
            $robots,
            $resolver->social($path, $resolvedTitle, $resolvedDescription, $type),
        );
    }
}
