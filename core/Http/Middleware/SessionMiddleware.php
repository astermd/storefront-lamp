<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Starts the native PHP session with cookie settings tuned for this
 * storefront's long checkout journey, unless one is already running (or
 * we're somewhere a cookie can't be set — CLI, or headers already sent).
 */
final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $sessionSavePath,
        private readonly bool $trace = false,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (session_status() === PHP_SESSION_NONE) {
            if (headers_sent() || PHP_SAPI === 'cli') {
                // Test / CLI context: no cookie transport, keep a plain array.
                $_SESSION = $_SESSION ?? [];
            } else {
                // Keep server-side session data alive for as long as the cookie
                // we hand out below (30 days), otherwise the GC default (24
                // min) can reap the session store well before the cookie expires.
                ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 30));
                session_save_path($this->sessionSavePath);
                // Trusting X-Forwarded-Proto assumes a proxy that strips any
                // client-sent value before setting its own (the shipped
                // nginx/apache configs in deploy/ front the app this way).
                $scheme = strtolower($request->getHeaderLine('X-Forwarded-Proto') ?: $request->getUri()->getScheme());
                session_set_cookie_params([
                    'lifetime' => 60 * 60 * 24 * 30,
                    'httponly' => true,
                    'samesite' => 'Lax',
                    'secure' => $scheme === 'https',
                    'path' => '/',
                ]);
                session_start();
            }
        }

        $response = $handler->handle($request);

        return $this->trace ? $response->withAddedHeader('X-MW-Trace', 'session') : $response;
    }
}
