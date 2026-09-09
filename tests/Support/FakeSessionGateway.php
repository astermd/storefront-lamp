<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use AsterMD\Storefront\Emr\SessionGateway;

/**
 * In-memory {@see SessionGateway} for exercising session resolution without
 * an EMR. Records every call so tests can assert that the reconciliation
 * read happens exactly once.
 */
final class FakeSessionGateway implements SessionGateway
{
    /** @var list<array{data: array<string, mixed>, ua: ?string, ip: ?string}> */
    public array $createCalls = [];

    /** @var list<string> */
    public array $viewCalls = [];

    /**
     * @param array<string, array{opportunity_id: ?string, events: list<string>}> $sessions known sessions
     * @param list<string>                                                        $failingViews uuids whose view() fails
     */
    public function __construct(
        private readonly array $sessions = [],
        private readonly ?string $mintUuid = null,
        private readonly array $failingViews = [],
    ) {
    }

    public function create(array $data, ?string $userAgent, ?string $clientIp): ?string
    {
        $this->createCalls[] = ['data' => $data, 'ua' => $userAgent, 'ip' => $clientIp];

        return $this->mintUuid;
    }

    public function view(string $uuid): ?array
    {
        $this->viewCalls[] = $uuid;

        if (in_array($uuid, $this->failingViews, true)) {
            return null;
        }

        $session = $this->sessions[$uuid] ?? null;

        return $session === null
            ? ['exists' => false, 'opportunity_id' => null, 'events' => []]
            : ['exists' => true, 'opportunity_id' => $session['opportunity_id'], 'events' => $session['events']];
    }
}
