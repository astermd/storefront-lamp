<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use AsterMD\VrioClient\Http\HttpClientInterface;
use AsterMD\VrioClient\Http\Request;
use AsterMD\VrioClient\Http\Response;

/**
 * Records what the adapter asked for and replays canned provider responses.
 *
 * The package's own transport seam is used rather than a stubbed API object,
 * because the payload assembly is the part worth testing: a test that stubs
 * `addOrder()` asserts only that we called a method, while this one can assert
 * the exact JSON body Vrio would have received.
 */
final class FakeVrioTransport implements HttpClientInterface
{
    /** @var list<Request> every request, in order, so a test can assert on the body */
    public array $requests = [];

    /** @var list<array{status: int, body: string, transportError: string}> */
    private array $queued = [];

    /** Queues one response. Calls beyond the queue reuse the last entry. */
    public function queue(int $status, string $body, string $transportError = ''): void
    {
        $this->queued[] = ['status' => $status, 'body' => $body, 'transportError' => $transportError];
    }

    /**
     * Queues a response straight from a recorded fixture.
     *
     * The client rebuilds its own `{success, message, data}` envelope from the
     * provider's raw body, so the fixture's `data` node is what the wire
     * actually carried and what the transport must hand back.
     */
    public function queueFixture(string $fixtureName): void
    {
        $raw = file_get_contents(__DIR__ . '/../fixtures/' . $fixtureName);
        if ($raw === false) {
            throw new \RuntimeException(sprintf('Fixture "%s" is unreadable.', $fixtureName));
        }

        /** @var array<string, mixed> $envelope */
        $envelope = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $this->queue(200, json_encode($envelope['data'] ?? null, JSON_THROW_ON_ERROR));
    }

    /**
     * The decoded JSON body of the request at $index.
     *
     * @return array<string, mixed>
     */
    public function body(int $index = 0): array
    {
        if (!isset($this->requests[$index])) {
            throw new \OutOfBoundsException(sprintf('No request was made at index %d.', $index));
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->requests[$index]->getBody() ?? '{}', true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;

        if ($this->queued === []) {
            return new Response(200, '{}', ['http_code' => 200]);
        }

        // Responses are consumed in order; once the queue runs out the last
        // one repeats, so a test that only cares about the first call does not
        // have to queue a response per call.
        $next = $this->queued[min(count($this->requests), count($this->queued)) - 1];

        return new Response(
            $next['status'],
            $next['body'],
            ['http_code' => $next['status']],
            $next['transportError'],
        );
    }
}
