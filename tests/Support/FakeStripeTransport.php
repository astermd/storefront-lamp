<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use Stripe\HttpClient\ClientInterface;

/**
 * Stripe's HTTP client, answering from a queue and recording what was asked.
 *
 * Installed through {@see \AsterMD\Storefront\Payment\Stripe\StripeApiFactory},
 * exactly where the refusing transport sits under the suite, so the adapter
 * under test is the real one end to end except for the wire. The parameters
 * are recorded as the SDK hands them over -- nested arrays, before encoding --
 * so a test asserts what was sent rather than how it was spelled.
 */
final class FakeStripeTransport implements ClientInterface
{
    /** @var list<array{method: string, url: string, headers: list<string>, params: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<array{status: int, body: string}|\Throwable> */
    private array $queued = [];

    /** @param array<string, mixed> $body */
    public function queue(int $status, array $body): void
    {
        $this->queued[] = ['status' => $status, 'body' => (string) json_encode($body, JSON_THROW_ON_ERROR)];
    }

    /** The next request fails as the SDK's own transport fails: by throwing. */
    public function queueFailure(\Throwable $failure): void
    {
        $this->queued[] = $failure;
    }

    public function queueFixture(string $fixtureName, int $status = 200): void
    {
        $raw = file_get_contents(__DIR__ . '/../fixtures/' . $fixtureName);
        if ($raw === false) {
            throw new \RuntimeException(sprintf('Fixture "%s" is unreadable.', $fixtureName));
        }

        $this->queue($status, json_decode($raw, true, 512, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function params(int $index): array
    {
        if (!isset($this->requests[$index])) {
            throw new \OutOfBoundsException(sprintf('No request was made at index %d.', $index));
        }

        return $this->requests[$index]['params'];
    }

    public function header(int $index, string $name): ?string
    {
        foreach ($this->requests[$index]['headers'] ?? [] as $header) {
            [$key, $value] = array_pad(explode(':', $header, 2), 2, '');
            if (strcasecmp(trim($key), $name) === 0) {
                return trim($value);
            }
        }

        return null;
    }

    /** The request paths in the order they were made, e.g. `POST /v1/payment_intents`. */
    public function calls(): array
    {
        return array_map(
            static fn (array $request): string => strtoupper($request['method']) . ' ' . (string) parse_url($request['url'], PHP_URL_PATH),
            $this->requests,
        );
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = [
            'method' => (string) $method,
            'url' => (string) $absUrl,
            'headers' => array_values(array_map('strval', (array) $headers)),
            'params' => (array) $params,
        ];

        if ($this->queued === []) {
            throw new \LogicException('No Stripe response was queued for this request.');
        }

        $next = array_shift($this->queued);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return [$next['body'], $next['status'], ['request-id' => 'req_test']];
    }
}
