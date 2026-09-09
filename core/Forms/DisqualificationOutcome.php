<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * What the disqualification rules said about one set of answers.
 *
 * A hard stop is at most one rule — the first that matched — because the
 * visitor is shown a single reason and the journey records a single rule id
 * (`[10.44]`). Advisories are a list and are reported *whether or not* a hard
 * stop fired: the two answer different questions, and a clinician reviewing a
 * terminated journey still wants the flags that were raised along the way.
 *
 * The outcome deliberately carries no verb. It says whether a journey may
 * proceed; redirecting, recording and rendering the reason belong to the funnel
 * and the controller (`[10.44a]`).
 */
final class DisqualificationOutcome
{
    /** @param list<TerminationRule> $advisories */
    private function __construct(
        private readonly ?TerminationRule $hard,
        private readonly array $advisories,
    ) {
    }

    /**
     * No hard stop matched: the journey may proceed, carrying whatever
     * advisories were raised on the way.
     *
     * @param list<TerminationRule> $advisories
     */
    public static function clear(array $advisories = []): self
    {
        return new self(null, $advisories);
    }

    /** @param list<TerminationRule> $advisories */
    public static function stop(TerminationRule $rule, array $advisories = []): self
    {
        return new self($rule, $advisories);
    }

    public function isHard(): bool
    {
        return $this->hard !== null;
    }

    /** The matched hard rule's id, which is what the journey stores so a resumed session stays terminated (`[10.44]`). */
    public function ruleId(): ?string
    {
        return $this->hard?->id;
    }

    /** The authored message for the matched rule — the visitor's reason, never a generic one (`[10.42]`). */
    public function message(): ?string
    {
        return $this->hard?->message;
    }

    /** @return list<TerminationRule> */
    public function advisories(): array
    {
        return $this->advisories;
    }
}
