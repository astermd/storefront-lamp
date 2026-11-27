<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use AsterMD\Storefront\Emr\PageView;
use AsterMD\Storefront\Emr\SessionEventGateway;

/**
 * In-memory {@see SessionEventGateway}: records every call, in order, and
 * sends nothing. Bound in place of the deferred production gateway, so a test
 * sees the event the moment the request is handled.
 */
final class RecordingSessionEventGateway implements SessionEventGateway
{
    /** @var list<array{session: string, page: PageView}> */
    public array $pageViews = [];

    /** @var list<string> the session of each `begin_identity_verification` */
    public array $verificationsBegun = [];

    public function pageView(string $session, PageView $page): void
    {
        $this->pageViews[] = ['session' => $session, 'page' => $page];
    }

    public function beginIdentityVerification(string $session): void
    {
        $this->verificationsBegun[] = $session;
    }
}
