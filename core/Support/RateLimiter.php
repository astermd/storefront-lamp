<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Support;

/**
 * A fixed-window counter per key, kept in the visitor's own PHP session.
 *
 * **State the limitation plainly, because the name promises more than this
 * delivers.** The counters live in `$_SESSION`, so the limit is per browser
 * session and is bypassed by discarding the cookie: anyone willing to start a
 * fresh session gets a fresh allowance, and a distributed caller was never
 * counted in the first place. That is adequate for what it is used for — the
 * intake form's early-capture endpoint, where the thing being prevented is one
 * visitor's script or a stuck retry loop flooding a lead write. It is **not**
 * adequate for checkout, promo-code enumeration, or anything else where the
 * caller is motivated to get around it (`[13.8]`, `[29.23]`); use
 * {@see DatabaseRateLimiter} there, which counts against the client address in
 * a database row rather than against a cookie the caller can throw away.
 *
 * A fixed window (rather than a sliding one) is chosen for the same reason:
 * two integers per key, no history to store, and its known weakness — up to
 * twice the limit across a window boundary — does not matter when the goal is
 * stopping a flood rather than enforcing a quota.
 *
 * The clock is injected so tests can advance time instead of sleeping.
 */
final class RateLimiter
{
    /**
     * One namespaced key so the counters cannot collide with journey or cart
     * state, and so clearing them is a single unset.
     */
    private const string SESSION_KEY = '_amd_rate_limits';

    /** @var callable(): int */
    private readonly mixed $clock;

    /**
     * @param int $limit         how many calls one key may make inside a window
     * @param int $windowSeconds how long a window lasts before the count resets
     * @param (callable(): int)|null $clock the current unix timestamp; defaults to the real one
     */
    public function __construct(
        private readonly int $limit,
        private readonly int $windowSeconds,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Whether this call is within the allowance, counting it if it is.
     *
     * A refusal deliberately does not extend the window — a caller that keeps
     * hammering is refused for the remainder of the current window and no
     * longer, so an honest visitor who tripped the limit recovers on their own
     * without any operator involvement.
     */
    public function allow(string $key): bool
    {
        $now = ($this->clock)();
        /** @var array<string, array{count: int, window_start: int}> $limits */
        $limits = is_array($_SESSION[self::SESSION_KEY] ?? null) ? $_SESSION[self::SESSION_KEY] : [];

        $entry = $limits[$key] ?? null;
        $started = is_array($entry) && is_int($entry['window_start'] ?? null) ? $entry['window_start'] : null;
        $count = is_array($entry) && is_int($entry['count'] ?? null) ? $entry['count'] : 0;

        // A window that has run out — or a clock that went backwards, which a
        // machine's time correction can do — starts a fresh one.
        if ($started === null || $now - $started >= $this->windowSeconds || $now < $started) {
            $started = $now;
            $count = 0;
        }

        $allowed = $count < $this->limit;

        if ($allowed) {
            ++$count;
        }

        $limits[$key] = ['count' => $count, 'window_start' => $started];
        $_SESSION[self::SESSION_KEY] = $limits;

        return $allowed;
    }
}
