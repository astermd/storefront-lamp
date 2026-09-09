<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Registers a place in the pipeline without building what goes in it.
 *
 * The pipeline's *order* is fixed at bootstrap; its *construction* must not
 * be. Resolving each middleware eagerly pulls in the whole dependency graph
 * behind it — the database connection among them — during application
 * bootstrap, which runs before the error middleware exists. A database that
 * is unreachable then is an unhandled fatal: no themed error page, no request
 * id to quote in a bug report, and `/health/` can never answer with
 * `database: false` at exactly the moment an operator is asking why the site
 * is down. Deferring resolution to the first request that actually reaches
 * this slot keeps that work inside the error boundary (`[20.1]`), and off the
 * routes that need no database at all.
 *
 * The middleware is resolved from the container, which caches it, so a
 * request-scoped collaborator shared between two middleware slots is still
 * the same instance.
 */
final class LazyMiddleware implements MiddlewareInterface
{
    /** @param \Closure(): MiddlewareInterface $resolve resolves the real middleware, once, on first use */
    public function __construct(private readonly \Closure $resolve)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return ($this->resolve)()->process($request, $handler);
    }
}
