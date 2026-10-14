<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Controller;

use AsterMD\Storefront\Emr\AddressAutofill;
use AsterMD\Storefront\Support\DatabaseRateLimiter;
use AsterMD\Storefront\Support\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `/checkout/address/suggest/` and `/checkout/address/resolve/` -- the
 * checkout address field's suggestions when the deployment has no Google Maps
 * key of its own.
 *
 * The browser asks the storefront rather than the EMR because the EMR call
 * needs this deployment's credential, which never reaches a page.
 *
 * **GETs, and so outside the CSRF check.** Both read and change nothing, and
 * what they answer is public address data. They are rate-limited per client
 * address instead, because each one spends an EMR call: typing an address is
 * a dozen of them, so the bucket is sized for several honest attempts and
 * stops a script.
 *
 * Every refusal and every failure answers an empty result with a 200, apart
 * from the rate limit's 429. The script treats both the same way -- no
 * dropdown -- and the buyer keeps typing.
 */
final class AddressController
{
    private const string BUCKET = 'checkout.address';

    /** Below this the EMR answers addresses from anywhere, and the call buys nothing. */
    private const int MIN_LENGTH = 3;

    private const int MAX_LENGTH = 200;

    public function __construct(
        private readonly AddressAutofill $autofill,
        private readonly DatabaseRateLimiter $limiter,
    ) {
    }

    public function suggest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $search = self::text($request->getQueryParams()['q'] ?? null);

        if ($search === null) {
            return self::json($response, ['suggestions' => []]);
        }

        if (!$this->allowed($request)) {
            return self::json($response->withStatus(429), ['suggestions' => []]);
        }

        return self::json($response, ['suggestions' => $this->autofill->suggest($search) ?? []]);
    }

    public function resolve(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $address = self::text($request->getQueryParams()['address'] ?? null);

        if ($address === null) {
            return self::json($response, ['address' => null]);
        }

        if (!$this->allowed($request)) {
            return self::json($response->withStatus(429), ['address' => null]);
        }

        return self::json($response, ['address' => $this->autofill->resolve($address)]);
    }

    private function allowed(ServerRequestInterface $request): bool
    {
        $context = RequestContext::fromRequest($request);

        return $this->limiter->allow(self::BUCKET, $context->clientIp ?? 'unknown');
    }

    /** The trimmed parameter, or null when it is missing, too short to be worth a call, or too long to be an address. */
    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        $length = mb_strlen($value);

        return $length < self::MIN_LENGTH || $length > self::MAX_LENGTH ? null : $value;
    }

    /** @param array<string, mixed> $data */
    private static function json(ResponseInterface $response, array $data): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($data));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }
}
