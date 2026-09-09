<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Observability;

use AsterMD\Storefront\Emr\SessionGateway;

/**
 * `[20.9]` over the analytics-session boundary.
 *
 * A session that cannot be minted is not an error anywhere in this
 * application — it degrades to "no session" (`[4.5]`, `[20.1]`) and the
 * visitor sees nothing — which is exactly why the outcome has to be counted
 * here. Without it, an EMR that has stopped minting sessions looks identical
 * to a quiet afternoon.
 *
 * Neither the attribution payload nor the visitor's own address and agent are
 * recorded: they are the visitor's data, not this call's outcome (`[20.14]`).
 */
final class InstrumentedSessionGateway implements SessionGateway
{
    public function __construct(
        private readonly SessionGateway $inner,
        private readonly BoundaryTimer $timer,
    ) {
    }

    public function create(array $data, ?string $userAgent, ?string $clientIp): ?string
    {
        return $this->timer->measure(
            Boundary::EmrSession,
            fn (): ?string => $this->inner->create($data, $userAgent, $clientIp),
            static fn (?string $uuid): array => ['outcome' => $uuid === null ? 'failed' : 'created'],
            ['operation' => 'create'],
        );
    }

    public function view(string $uuid): ?array
    {
        return $this->timer->measure(
            Boundary::EmrSession,
            fn (): ?array => $this->inner->view($uuid),
            static fn (?array $view): array => ['outcome' => $view === null ? 'failed' : 'ok'],
            ['operation' => 'view', 'session' => $uuid],
        );
    }
}
