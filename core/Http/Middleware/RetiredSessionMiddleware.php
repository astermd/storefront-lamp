<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Middleware;

use AsterMD\Storefront\Funnel\FlowDefinition;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Journey\SessionOptions;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Ends the analytics session of a journey that has completed, on the first
 * request that is not the receipt itself (`[4.18]`, `[4.19]`).
 *
 * **The mechanism deviates from the spec deliberately.** `[4.18]` describes the
 * server handing the client an "order placed" signal which the client then acts
 * on, clearing the session identifier from both the cookie and client storage.
 * This application has no client-side session bridge -- the `amd_session`
 * HTTP-only cookie is the only carrier, by an architectural decision that
 * predates this rule -- so the signal lives in durable journey state instead
 * and the clearing happens here.
 *
 * The semantics are the ones `[4.18]` asks for. The receipt keeps the session
 * alive, because upsells are reached from it and a buyer who presses back must
 * still find their order. Any other page ends it, so the next browse starts a
 * genuinely new session. And because the flag is durable rather than held in
 * the browser, `[4.19]`'s "closed the browser mid-upsell" case is covered
 * without client storage: the flag is still there whenever the visitor next
 * returns.
 *
 * Clearing the cookie rather than deleting the row: the journey is the record
 * of a completed purchase and a reconciliation sweep may still want it
 * (`[21.8]`). What ends is the browser's claim on it.
 *
 * **Position in the pipeline is load-bearing, and it is *outside*
 * {@see AttributionMiddleware}.** Two reasons, in that order. The cookie is
 * issued on the way out by that middleware, and `Set-Cookie` headers are
 * applied by a browser in order — so a clear added inside it would be
 * overwritten by the re-issue it is meant to cancel. And a request that
 * genuinely did re-mint a session has adopted a *fresh* journey whose
 * retirement flag is false, so reading the flag here, after the handler has
 * run, answers about the journey the visitor actually ends the request with
 * rather than the one they started it with. Which is the right answer: a
 * re-minted session is already the new session `[4.18]` is asking for, and
 * clearing it would start a mint loop.
 *
 * Everything about it fails open. An unreachable journey store, an unresolvable
 * path, a flow with no receipt step: each leaves the cookie alone. The harm in
 * this direction is a stale analytics session; the harm in the other is a buyer
 * losing their receipt one request after paying for it.
 */
final class RetiredSessionMiddleware implements MiddlewareInterface
{
    /** @param \Closure(): JourneyStore $journey resolved on first use, so an unreachable store degrades rather than fatals */
    public function __construct(
        private readonly \Closure $journey,
        private readonly FlowDefinition $flow,
        private readonly SessionOptions $options,
        private readonly bool $trace = false,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($this->shouldClear($request)) {
            $response = $response->withAddedHeader('Set-Cookie', $this->expiredCookie($request));
        }

        return $this->trace ? $response->withAddedHeader('X-MW-Trace', 'retired-session') : $response;
    }

    /**
     * Whether this request is the one that ends the session.
     *
     * The receipt is matched by asking the flow which step owns the path rather
     * than by comparing strings: `stepForPath()` canonicalises, and it answers
     * null instead of throwing for a path the funnel does not know — so a
     * deployment that renamed the step clears nothing rather than clearing the
     * receipt's own session.
     */
    private function shouldClear(ServerRequestInterface $request): bool
    {
        try {
            $state = ($this->journey)()->state();
        } catch (\Throwable) {
            // A journey that cannot be read cannot be proved retired, and
            // `[20.1]` says the visitor's page comes first either way.
            return false;
        }

        if ($state === null || !$state->sessionRetired) {
            return false;
        }

        return $this->flow->stepForPath($request->getUri()->getPath()) !== 'receipt';
    }

    /**
     * The same cookie {@see AttributionMiddleware} issues, expired.
     *
     * Name, path and the security attributes have to match the one being
     * replaced or a browser treats it as a different cookie and leaves the
     * original in place. `Max-Age=0` and a past `Expires` are both sent because
     * either alone is ignored by some client somewhere, and no value is carried:
     * there is nothing left to identify.
     */
    private function expiredCookie(ServerRequestInterface $request): string
    {
        $scheme = strtolower($request->getHeaderLine('X-Forwarded-Proto') ?: $request->getUri()->getScheme());

        $parts = [
            $this->options->cookieName . '=',
            'Path=/',
            'Max-Age=0',
            'Expires=Thu, 01 Jan 1970 00:00:00 GMT',
            'HttpOnly',
            'SameSite=Lax',
        ];

        if ($scheme === 'https') {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }
}
