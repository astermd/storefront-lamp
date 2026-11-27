<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Observability;

use AsterMD\Storefront\Emr\PageView;
use AsterMD\Storefront\Emr\SessionEventGateway;

/**
 * `[20.9]` over the session-event boundary.
 *
 * The page type is logged and the path is not: a product path names the
 * product, and on this storefront that is a prescription.
 */
final class InstrumentedSessionEventGateway implements SessionEventGateway
{
    public function __construct(
        private readonly SessionEventGateway $inner,
        private readonly BoundaryTimer $timer,
    ) {
    }

    public function pageView(string $session, PageView $page): void
    {
        $this->timer->measure(
            Boundary::SessionEvent,
            fn () => $this->inner->pageView($session, $page),
            null,
            ['operation' => 'page_view', 'session' => $session, 'page_type' => $page->type->value],
        );
    }

    public function beginIdentityVerification(string $session): void
    {
        $this->timer->measure(
            Boundary::SessionEvent,
            fn () => $this->inner->beginIdentityVerification($session),
            null,
            ['operation' => 'begin_identity_verification', 'session' => $session],
        );
    }
}
