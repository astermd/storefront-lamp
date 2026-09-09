<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Support;

/**
 * Path canonicalisation, shared by {@see \AsterMD\Storefront\Http\Middleware\CanonicalUrlMiddleware}
 * (which redirects non-canonical requests) and every `url()` call templates
 * make (which should never need a redirect in the first place). The
 * canonical form is always lowercase, has slash-runs collapsed, and — the
 * root path aside — always ends in a single trailing slash; changing any of
 * those three rules changes what counts as a redirect-worthy URL sitewide.
 */
final class Url
{
    public static function canonicalizePath(string $path): string
    {
        $path = strtolower($path);
        $path = (string) preg_replace('#/{2,}#', '/', $path);
        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : $path . '/';
    }

    /** @param array<string, scalar> $query */
    public static function to(string $path, array $query = []): string
    {
        $url = self::canonicalizePath($path);
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }
}
