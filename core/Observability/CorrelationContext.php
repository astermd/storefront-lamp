<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Observability;

/**
 * `[20.13]`'s correlation identifier, carried in request scope.
 *
 * Debugging one buyer's journey across the storefront, the EMR and the
 * provider without a shared identifier is guesswork. The analytics session
 * uuid already serves that role in payloads (`[13.22]`); the requirement is
 * that it appear in *logs*. There are roughly 137 operator-log call sites in
 * this application and about a quarter of them pass a session key, so the
 * requirement is met by making the identifier ambient rather than by editing
 * a hundred call sites — an edit that would be undone by the hundred and
 * first.
 *
 * **It resolves rather than being pushed.** The session uuid is not known when
 * the container is built and is not known at the start of a request either: it
 * is minted, adopted or re-minted partway through, by whichever middleware got
 * there first. A value captured at any fixed moment would be wrong for the
 * lines on one side of that moment. {@see \AsterMD\Storefront\Journey\JourneyStore::sessionUuid()}
 * is an in-memory getter, so asking it per log line costs nothing.
 *
 * **Three things can go wrong and all three answer null.** The resolver
 * reaches into the container, so it can throw where the database is gone —
 * which is precisely when the log line matters most, and a log line that
 * raised the outage it was recording would be worse than one missing a field
 * (`[20.1]`). It can also re-enter: a resolver whose own path logs would call
 * back into this object while it is answering, and the guard turns that into
 * a null instead of an unbounded recursion.
 */
final class CorrelationContext
{
    private ?string $sessionUuid = null;

    private bool $resolving = false;

    /** @var (\Closure(): ?string)|null */
    private readonly ?\Closure $resolver;

    /** @param (\Closure(): ?string)|null $resolver reads the journey's identifier, or null for a scope that has none */
    public function __construct(?\Closure $resolver = null)
    {
        $this->resolver = $resolver;
    }

    /**
     * Names the journey explicitly, for a scope with no request behind it.
     *
     * A scheduled sweep processes many journeys in one process and has no
     * ambient one; setting this around a batch is what lets its log lines join
     * the same trail as the request that placed the order.
     */
    public function set(?string $sessionUuid): void
    {
        $this->sessionUuid = $sessionUuid;
    }

    public function sessionUuid(): ?string
    {
        if ($this->sessionUuid !== null) {
            return $this->sessionUuid;
        }

        if ($this->resolver === null || $this->resolving) {
            return null;
        }

        $this->resolving = true;

        try {
            $resolved = ($this->resolver)();

            return is_string($resolved) && $resolved !== '' ? $resolved : null;
        } catch (\Throwable) {
            // See the class docblock: a missing field beats a raised outage.
            return null;
        } finally {
            $this->resolving = false;
        }
    }
}
