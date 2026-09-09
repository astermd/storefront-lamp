<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Journey;

use AsterMD\Storefront\Repository\SessionRepository;

/**
 * Request-scoped access to the current journey's durable state: load it
 * once, hand the same instance to everyone who asks, and write it back once
 * at the end of the request.
 *
 * The write-back compares a fingerprint taken at load time rather than
 * trusting callers to flag mutations, because the failure mode of a missed
 * dirty flag is silent data loss on a step the visitor believes they
 * completed. Loading a session this storefront has never seen creates the
 * row, which is what makes an adopted resume link work on a fresh
 * deployment.
 */
final class JourneyStore
{
    private ?string $uuid = null;

    private ?JourneyState $state = null;

    private ?string $fingerprint = null;

    public function __construct(private readonly SessionRepository $sessions)
    {
    }

    public function load(string $uuid): JourneyState
    {
        if ($this->uuid === $uuid && $this->state !== null) {
            return $this->state;
        }

        $row = $this->sessions->find($uuid);
        if ($row === null) {
            $this->sessions->insert($uuid, [], null);
            $row = ['journey_state' => [], 'attribution' => null, 'opportunity_id' => null];
        }

        $state = JourneyState::fromArray($row['journey_state'], $row['attribution'], $row['opportunity_id']);
        $this->adopt($uuid, $state);

        return $state;
    }

    /** Registers an already-built state (a session minted on this request). */
    public function adopt(string $uuid, JourneyState $state): void
    {
        $this->uuid = $uuid;
        $this->state = $state;
        $this->fingerprint = $state->fingerprint();
    }

    public function state(): ?JourneyState
    {
        return $this->state;
    }

    public function sessionUuid(): ?string
    {
        return $this->uuid;
    }

    /**
     * Persists the journey if anything changed.
     *
     * A write that matched no row is raised rather than returned, so it
     * reaches the one place that already knows how to report a failed journey
     * write to operators — {@see \AsterMD\Storefront\Http\Middleware\JourneyStateMiddleware},
     * which logs it and swallows it, because a lost analytics write must not
     * become the visitor's problem (`[20.1]`). Leaving the fingerprint
     * untouched on failure means a later flush in the same request tries
     * again instead of assuming the state is safely stored.
     *
     * @throws \RuntimeException when the row this journey belongs to is gone
     */
    public function flush(): void
    {
        if ($this->uuid === null || $this->state === null) {
            return;
        }

        $current = $this->state->fingerprint();
        if ($current === $this->fingerprint) {
            return;
        }

        $saved = $this->sessions->save(
            $this->uuid,
            $this->state->toArray(),
            $this->state->attribution?->toArray(),
            $this->state->opportunityId,
        );

        if (!$saved) {
            // Composed from the session uuid and nothing else, which is what
            // makes it safe to report in full. See {@see JourneyWriteFailed}
            // for why that is a type here rather than a convention.
            throw new JourneyWriteFailed(sprintf('Journey row for session %s no longer exists.', $this->uuid));
        }

        $this->fingerprint = $current;
    }
}
