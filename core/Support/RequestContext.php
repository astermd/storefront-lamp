<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Support;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The visitor's own IP address and user agent, resolved from the request in
 * exactly one place.
 *
 * Both values are forwarded to the EMR on session creation so a session is
 * attributed to the end user's device and location rather than to this
 * server (`[4.3]`), which makes the resolution order load-bearing rather
 * than cosmetic: a CDN's real-client header first, then the left-most entry
 * of the forwarded-for chain, then the direct peer. Candidates that are not
 * valid IP literals are skipped rather than trusted, and a request with
 * nothing usable yields null — never a placeholder, since an invented address
 * is worse than an absent one.
 *
 * Every one of those headers is client-writable at the HTTP level, so the
 * order above assumes a proxy that strips any client-sent value before
 * setting its own — the shipped nginx/apache configs in `deploy/` front the
 * app this way. Behind a proxy that does not, a visitor can name any address
 * they like and have it forwarded to the EMR as theirs (`[5.16]`), which
 * poisons session geo attribution.
 */
final class RequestContext
{
    /** Real-client headers set by a CDN, in preference order. */
    private const array CDN_IP_HEADERS = ['CF-Connecting-IP', 'True-Client-IP'];

    private function __construct(
        public readonly ?string $clientIp,
        public readonly ?string $userAgent,
    ) {
    }

    public static function fromRequest(ServerRequestInterface $request): self
    {
        return new self(self::resolveIp($request), self::resolveUserAgent($request));
    }

    private static function resolveIp(ServerRequestInterface $request): ?string
    {
        foreach (self::CDN_IP_HEADERS as $header) {
            $candidate = self::validIp($request->getHeaderLine($header));
            if ($candidate !== null) {
                return $candidate;
            }
        }

        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded !== '') {
            $first = self::validIp(explode(',', $forwarded)[0]);
            if ($first !== null) {
                return $first;
            }
        }

        return self::validIp((string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));
    }

    private static function validIp(string $value): ?string
    {
        $value = trim($value);

        return $value !== '' && filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : null;
    }

    private static function resolveUserAgent(ServerRequestInterface $request): ?string
    {
        $agent = trim($request->getHeaderLine('User-Agent'));

        return $agent === '' ? null : $agent;
    }
}
