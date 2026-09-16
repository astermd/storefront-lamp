<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use AsterMD\CheckoutChampClient\Http\HttpClientInterface;
use AsterMD\CheckoutChampClient\Http\Request;
use AsterMD\CheckoutChampClient\Http\Response;

/**
 * Records what the adapter asked for and replays canned provider responses.
 *
 * The counterpart to {@see FakeVrioTransport}, and it inspects a different part
 * of the request for a reason that is the provider's rather than this file's:
 * **this API takes every parameter in the query string**, so the assertion
 * worth making is about the URL, not the body. {@see self::params()} decodes it
 * back into an array so a case can name a field instead of matching a
 * substring — and a substring match here would happily pass on
 * `product10_id` when the case meant `product1_id`.
 */
final class FakeCheckoutChampTransport implements HttpClientInterface
{
    /** @var list<Request> every request, in order, so a test can assert on the query */
    public array $requests = [];

    /** @var list<array{status: int, body: string, transportError: string}> */
    private array $queued = [];

    /** Queues one response. Calls beyond the queue reuse the last entry. */
    public function queue(int $status, string $body, string $transportError = ''): void
    {
        $this->queued[] = ['status' => $status, 'body' => $body, 'transportError' => $transportError];
    }

    /** Queues a `{"result": "SUCCESS", "message": {...}}` envelope. */
    public function queueSuccess(array $message): void
    {
        $this->queue(200, json_encode(['result' => 'SUCCESS', 'message' => $message], JSON_THROW_ON_ERROR));
    }

    /** Queues the provider's refusal shape: `message` is a plain sentence, not an object. */
    public function queueError(string $message): void
    {
        $this->queue(200, json_encode(['result' => 'ERROR', 'message' => $message], JSON_THROW_ON_ERROR));
    }

    /** Queues a response straight from a recorded fixture. */
    public function queueFixture(string $fixtureName): void
    {
        $raw = file_get_contents(__DIR__ . '/../fixtures/' . $fixtureName);
        if ($raw === false) {
            throw new \RuntimeException(sprintf('Fixture "%s" is unreadable.', $fixtureName));
        }

        $this->queue(200, $raw);
    }

    /**
     * The query parameters of the request at $index, decoded.
     *
     * @return array<string, string>
     */
    public function params(int $index = 0): array
    {
        if (!isset($this->requests[$index])) {
            throw new \OutOfBoundsException(sprintf('No request was made at index %d.', $index));
        }

        parse_str((string) parse_url($this->requests[$index]->getUrl(), PHP_URL_QUERY), $params);

        /** @var array<string, string> $params */
        return $params;
    }

    /** The path of the request at $index, which is how the two-call placement is told apart. */
    public function path(int $index = 0): string
    {
        if (!isset($this->requests[$index])) {
            throw new \OutOfBoundsException(sprintf('No request was made at index %d.', $index));
        }

        return (string) parse_url($this->requests[$index]->getUrl(), PHP_URL_PATH);
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;

        $queued = $this->queued === []
            ? ['status' => 200, 'body' => '{"result":"SUCCESS","message":{}}', 'transportError' => '']
            : (count($this->queued) > 1 ? array_shift($this->queued) : $this->queued[0]);

        return new Response($queued['status'], $queued['body'], [], $queued['transportError']);
    }
}
