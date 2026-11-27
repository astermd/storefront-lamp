<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Emr;

use AsterMD\Storefront\Support\AfterResponse;

/**
 * Sends each session event after the response rather than during it.
 *
 * Both events are stateless — nothing the journey records depends on them
 * landing — which is the one condition {@see AfterResponse} sets. A page view
 * fires on every page, so sending it inline would put an EMR round trip in
 * front of every page the storefront serves.
 */
final class DeferredSessionEventGateway implements SessionEventGateway
{
    public function __construct(
        private readonly SessionEventGateway $inner,
        private readonly AfterResponse $after,
    ) {
    }

    public function pageView(string $session, PageView $page): void
    {
        $this->after->defer('page_view', fn () => $this->inner->pageView($session, $page));
    }

    public function beginIdentityVerification(string $session): void
    {
        $this->after->defer('begin_identity_verification', fn () => $this->inner->beginIdentityVerification($session));
    }
}
