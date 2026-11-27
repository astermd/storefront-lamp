<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Emr;

/**
 * The browsing events only the storefront can see: which page a visitor
 * opened, and that they started identity verification.
 *
 * An interface for the reason {@see SessionGateway} is one — a deployment with
 * EMR analytics off binds {@see NullSessionEventGateway} and every caller stays
 * the same. Neither method returns anything or throws: these are tracking
 * calls, and nothing on the page may depend on whether they landed (`[20.1]`).
 */
interface SessionEventGateway
{
    public function pageView(string $session, PageView $page): void;

    public function beginIdentityVerification(string $session): void;
}
