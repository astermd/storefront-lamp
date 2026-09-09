<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Middleware;

use AsterMD\Storefront\Support\Url;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * One-hop 301 to the canonical URL form (spec §23): trailing slash, lowercase
 * path, collapsed slashes. The query string is preserved byte-for-byte —
 * attribution and resume parameters ride there and must never be re-encoded.
 * Only safe methods redirect; redirecting a POST would drop its body.
 */
final class CanonicalUrlMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly bool $trace = false)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $canonical = Url::canonicalizePath($path);

        if ($canonical !== $path
            && in_array($request->getMethod(), ['GET', 'HEAD'], true)
            && !$this->pathIsFileLike($path)
        ) {
            $query = $request->getUri()->getQuery();
            $location = $canonical . ($query !== '' ? '?' . $query : '');

            $response = (new Response(301))->withHeader('Location', $location);

            return $this->trace ? $response->withAddedHeader('X-MW-Trace', 'canonical') : $response;
        }

        $response = $handler->handle($request);

        return $this->trace ? $response->withAddedHeader('X-MW-Trace', 'canonical') : $response;
    }

    /**
     * File-like final path segments (sitemap.xml, robots.txt, favicon.ico) are
     * exempt from canonicalisation: they're conventionally served without a
     * trailing slash and must not 301 to one.
     */
    private function pathIsFileLike(string $path): bool
    {
        return str_contains(basename($path), '.');
    }
}
