<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * What happened to one save or one submit.
 *
 * The four outcomes are deliberately distinct rather than collapsed into a
 * boolean plus a message bag, because the funnel treats them differently and
 * conflating two of them is how a visitor ends up in the wrong place:
 * validation errors keep someone on the step to fix them, a stop takes them
 * off the funnel entirely, a plain save advances, and only a finished form
 * opens the next step.
 *
 * A stop carries no field errors on purpose. Someone who answered honestly
 * and is not eligible has not filled the form in wrongly, and telling them so
 * alongside the refusal reads as though correcting a field might change the
 * answer (`[10.43]`).
 */
final class IntakeResult
{
    /** @param array<string, string> $errors field name => message */
    private function __construct(
        public readonly array $errors,
        public readonly ?string $ruleId,
        public readonly ?string $message,
        public readonly bool $completed,
    ) {
    }

    /** @param array<string, string> $errors */
    public static function invalid(array $errors): self
    {
        return new self($errors, null, null, false);
    }

    public static function stopped(string $ruleId, string $message): self
    {
        return new self([], $ruleId, $message, false);
    }

    public static function saved(): self
    {
        return new self([], null, null, false);
    }

    public static function finished(): self
    {
        return new self([], null, null, true);
    }

    public function disqualified(): bool
    {
        return $this->ruleId !== null;
    }

    public function valid(): bool
    {
        return $this->errors === [];
    }
}
