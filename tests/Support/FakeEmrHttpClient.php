<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * In-memory PSR-18 client for exercising the AsterMD SDK without a network
 * call. Routes are matched by substring against the request path — the first
 * matching entry wins — so a single fake can serve both the token endpoint
 * (`POST /v1/auth/api-credentials/token`) and whatever resource endpoint a
 * test is targeting. Every dispatched request is recorded in `$requests` for
 * assertions.
 */
final class FakeEmrHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param array<string, array{0: int, 1: array<string, mixed>|string}> $routes path substring => [statusCode, bodyArrayOrString] */
    public function __construct(private readonly array $routes)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $path = $request->getUri()->getPath();

        foreach ($this->routes as $substring => $route) {
            if (str_contains($path, $substring)) {
                [$status, $body] = $route;
                $content = is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body;

                return (new ResponseFactory())->createResponse($status)
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody((new StreamFactory())->createStream($content));
            }
        }

        throw new \RuntimeException(sprintf('FakeEmrHttpClient: no route configured for path "%s".', $path));
    }
}
