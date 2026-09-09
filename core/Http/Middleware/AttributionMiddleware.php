<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Middleware;

use AsterMD\Storefront\Journey\SessionOptions;
use AsterMD\Storefront\Journey\JourneyWriteFailed;
use AsterMD\Storefront\Journey\SessionResolution;
use AsterMD\Storefront\Journey\SessionResolver;
use AsterMD\Storefront\Support\FailureDigest;
use AsterMD\Storefront\Support\OperatorLog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves the visitor's analytics session and captures first-touch
 * attribution, then publishes the result to the rest of the request.
 *
 * Its position in the pipeline is load-bearing: inside the native session
 * middleware (which gives CSRF somewhere to keep its token) and outside
 * CSRF and the save-back, so attribution is captured before anything reads
 * it and the state it mutates is still persisted on the way out.
 *
 * A session is only minted for a plain page view: a GET, on a path that is
 * not an excluded machine endpoint. A POST arriving without a session is a
 * visitor whose journey the storefront never saw start — mirroring their
 * cart degrades, which is the documented outcome (`[7.12]`) — and minting
 * one mid-mutation would attribute the journey to the wrong moment.
 *
 * This middleware is also the error boundary for the whole of session
 * resolution. Analytics session creation is a must-degrade-silently
 * dependency (`[20.2]`), and that covers the local reads and writes backing
 * it as much as the EMR call itself: a visitor must get their page even from
 * a storefront whose database is unreachable or unmigrated (`[20.1]`).
 * Wrapping here rather than inside each repository method keeps the swallow
 * in one auditable place instead of scattered through the data layer; leaves
 * {@see \AsterMD\Storefront\Repository\SessionRepository} throwing for the
 * console and migration callers, which *should* see a failure; and makes a
 * fault anywhere beneath the resolver — repository, journey store, or
 * gateway — degrade identically to "no session", which the funnel already
 * handles (`[4.5]`).
 *
 * The resolver arrives as a closure, not as an instance, so that *building*
 * it — the repositories and the journey store — happens inside that same
 * boundary. A slot that cannot be constructed has to degrade exactly like one
 * that throws mid-resolution; otherwise `/health/`, whose entire job is to
 * report an unreachable database (`[20.15]`), is the first page an unreachable
 * database takes down. The connection itself is no longer opened during that
 * construction — {@see \AsterMD\Storefront\Repository\SessionRepository} opens
 * it on first query — so what an outage throws here is the resolver's first
 * read, which lands in the same catch.
 */
final class AttributionMiddleware implements MiddlewareInterface
{
    /** @param \Closure(): SessionResolver $resolver resolved on first use, inside this middleware's error boundary */
    public function __construct(
        private readonly \Closure $resolver,
        private readonly SessionOptions $options,
        private readonly OperatorLog $log,
        private readonly bool $trace = false,
        private readonly ?\Closure $routeExists = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $resolution = ($this->resolver)()->resolve($request, $this->mayCreate($request));
        } catch (\Throwable $e) {
            // Distinct event name: this is the only line that distinguishes a
            // storefront serving pages with tracking silently broken from one
            // that simply has no visitors to track.
            //
            // The message is not taken, for the reason
            // {@see JourneyStateMiddleware} does not take one either, and this
            // is the sibling that reaches the same column: resolution ends in
            // {@see \AsterMD\Storefront\Repository\SessionRepository::create()},
            // which inserts the whole journey blob -- clinical answers, buyer
            // contact details, the credential handle, the frozen receipt and
            // the identity verdict too. A driver that refuses that insert may
            // quote it back, and neither defence at the sink can see inside a
            // string: redaction is key-based, and
            // {@see \AsterMD\Storefront\Support\CardScrubber} masks by Luhn
            // check, so it catches a card number and nothing else.
            //
            // The class and the SQLSTATE answer what an outage actually asks
            // -- which failure, how often, since when (`[20.6]`, `[22.21]`).
            $this->log->error('session.resolve_failed', array_filter([
                'path' => $request->getUri()->getPath(),
                'exception' => $e::class,
                'sqlstate' => FailureDigest::sqlState($e),
                // Reported only when this application composed it. Same type
                // boundary the journey save-back uses, and for the same
                // reason: "ours" cannot be decided by reading message text.
                'reason' => $e instanceof JourneyWriteFailed ? $e->getMessage() : null,
            ], static fn (mixed $value): bool => $value !== null));
            $resolution = new SessionResolution(null);
        }

        $response = $handler->handle($request->withAttribute('session_uuid', $resolution->sessionUuid));

        if ($resolution->sessionUuid !== null && $resolution->issueCookie) {
            $response = $response->withAddedHeader('Set-Cookie', $this->cookie($request, $resolution->sessionUuid));
        }

        return $this->trace ? $response->withAddedHeader('X-MW-Trace', 'attribution') : $response;
    }


    private function mayCreate(ServerRequestInterface $request): bool
    {
        if ($request->getMethod() !== 'GET') {
            return false;
        }

        $path = $request->getUri()->getPath();
        foreach ($this->options->excludePaths as $excluded) {
            if (str_starts_with($path, $excluded)) {
                return false;
            }
        }

        // A request for a page this storefront does not have is not a visit,
        // and minting for one is not a harmless extra row: it is an outbound
        // EMR call on the request's critical path, and the session it creates
        // can never be used. The cookie that would carry it is added after the
        // handler returns, and a request that 404s leaves through the error
        // handler instead -- so the session is created and orphaned in the
        // same breath.
        //
        // Left unguarded that is unbounded: every missing asset, every
        // mistyped URL and every scanner probing for `/wp-login.php` creates a
        // session. A site with no `public/favicon.ico` mints one per fresh
        // visitor before they see a single page.
        //
        // Asked of the route resolver rather than inferred from the pipeline,
        // because this middleware runs *outside* the routing middleware and
        // moving it inside would reorder a stack whose order is load-bearing
        // and separately tested. The resolver answers the same question
        // without caring where it is asked from.
        return $this->routeExists === null || ($this->routeExists)($request) === true;
    }

    /**
     * The session cookie (`[4.6]`): 30 days, HTTP-only, same-site lax, and
     * secure whenever the request reached us over TLS. `X-Forwarded-Proto` is
     * trusted for the same reason the native session middleware trusts it —
     * it assumes a proxy that strips any client-sent value before setting its
     * own (the shipped nginx/apache configs in `deploy/` front the app this
     * way). Behind a proxy that does not, a client can send
     * `X-Forwarded-Proto: http` on a TLS site and suppress the `Secure` flag.
     */
    private function cookie(ServerRequestInterface $request, string $uuid): string
    {
        $scheme = strtolower($request->getHeaderLine('X-Forwarded-Proto') ?: $request->getUri()->getScheme());
        $maxAge = $this->options->lifetimeDays * 86400;

        $parts = [
            $this->options->cookieName . '=' . urlencode($uuid),
            'Path=/',
            'Max-Age=' . $maxAge,
            'Expires=' . gmdate('D, d M Y H:i:s \G\M\T', time() + $maxAge),
            'HttpOnly',
            'SameSite=Lax',
        ];

        if ($scheme === 'https') {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }
}
