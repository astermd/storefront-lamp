<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use AsterMD\Sdk\Enum\Event;
use AsterMD\Storefront\Forms\IntakeGateway;

/**
 * In-memory {@see IntakeGateway} for exercising the intake controllers without
 * an EMR. Every recorded submission is kept in full, because the questions a
 * test asks of it are about the arguments — which event was sent, whether the
 * accumulated answer list really was re-sent in full (`[11.5]`), and whether
 * the multi-page position came along.
 *
 * `$result` is mutable so a single test can let the first save land and fail
 * the next.
 */
final class FakeIntakeGateway implements IntakeGateway
{
    /** @var list<array{session: string, event: Event, teleform_id: string, data: array<int, mixed>, progress: array{page: int, total: int}|null}> */
    public array $records = [];

    public function __construct(public bool $result = true)
    {
    }

    public function record(string $session, Event $event, string $teleformId, array $data, ?array $progress): bool
    {
        $this->records[] = [
            'session' => $session,
            'event' => $event,
            'teleform_id' => $teleformId,
            'data' => $data,
            'progress' => $progress,
        ];

        return $this->result;
    }
}
