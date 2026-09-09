<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Issues (or reuses) a per-session CSRF token and rejects mutating requests
 * that don't echo it back. The token is minted lazily on every request, not
 * just mutating ones, so it's already sitting in the session by the time a
 * form is rendered — the alternative (minting it only on GET) would race a
 * form load against a session that hasn't started yet.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const MUTATING = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private readonly bool $trace = false)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        $token = $_SESSION['_csrf'];

        if (in_array($request->getMethod(), self::MUTATING, true)) {
            $body = (array) ($request->getParsedBody() ?? []);
            // Accepted from either the form body (_csrf) or a header
            // (X-CSRF-Token), so both classic form posts and fetch()-based
            // submissions can satisfy the check. hash_equals() guards against
            // timing attacks on the comparison.
            $sent = (string) ($body['_csrf'] ?? $request->getHeaderLine('X-CSRF-Token'));
            if ($sent === '' || !hash_equals($token, $sent)) {
                $response = new Response(419);
                $response->getBody()->write('Session expired or invalid form token. Go back, refresh the page, and try again.');

                return $this->trace ? $response->withAddedHeader('X-MW-Trace', 'csrf') : $response;
            }
        }

        $response = $handler->handle($request->withAttribute('csrf_token', $token));

        return $this->trace ? $response->withAddedHeader('X-MW-Trace', 'csrf') : $response;
    }
}
