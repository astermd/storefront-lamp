<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * What one field's conditions resolved to for one set of answers.
 *
 * Three booleans rather than a bag of flags, because these are the only three
 * outcomes the honoured condition actions can produce, and both the renderer
 * and the validator need exactly them: the renderer to decide what to draw and
 * what to mark required, the validator to decide what it is allowed to demand.
 * They travel together so the two can never disagree — a hidden field that the
 * validator still believed required would block a submission on a control the
 * visitor cannot see (`[10.19]`, `[10.39]`).
 *
 * Immutable: {@see RuleEvaluator::state()} resolves the whole field at once, so
 * there is no legitimate reason for a caller to adjust one flag afterwards.
 */
final class FieldState
{
    public function __construct(
        public readonly bool $visible = true,
        public readonly bool $disabled = false,
        public readonly bool $required = false,
    ) {
    }
}
