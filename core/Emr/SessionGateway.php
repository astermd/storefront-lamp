<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Emr;

/**
 * The storefront's whole view of the EMR's analytics-session lifecycle.
 *
 * Everything the request path needs from the EMR about sessions goes through
 * these two calls, for three reasons: session tracking must degrade to "no
 * session" rather than break a page (`[4.5]`, `[20.1]`), so exactly one
 * layer is allowed to swallow; the return-visit reconciliation must be a
 * single read rather than one call per fact (`[4.14]`), which is only
 * enforceable if there is one method to call; and the test suite must never
 * reach the network, which a null implementation of an interface gives for
 * free.
 */
interface SessionGateway
{
    /**
     * Mints a new analytics session, forwarding the visitor's own agent and
     * IP so the EMR attributes it to their device rather than to this server
     * (`[4.3]`).
     *
     * @param array<string, mixed> $data first-touch attribution to record on the session (`[4.4]`)
     *
     * @return string|null the new session uuid, or null on any failure — never a synthetic id (`[20.8]`)
     */
    public function create(array $data, ?string $userAgent, ?string $clientIp): ?string;

    /**
     * Reads the server-recorded state of a session once: whether it exists,
     * the opportunity it is linked to, and which lifecycle events have
     * already fired (`[4.12]`).
     *
     * @return array{exists: bool, opportunity_id: ?string, events: list<string>}|null null when the read failed
     */
    public function view(string $uuid): ?array;
}
