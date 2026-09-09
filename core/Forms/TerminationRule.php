<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * One disqualification rule: the three things `[10.42]` requires a rule to
 * declare — a condition, a message and a mode — held together as a value.
 *
 * Modes are two rather than a boolean because the difference is clinical, not
 * technical. A `hard` rule ends the journey; an `advisory` one records that a
 * clinician has to look at this answer and lets the visitor carry on, so the
 * two must never collapse into "disqualified" (`[10.42]`, `[10.43]`).
 *
 * `$conditions` stays in the raw authored shape rather than being parsed into
 * something of its own, because {@see RuleEvaluator::group()} is the only thing
 * that ever reads it and it is the same evaluator that resolves field state —
 * one set of combinator and operator semantics governs both, which is what
 * keeps a rule from meaning one thing to the renderer and another to the gate.
 * More than one condition means any of them firing is enough: each is an
 * independent way the authored alert becomes visible.
 */
final class TerminationRule
{
    public const string MODE_HARD = 'hard';

    public const string MODE_ADVISORY = 'advisory';

    /** @param list<array<string, mixed>> $conditions condition groups in authored shape, any one of which fires the rule */
    public function __construct(
        public readonly string $id,
        public readonly string $mode,
        public readonly string $message,
        public readonly array $conditions,
    ) {
    }

    public function isHard(): bool
    {
        return $this->mode === self::MODE_HARD;
    }
}
