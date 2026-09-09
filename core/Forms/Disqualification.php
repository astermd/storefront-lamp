<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Support\OperatorLog;

/**
 * Reads a form's disqualification rules and says whether a set of answers may
 * proceed.
 *
 * Real forms do not declare the "termination rules" block `[10.42]` describes;
 * no such key exists anywhere in a definition. What authors actually write is an
 * **alert field revealed by a condition**, and all three parts the spec requires
 * are there under different names: the alert's own `conditions` are the rule,
 * `properties.alertText` is the message, and `properties.alertType` is the mode
 * — `danger` ends the journey, `warning` flags the answer for clinician review
 * and lets it continue (`[10.43]`). Any other alert type (`info`, `success`, or
 * none at all) is ordinary page copy and is not a rule: treating a reassuring
 * notice as a disqualifier would turn a form's own explanatory text into a
 * dead end.
 *
 * Because that reading is inferred from an authoring convention rather than
 * read from a declaration, it can turn out to be wrong. {@see self::DEFINITION_SHAPE}
 * is the lever for correcting it.
 *
 * The division of labour is `[10.44a]`'s: this class says *whether* a journey
 * may proceed and names the rule that decided it. It does not stop anything —
 * the funnel is what refuses the next step, redirects to the terminal page and
 * keeps a resumed session terminated. Nothing here writes state or performs
 * I/O beyond a log line, so the same evaluation can run on every progressive
 * save and every submit without cost (`[10.45]`).
 */
final class Disqualification
{
    /**
     * The generation of the alert-field reading below.
     *
     * A definition never says "this alert is a hard stop"; this class decides
     * that from a convention — a conditioned `alert` field plus an `alertType`.
     * If the convention turns out to have been read wrongly, a cached
     * definition read under the old reading would go on judging journeys by a
     * reader already known to be wrong, and a rule that silently never fires is
     * exactly the failure `[10.41c]` warns about. Bumping this constant makes
     * every cached definition re-read exactly once instead, the same way
     * {@see \AsterMD\Storefront\Journey\SessionResolver::READ_MODEL_SHAPE} does
     * for a journey's reconciled snapshot.
     *
     * Still generation 1: the reading was confirmed against the real published
     * form on 2026-08-23, whose thirteen conditioned alerts are its six hard
     * stops and seven review flags.
     */
    public const int DEFINITION_SHAPE = 1;

    /** The alert types that carry a rule, mapped onto the mode each one means. */
    private const array ALERT_MODES = [
        'danger' => TerminationRule::MODE_HARD,
        'warning' => TerminationRule::MODE_ADVISORY,
    ];

    /**
     * Operators that compare an answer against an authored value from the
     * field's own option list, and so can be checked for a comparand no option
     * can ever produce. The camelCase spellings are the ones the authoring UI
     * emits; an absent operator means `equals`, which is checkable too.
     */
    private const array COMPARAND_OPERATORS = [
        'equals', 'not_equals', 'contains', 'not_contains', 'in', 'not_in',
        'notEquals', 'notContains', 'notIn', '',
    ];

    /**
     * Comparand warnings already written, keyed by rule, field and operator, so
     * a form whose every rule is mis-authored still produces one line per
     * defect rather than one per progressive save.
     *
     * @var array<string, true>
     */
    private array $reportedUnmatchable = [];

    /** @var array<string, true> Teleforms already reported as falling back to configured rules. */
    private array $reportedFallback = [];

    public function __construct(
        private readonly RuleEvaluator $evaluator,
        private readonly Config $config,
        private readonly OperatorLog $log,
    ) {
    }

    /**
     * The rules governing one teleform, definition first.
     *
     * Configured rules are a fallback for a form that declares none, never a
     * supplement: merging the two would let a stale config entry keep
     * disqualifying people after the form author removed the question it reads,
     * so a form that authors its own rules is always authoritative.
     *
     * @return list<TerminationRule>
     */
    public function rulesFor(string $teleformId, Definition $definition): array
    {
        $rules = [];
        foreach ($definition->fields() as $field) {
            $rule = self::ruleFromAlert($field);
            if ($rule !== null) {
                $rules[] = $rule;
            }
        }

        if ($rules === []) {
            $rules = $this->configuredRules($teleformId);
        }

        $this->reportUnmatchableComparands($rules, $definition);

        return $rules;
    }

    /**
     * Evaluates every rule against the answers given so far.
     *
     * Hard stops are checked first and the first match wins, so an advisory
     * authored earlier in the form can never mask a hard stop authored later —
     * the visitor would have been allowed to continue on the strength of a rule
     * that only asked for a second opinion. Advisories are collected either way:
     * a clinician reviewing a terminated journey still wants to see them
     * (`[10.43]`).
     *
     * An unanswered question matches nothing, because every operator reads an
     * absent answer as absent rather than as a failed comparison. That is why a
     * visitor who has opened the form and typed nothing is not disqualified.
     */
    public function evaluate(string $teleformId, Definition $definition, AnswerSet $answers): DisqualificationOutcome
    {
        $rules = $this->rulesFor($teleformId, $definition);
        $values = $answers->all();

        $hard = null;
        foreach ($rules as $rule) {
            if ($rule->isHard() && $this->matches($rule, $values, $definition)) {
                $hard = $rule;
                break;
            }
        }

        $advisories = [];
        foreach ($rules as $rule) {
            if (!$rule->isHard() && $this->matches($rule, $values, $definition)) {
                $advisories[] = $rule;
            }
        }

        return $hard === null
            ? DisqualificationOutcome::clear($advisories)
            : DisqualificationOutcome::stop($hard, $advisories);
    }

    /**
     * A rule fires when **any** of its conditions is satisfied, matching what
     * makes the authored alert appear: each condition is an independent route to
     * the same message.
     *
     * @param array<string, mixed> $values answers keyed by field name
     */
    private function matches(TerminationRule $rule, array $values, Definition $definition): bool
    {
        foreach ($rule->conditions as $condition) {
            if ($this->evaluator->group($condition, $values, $definition)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Turns one field into a rule, or `null` if it is not one.
     *
     * Only `show` conditions are kept. A `hide` condition on an alert is
     * presentation — the author suppressing their own notice in some other
     * context — and honouring it as a disqualifier would fire a hard stop on
     * the answers that were meant to put the notice away. An alert with no
     * `show` condition at all is unconditional page copy, whatever its type.
     */
    private static function ruleFromAlert(Field $field): ?TerminationRule
    {
        if ($field->type !== 'alert') {
            return null;
        }

        $mode = self::ALERT_MODES[strtolower(trim((string) ($field->properties['alertType'] ?? '')))] ?? null;
        if ($mode === null) {
            return null;
        }

        $conditions = [];
        foreach ($field->conditions as $condition) {
            if (is_array($condition) && (string) ($condition['action'] ?? '') === 'show') {
                $conditions[] = $condition;
            }
        }

        if ($conditions === []) {
            return null;
        }

        $message = trim((string) ($field->properties['alertText'] ?? ''));

        return new TerminationRule(
            id: $field->id,
            mode: $mode,
            // The label is the fallback because an alert whose text lives in its
            // label still has something to show the visitor, and a blank reason
            // on a terminal page is worse than a terse one (`[10.44]`).
            message: $message !== '' ? $message : $field->label,
            conditions: $conditions,
        );
    }

    /**
     * The configured fallback for a form that authors no rules of its own.
     *
     * Each entry is authored in the same shape a definition uses — a `logic`
     * combinator over a list of `rules` — so the evaluator reads a configured
     * rule and an authored one identically. `rules` is required: an entry with
     * nothing to test would be a rule that either never fires or always does,
     * and neither is what an operator writing it meant. `mode` defaults to
     * `hard`, and anything other than `advisory` is read as `hard`, because a
     * mistyped mode should stop a journey for review rather than wave it
     * through.
     *
     * @return list<TerminationRule>
     */
    private function configuredRules(string $teleformId): array
    {
        $configured = $this->config->get('intake.disqualification.' . $teleformId);
        if (!is_array($configured)) {
            return [];
        }

        $rules = [];
        foreach (array_values($configured) as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $conditionRules = array_values(array_filter((array) ($entry['rules'] ?? []), 'is_array'));
            if ($conditionRules === []) {
                continue;
            }

            $id = trim((string) ($entry['id'] ?? ''));
            $mode = trim((string) ($entry['mode'] ?? TerminationRule::MODE_HARD));

            $rules[] = new TerminationRule(
                id: $id !== '' ? $id : 'configured_' . ($index + 1),
                mode: $mode === TerminationRule::MODE_ADVISORY
                    ? TerminationRule::MODE_ADVISORY
                    : TerminationRule::MODE_HARD,
                message: (string) ($entry['message'] ?? ''),
                conditions: [[
                    'action' => 'show',
                    'logic' => $entry['logic'] ?? 'and',
                    'rules' => $conditionRules,
                ]],
            );
        }

        if ($rules !== [] && !isset($this->reportedFallback[$teleformId])) {
            $this->reportedFallback[$teleformId] = true;
            $this->log->info('intake.disqualification_from_config', [
                'teleform_id' => $teleformId,
                'rules' => count($rules),
            ]);
        }

        return $rules;
    }

    /**
     * Warns about a rule that compares a choice field against a value none of
     * its options can produce.
     *
     * This is the live authoring defect `[10.41c]` predicted, and the real
     * staging form has it on every rule: the conditions compare against option
     * *labels* (`equals 'Yes'`) while the options store lowercase *values*
     * (`'yes'`), so not one hard stop in that form can fire. The storefront
     * cannot correct another system's form, but a hard stop that silently never
     * fires must not also be invisible.
     *
     * Only equality-family operators against a field that declares options are
     * checkable; a numeric threshold or a free-text field has no vocabulary to
     * check against and is skipped rather than guessed at. The line names the
     * rule, the field and the operator and **never the comparand**, which in a
     * medical form is very often the name of a condition (`[20.6]`).
     *
     * @param list<TerminationRule> $rules
     */
    private function reportUnmatchableComparands(array $rules, Definition $definition): void
    {
        foreach ($rules as $rule) {
            foreach ($rule->conditions as $condition) {
                foreach ((array) ($condition['rules'] ?? []) as $conditionRule) {
                    if (is_array($conditionRule)) {
                        $this->checkComparand($rule, $conditionRule, $definition);
                    }
                }
            }
        }
    }

    /** @param array<string, mixed> $conditionRule */
    private function checkComparand(TerminationRule $rule, array $conditionRule, Definition $definition): void
    {
        $operator = trim((string) ($conditionRule['operator'] ?? ''));
        if (!in_array($operator, self::COMPARAND_OPERATORS, true)) {
            return;
        }

        $field = $definition->field((string) ($conditionRule['field'] ?? ''));
        if ($field === null) {
            return;
        }

        $options = $field->options();
        if ($options === []) {
            return;
        }

        $comparands = self::comparands($conditionRule['value'] ?? null);
        if ($comparands === []) {
            return;
        }

        $values = array_map(static fn (array $option): string => $option['value'], $options);
        foreach ($comparands as $comparand) {
            if (in_array($comparand, $values, true)) {
                return;
            }
        }

        $key = $rule->id . '|' . $field->name . '|' . $operator;
        if (isset($this->reportedUnmatchable[$key])) {
            return;
        }

        $this->reportedUnmatchable[$key] = true;
        $this->log->warning('intake.termination_rule_unmatchable', [
            'rule' => $rule->id,
            'field' => $field->name,
            'operator' => $operator === '' ? 'equals' : $operator,
        ]);
    }

    /**
     * The values one condition rule compares against, as the evaluator reads
     * them: an authored list as it stands, and a string split on commas so
     * `in`'s "a, b, c" spelling is checked entry by entry rather than whole.
     *
     * @return list<string>
     */
    private static function comparands(mixed $value): array
    {
        if (is_array($value)) {
            $comparands = array_map(
                static fn (mixed $entry): string => is_scalar($entry) ? trim((string) $entry) : '',
                array_values($value),
            );
        } elseif (is_string($value)) {
            $comparands = array_map('trim', explode(',', $value));
        } elseif (is_scalar($value)) {
            $comparands = [trim((string) $value)];
        } else {
            return [];
        }

        return array_values(array_filter($comparands, static fn (string $entry): bool => $entry !== ''));
    }
}
