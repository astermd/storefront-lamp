<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Support;

use AsterMD\Storefront\Repository\RateLimitRepository;

/**
 * A fixed-window counter per `(bucket, identity)`, kept in the database.
 *
 * **State plainly what this stops and what it does not.** The identity is the
 * client address, so it throttles one caller coming from one address: a stuck
 * retry loop, a double-click storm, a script someone pointed at checkout. A
 * distributed caller is not counted, a shared office NAT counts many people as
 * one, and nothing here inspects what is being submitted. It is a flood guard,
 * not a fraud control, and it must never be described as one.
 *
 * What it does have over {@see RateLimiter}, which stays bound to the intake
 * capture endpoint it was written for, is an identity the caller cannot throw
 * away. That limiter's counters live in `$_SESSION`, so dropping a cookie
 * bought a fresh allowance; nothing a browser does resets a database row keyed
 * on the address the request arrived from (`[13.8]`, `[29.23]`).
 *
 * A fixed window rather than a sliding one, for {@see RateLimitRepository}'s
 * reason: a sliding window needs the timestamp of every individual hit, and a
 * durable list of when each address touched checkout is a log of visitor
 * behaviour this storefront has no reason to hold. The cost is the usual one,
 * up to twice the limit across a window boundary, and it does not matter at
 * these limits.
 *
 * **It fails open.** Any database error allows the call and is logged once.
 * `[20.1]` puts the judgement plainly: an outage must not become a storefront
 * that cannot take money, and a limiter that failed closed would turn a
 * database blip into a total checkout failure — a far worse outcome than an
 * unthrottled minute. Note that this is the limiter's rule alone; the submit
 * path itself refuses when it cannot record an order, because a charge nobody
 * can reconcile is worse than a refused checkout.
 *
 * The clock is injected so tests can advance time instead of sleeping.
 */
final class DatabaseRateLimiter
{
    /** @var callable(): int */
    private readonly mixed $clock;

    /** Logged once per instance, so an outage does not also become a log flood. */
    private bool $reportedUnavailable = false;

    /** @var array<string, true> buckets already reported as unconfigured */
    private array $reportedUnconfigured = [];

    /**
     * @param array<string, array{limit: int, window_seconds: int}> $policies keyed by bucket name,
     *                                                                       as `payment.rate_limits` holds them
     * @param (callable(): int)|null $clock the current unix timestamp; defaults to the real one
     */
    public function __construct(
        private readonly RateLimitRepository $limits,
        private readonly array $policies,
        private readonly OperatorLog $log,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Whether this call is within the bucket's allowance, counting it if the
     * counter could be read at all.
     *
     * A refusal deliberately does not extend the window: the count rises but
     * the window's start does not move, so a caller that keeps hammering is
     * refused for the remainder of the current window and no longer. An honest
     * visitor who tripped the limit recovers on their own, with no operator
     * involvement.
     *
     * A bucket with no configured policy is allowed and reported once. The
     * alternative — refusing, or throwing — would turn a config typo into a
     * checkout nobody can complete, and the operator log is the right place
     * for a misconfiguration that silently disarms a guard.
     */
    public function allow(string $bucket, string $identity): bool
    {
        $policy = $this->policies[$bucket] ?? null;

        if ($policy === null) {
            if (!isset($this->reportedUnconfigured[$bucket])) {
                $this->reportedUnconfigured[$bucket] = true;
                $this->log->warning('rate_limit_bucket_unconfigured', ['bucket' => $bucket]);
            }

            return true;
        }

        $window = max(1, (int) $policy['window_seconds']);
        $limit = max(0, (int) $policy['limit']);

        try {
            $hits = $this->limits->hit($bucket, $identity, $window, ($this->clock)());
        } catch (\Throwable $error) {
            if (!$this->reportedUnavailable) {
                $this->reportedUnavailable = true;
                // The identity is deliberately absent: this line says the
                // guard is down, which is an infrastructure fact, and does not
                // need a visitor's address attached to say it.
                $this->log->warning('rate_limit_unavailable', ['bucket' => $bucket, 'reason' => $error->getMessage()]);
            }

            return true;
        }

        return $hits <= $limit;
    }
}
