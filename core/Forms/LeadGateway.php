<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * The storefront's view of the EMR's opportunity resource: create a lead, then
 * amend it as more of the form is answered (`[9.7]`).
 *
 * An interface for the same reason {@see \AsterMD\Storefront\Emr\CartGateway}
 * is one — analytics-off deployments and the test suite bind a null
 * implementation and every caller above stays identical.
 *
 * Both methods report only *what happened*, never decide what should. The
 * readiness threshold (`[9.9]`) and the swallow-and-log policy live in
 * {@see LeadWriter}; a gateway that also judged whether a write was worth
 * making would put the same rule in two places, and the payload here is the
 * visitor's name, email and clinical answers, so implementations must keep it
 * out of any log line.
 */
interface LeadGateway
{
    /**
     * Creates the opportunity and returns its new id, or null when the write
     * failed or the response carried no recoverable id.
     *
     * The id is the whole point of the return value: without it the journey can
     * never update this lead again (`[9.10]`).
     *
     * @param array<string, mixed> $payload the `opportunity`-rooted record, as {@see RecordMapper} builds it
     */
    public function create(array $payload): ?string;

    /**
     * Amends an existing opportunity. Returns whether the EMR accepted it.
     *
     * @param array<string, mixed> $payload the record rebuilt in full (`[11.5]`), not a delta
     */
    public function update(string $opportunityId, array $payload): bool;

    /**
     * Whether this gateway writes anywhere at all.
     *
     * It exists so that "nothing was written because nothing is configured to
     * be" can be told apart from "a write was attempted and failed". Without
     * it the two are the same null, and a deployment with analytics switched
     * off logs a lead failure on every capture — which is the kind of false
     * positive that makes an operator stop trusting the very alert `[20.10]`
     * asks them to watch.
     */
    public function writes(): bool;
}
