<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * Everything a renderer needs to draw the form once, gathered so the
 * controller performs one read rather than each template fetching its own.
 *
 * `rules` is handed to the page so the client can show an ineligibility
 * notice the moment an answer is given (`[10.44]`) instead of a round trip
 * later. It carries no authority: the same rules are re-evaluated on the
 * server on every save and on submit (`[10.45]`), so a tampered client can
 * only change what the visitor sees, never whether they may proceed.
 */
final class IntakeView
{
    /**
     * @param array<string, string> $errors        field name => message, empty on a first render
     * @param list<string>          $unsupportedTypes types the renderer cannot draw (`[10.13]`)
     * @param list<TerminationRule> $rules
     */
    public function __construct(
        public readonly Definition $definition,
        public readonly TeleformMetadata $metadata,
        public readonly AnswerSet $answers,
        public readonly array $errors,
        public readonly ?string $terminationMessage,
        public readonly array $unsupportedTypes,
        public readonly array $rules,
    ) {
    }

    public function blocked(): bool
    {
        return $this->unsupportedTypes !== [];
    }
}
