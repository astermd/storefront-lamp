<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Journey;

/**
 * What {@see SessionResolver::resolve()} decided for this request.
 *
 * `issueCookie` is the only thing the caller has to act on: the cookie is
 * (re-)sent when a session was just minted, just adopted from a resume link,
 * or just re-minted to replace one the EMR no longer recognises
 * ({@see SessionResolver::remint()}), and left alone when the browser already
 * presented the one being used, so an ordinary page view carries no
 * `Set-Cookie` at all.
 */
final class SessionResolution
{
    public function __construct(
        public readonly ?string $sessionUuid,
        public readonly bool $issueCookie = false,
    ) {
    }
}
