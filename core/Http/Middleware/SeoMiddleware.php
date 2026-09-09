<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Middleware;

use AsterMD\Storefront\Seo\MetaResolver;
use AsterMD\Storefront\Seo\PageMeta;
use AsterMD\Storefront\Seo\RobotsPolicy;
use AsterMD\Storefront\Support\Url;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Views\Twig;

/**
 * Publishes the request's SEO context to every template and enforces the
 * indexing directive as a response header.
 *
 * `[24.2]` requires the indexing decision to be enforced **twice** — as a
 * header and as a meta tag — from a single config value. This middleware is
 * the header half and the source of the value the meta half renders, so the
 * two cannot disagree. Before it, `X-Robots-Tag` was sent by exactly one
 * route in the whole application while four pages emitted conflicting meta
 * tags.
 *
 * It sits immediately inside {@see CanonicalUrlMiddleware} and outside
 * everything else, for two reasons. The canonical URL it publishes must be
 * built from the canonicalised path, so canonicalisation has to have run.
 * And every response the application produces should carry the header —
 * including the ones no controller rendered — so this has to be outside the
 * session, journey and step-guard slots that can redirect a request away
 * before a controller sees it.
 *
 * One consequence of that position is worth stating rather than discovering:
 * the error boundary sits **outside** this middleware, so a response it
 * renders — a 404, or anything that threw — never passes back through here
 * and carries its directive as a meta tag alone. The error templates refuse
 * indexing themselves for that reason, and a non-200 response is not indexed
 * on its own account either way.
 *
 * It publishes rather than decides: the `seo` global carries resolved
 * defaults and the helpers a template needs, and a page that knows better
 * about its own title or description passes its own value in. That keeps
 * `[24.5]`'s per-page override a template-level concern without the
 * resolution logic living in Twig.
 */
final class SeoMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Twig $twig,
        private readonly MetaResolver $meta,
        private readonly RobotsPolicy $robots,
        private readonly bool $trace = false,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = Url::canonicalizePath($request->getUri()->getPath());
        $directive = $this->robots->directiveFor($path);

        $this->twig->getEnvironment()->addGlobal('seo', [
            'path' => $path,
            'canonical' => $this->meta->canonical($path),
            'robots' => $directive,
            'site_name' => $this->meta->siteName(),
            'default_title' => $this->meta->title(),
            'default_description' => $this->meta->description(),
            // What the layout renders. It is the request's metadata before
            // any page has spoken; the layout folds in the page's own title
            // and description through `page.with(...)`, which is the only
            // place either one is resolved.
            'page' => PageMeta::forPath($this->meta, $path, $directive),
            // The directive a page refuses indexing with when it knows
            // something the path-based policy cannot — kept here so the
            // layout never spells out a directive of its own.
            'refused' => RobotsPolicy::REFUSED,
        ]);

        $response = $handler->handle($request);

        if ($directive !== null && !$response->hasHeader('X-Robots-Tag')) {
            $response = $response->withHeader('X-Robots-Tag', $directive);
        }

        return $this->trace ? $response->withAddedHeader('X-MW-Trace', 'seo') : $response;
    }
}
