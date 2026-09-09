<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Seo;

use AsterMD\Storefront\Support\Config;

/**
 * The single answer to "may this path be indexed", consulted by everything
 * that needs to know.
 *
 * There is one writer of that decision on purpose. Before this class the
 * answer lived in four places at once — a route argument, a controller
 * variable, a hard-coded `<meta>` in the template, and nothing at all for the
 * response header — and three funnel pages emitted the tag twice with
 * different values because two of those writers disagreed. `[24.2]` requires
 * the directive to be enforced twice, as a header *and* a meta tag, which is
 * only safe when both readings come from one source.
 *
 * The same object answers `[24.6]`: a path that may not be indexed is a path
 * that may not appear in the sitemap. Deriving one from the other is what
 * stops a funnel step added later from being hidden from crawlers while still
 * being advertised in `sitemap.xml`.
 *
 * `[24.7]`'s master switch sits above all of it. When `discourage_indexing`
 * is on, every path is refused regardless of any per-route rule, because a
 * staging deployment must not be able to leak into an index through one
 * mis-set page.
 */
final class RobotsPolicy
{
    /** What a refused path emits. `nofollow` too: a noindex page's links are not endorsements. */
    public const string REFUSED = 'noindex, nofollow';

    /** Ranks below every real match, so a rule that matches nothing can never win. */
    private const int NO_MATCH = -1;

    /**
     * @param array<string, string> $rules canonical path (optionally ending `*`) => directive
     */
    public function __construct(
        private readonly array $rules,
        private readonly bool $discourageIndexing,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        /** @var array<string, string> $rules */
        $rules = (array) $config->get('app.seo.robots_rules', []);

        return new self($rules, (bool) $config->get('app.seo.discourage_indexing', false));
    }

    /**
     * The directive for $path, or null when the path may be indexed freely.
     *
     * Null rather than an `index, follow` string because the absence of a
     * directive is what "indexable" means to a crawler, and emitting the
     * permissive form says nothing a crawler did not already assume while
     * adding a tag to every page that has to stay correct forever.
     */
    public function directiveFor(string $path): ?string
    {
        if ($this->discourageIndexing) {
            return self::REFUSED;
        }

        return $this->matchRule($path);
    }

    /**
     * Whether $path's directive keeps it out of an index altogether, as
     * opposed to merely qualifying how it appears in one.
     *
     * **Having a directive is not the same as being refused.** `noarchive`
     * asks a crawler to index the page and not cache it; `nosnippet` asks it
     * to index the page and quote nothing from it. Reading either as a
     * refusal is not a harmless over-reach: `robots.txt` would translate it
     * into a `Disallow`, which blocks the fetch, and a page that is never
     * fetched is never indexed — the exact opposite of what the deployment
     * asked for.
     *
     * This lives here because `robots.txt` and `sitemap.xml` both need the
     * answer, and a private copy in either of them is a second reading of one
     * decision. That is what this class exists to prevent: the failure mode is
     * silent, since a `Disallow` in front of a page that believes it may be
     * indexed stops the crawl that would have revealed the disagreement.
     */
    public function refusesIndexing(string $path): bool
    {
        $directive = $this->directiveFor($path);

        return $directive !== null && str_contains(strtolower($directive), 'noindex');
    }

    /**
     * Whether $path may appear in `sitemap.xml` (`[24.6]`).
     *
     * Derived from the refusal rather than from a second list: a sitemap that
     * advertises a page the robots policy refuses is the contradiction
     * `[24.6]` exists to prevent, and two lists drift.
     *
     * Deliberately the negation of {@see self::refusesIndexing()} and not
     * `directiveFor() === null`. The two differ only for a directive that
     * qualifies indexing without refusing it, and for that case the negation
     * is the correct reading: `[24.6]` withholds the advertisement from a page
     * that may not be indexed, and a `noarchive` page may be. Defining it the
     * other way left this class holding two readings of its own decision —
     * `robots.txt` crawling a path the sitemap had dropped — which is the
     * drift it was built to make impossible.
     */
    public function isIndexable(string $path): bool
    {
        return !$this->refusesIndexing($path);
    }

    /**
     * Whether the deployment refuses indexing outright (`[24.7]`).
     *
     * `robots.txt` needs this separately from any single path, because the
     * master switch turns the whole file into a blanket disallow rather than
     * a list of exceptions.
     */
    public function indexingDiscouraged(): bool
    {
        return $this->discourageIndexing;
    }

    /**
     * Every rule this class cannot read, named so a deployment check can
     * refuse the deployment (`[24.11]`'s reporting, applied to the rules
     * rather than to what they publish).
     *
     * A skipped rule is a rule that fences nothing off, and the fence is the
     * only reason the rule was written. Nothing at request time is in a
     * position to complain — see {@see self::matchRule()} for why it must
     * stay silent there — so this is where the complaint lives, and it is
     * only useful if something asks before the deployment goes out.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        foreach ($this->rules as $pattern => $directive) {
            if (!is_string($pattern)) {
                $problems[] = sprintf(
                    'app.seo.robots_rules[%s] has no path to match — the rules are a map of path => directive, '
                    . 'and a bare list entry (%s) leaves the path it names crawlable',
                    var_export($pattern, true),
                    var_export($directive, true),
                );

                continue;
            }

            if (!is_string($directive)) {
                $problems[] = sprintf(
                    'app.seo.robots_rules[%s] names no directive — %s is not one, so the rule is skipped '
                    . 'and the path stays crawlable',
                    $pattern,
                    get_debug_type($directive),
                );
            }
        }

        return $problems;
    }

    /**
     * The most specific matching rule's directive.
     *
     * Exact paths beat prefixes, and a longer prefix beats a shorter one, so
     * `/checkout/promo/` can be governed separately from `/checkout/*` without
     * the order of the config array deciding the outcome.
     *
     * A rule this method cannot read is **skipped, not coerced**. PHP gives
     * integer keys to a `robots_rules` written as a list, and an integer is
     * not a path: coercing one to `"0"` would leave a rule that looks like a fence
     * and matches nothing, hiding the mistake for as long as the deployment
     * lives. Skipping it has the same effect on the answer and is reported by
     * {@see self::problems()} instead. What it must not do is throw —
     * `[20.1]` puts every page's directive on the side that may never break
     * the storefront, and this method is on the path of every request,
     * `/health/` included, so a TypeError here takes the whole instance out
     * of rotation.
     */
    private function matchRule(string $path): ?string
    {
        $best = null;
        $bestRank = self::NO_MATCH;

        foreach ($this->rules as $pattern => $directive) {
            if (!is_string($pattern) || !is_string($directive)) {
                continue;
            }

            $rank = self::specificity($pattern, $path);
            if ($rank > $bestRank) {
                $best = $directive;
                $bestRank = $rank;
            }
        }

        return $best;
    }

    /**
     * How specific $pattern's match against $path is, or {@see self::NO_MATCH}.
     *
     * Doubled, with an exact match taking the odd number, so that **an exact
     * rule outranks a prefix rule of the same length**. That tie is reachable
     * and it is not academic: `/faq/` and `/faq/*` both score five characters,
     * and scoring them equally left `>` to resolve them — which means the
     * order of the config array deciding the outcome, the one thing the
     * ordering exists to prevent. Because `refusesIndexing()` feeds
     * `robots.txt` and `sitemap.xml` as well, losing the tie does not merely
     * mis-tag a page: it makes the page indexable *and* advertises it.
     *
     * Doubling is safe rather than merely convenient. A prefix long enough to
     * outrank an exact match — `strlen($prefix) > strlen($pattern)` — cannot
     * match the same path the exact rule matched, since that path *is* the
     * exact pattern and is therefore too short to start with the longer
     * prefix. So the two halves of the ordering never compete.
     */
    private static function specificity(string $pattern, string $path): int
    {
        if (!str_ends_with($pattern, '*')) {
            return $pattern === $path ? strlen($pattern) * 2 + 1 : self::NO_MATCH;
        }

        $prefix = substr($pattern, 0, -1);

        return str_starts_with($path, $prefix) ? strlen($prefix) * 2 : self::NO_MATCH;
    }
}
