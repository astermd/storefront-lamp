<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

use AsterMD\Storefront\Support\OperatorLog;

/**
 * Evaluates a definition's conditional logic: one rule, a group of rules, and
 * the resolved state of a field.
 *
 * Pure by design — answers in, booleans out, no I/O — because this is the only
 * gate the storefront actually trusts. The hosted engine renders one of the two
 * modes and this class renders the other, and `[28.3]` requires the two to mean
 * the same thing, so every semantic here is copied from the engine rather than
 * chosen: a missing operator means `equals`, an operator nobody recognises
 * evaluates **false** instead of throwing (`[10.18]`), and comparisons against
 * a list-valued answer are membership tests rather than string comparisons.
 *
 * Two places where the engine differs from the spec's prose, and the engine
 * wins because the engine is what one of the modes runs:
 *
 * - A `logic` array carries one combinator **per join**, so `logic[i - 1]` is
 *   what joins the accumulated result with rule `i` and the array is one
 *   shorter than the rule list. Real definitions author `["and"]` for two
 *   rules, which the "position 0 is ignored" reading of `[10.16]` does not
 *   describe.
 * - Conditions default **inverted**: a field carrying a `show` condition starts
 *   invisible and a field carrying an `enable` condition starts disabled, so an
 *   authored `show` reveals rather than merely confirming.
 *
 * Numeric comparison is load-bearing rather than optional (`[10.41c]`): the
 * real weight-loss form hard-stops on `bmi_measurement less_than '27'`, a
 * threshold over a computed value, so both sides of every ordering comparison
 * run through the same coercion helper and dates compare chronologically.
 */
final class RuleEvaluator
{
    /** camelCase spellings the authoring UI emits, mapped onto the canonical snake_case set. */
    private const array OPERATOR_ALIASES = [
        'notEquals' => 'not_equals',
        'notContains' => 'not_contains',
        'isEmpty' => 'is_empty',
        'isNotEmpty' => 'is_not_empty',
        'notIn' => 'not_in',
    ];

    /** Actions that change a field's state. Everything else is presentation and is ignored server-side. */
    private const array HONOURED_ACTIONS = [
        'show', 'hide', 'enable', 'disable',
        'require', 'make_required', 'optional', 'make_optional',
    ];

    /**
     * Action names already reported, so a form that repeats a presentation-only
     * action on forty fields still produces one log line per distinct action.
     *
     * @var array<string, true>
     */
    private array $reportedActions = [];

    public function __construct(private readonly ?OperatorLog $log = null)
    {
    }

    /**
     * Evaluates one rule against the answers.
     *
     * `$subject` is the field the rule **reads** — resolved by the caller from
     * `$rule['field']` — and it is needed for two things. A `phone` field is
     * normalised on both sides, so a formatted answer still matches an
     * unformatted comparand. And a `null` subject means the definition does not
     * declare that field at all, which evaluates false even when an answer
     * exists under the name: a post-launch question rename orphans its answers,
     * and a rule that kept firing against an orphaned answer would disqualify
     * people using data no visible question produced (`[28.16]`).
     *
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $answers keyed by field name
     */
    public function rule(array $rule, array $answers, ?Field $subject): bool
    {
        if ($subject === null) {
            return false;
        }

        $operator = self::canonicalOperator($rule['operator'] ?? null);
        $answer = $answers[(string) ($rule['field'] ?? '')] ?? null;

        if ($operator === 'is_empty') {
            return self::isEmpty($answer);
        }

        if ($operator === 'is_not_empty') {
            return !self::isEmpty($answer);
        }

        $value = $rule['value'] ?? null;
        $phone = $subject->type === 'phone';

        if (is_array($answer)) {
            return self::listRule($operator, self::valueList($answer, $phone), self::valueList($value, $phone));
        }

        return self::scalarRule($operator, $answer, $value, $phone);
    }

    /**
     * Evaluates a whole condition group: its rules joined by its combinators.
     *
     * An empty rule list is **not** satisfied. A condition with nothing to test
     * is an authoring accident, and treating it as vacuously true would fire
     * every `show` and every hard stop that carried one.
     *
     * @param array<string, mixed> $condition
     * @param array<string, mixed> $answers keyed by field name
     */
    public function group(array $condition, array $answers, Definition $definition): bool
    {
        $rules = array_values(array_filter((array) ($condition['rules'] ?? []), 'is_array'));
        if ($rules === []) {
            return false;
        }

        $logic = $condition['logic'] ?? null;
        $result = $this->evaluate($rules[0], $answers, $definition);

        for ($i = 1, $count = count($rules); $i < $count; $i++) {
            $combinator = is_array($logic)
                ? strtolower(trim((string) ($logic[$i - 1] ?? 'and')))
                : strtolower(trim((string) ($logic ?? '')));

            $result = $combinator === 'or'
                ? $result || $this->evaluate($rules[$i], $answers, $definition)
                : $result && $this->evaluate($rules[$i], $answers, $definition);
        }

        return $result;
    }

    /**
     * Resolves one field's visibility, disabled and required flags for a given
     * set of answers.
     *
     * The authored flags are the starting point, then the engine's inverted
     * defaults apply — see the class docblock — and only then are the conditions
     * walked in authored order, each honoured action applied on match. Order
     * matters: the last condition to match a field wins, which is how an author
     * layers a broad `show` under a narrower `hide`.
     *
     * A field that ends up invisible or disabled is forced non-required, so
     * neither the browser nor the validator can block a submission on a control
     * the visitor cannot reach (`[10.19]`, `[10.39]`).
     *
     * @param array<string, mixed> $answers keyed by field name
     */
    public function state(Field $field, array $answers, Definition $definition): FieldState
    {
        $visible = !$field->hidden;
        $disabled = $field->disabled;
        $required = $field->required;

        foreach ($field->conditions as $condition) {
            $action = self::action($condition);

            if ($action === 'show') {
                $visible = false;
            } elseif ($action === 'enable') {
                $disabled = true;
            } elseif (!in_array($action, self::HONOURED_ACTIONS, true)) {
                $this->reportUnsupportedAction($action);
            }
        }

        foreach ($field->conditions as $condition) {
            $action = self::action($condition);

            if (!in_array($action, self::HONOURED_ACTIONS, true)) {
                continue;
            }

            if (!$this->group($condition, $answers, $definition)) {
                continue;
            }

            switch ($action) {
                case 'show':
                    $visible = true;
                    break;
                case 'hide':
                    $visible = false;
                    break;
                case 'enable':
                    $disabled = false;
                    break;
                case 'disable':
                    $disabled = true;
                    break;
                case 'require':
                case 'make_required':
                    $required = true;
                    break;
                case 'optional':
                case 'make_optional':
                    $required = false;
                    break;
            }
        }

        if (!$visible || $disabled) {
            $required = false;
        }

        return new FieldState(visible: $visible, disabled: $disabled, required: $required);
    }

    /** @param array<string, mixed> $rule */
    private function evaluate(array $rule, array $answers, Definition $definition): bool
    {
        return $this->rule($rule, $answers, $definition->field((string) ($rule['field'] ?? '')));
    }

    /**
     * The presentation-only actions — `apply_class_name`, `set_value`,
     * `show_modal` and friends — are deliberately ignored rather than
     * implemented, but silence would make a form that relies on one look as if
     * it had simply not fired. One line per distinct action is enough for an
     * operator to notice; no answer is ever named.
     */
    private function reportUnsupportedAction(string $action): void
    {
        if ($this->log === null || $action === '' || isset($this->reportedActions[$action])) {
            return;
        }

        $this->reportedActions[$action] = true;
        $this->log->info('intake.unsupported_action', ['action' => $action]);
    }

    /** @param array<string, mixed> $condition */
    private static function action(array $condition): string
    {
        return (string) ($condition['action'] ?? '');
    }

    /** An absent operator means `equals`, which is what the authoring UI omits when the author never changed it. */
    private static function canonicalOperator(mixed $operator): string
    {
        if (!is_string($operator) || trim($operator) === '') {
            return 'equals';
        }

        $operator = trim($operator);

        return self::OPERATOR_ALIASES[$operator] ?? $operator;
    }

    /**
     * Membership semantics for a list-valued answer, and the reason
     * `contains 'None of the above'` is false against `['none-of-the-above']`:
     * the comparison is against stored option values, not rendered labels.
     *
     * Operators outside the six that mean membership are false rather than
     * coerced — comparing a multi-select against a numeric threshold has no
     * meaning the engine would agree with.
     *
     * @param list<string> $selected
     * @param list<string> $comparands
     */
    private static function listRule(string $operator, array $selected, array $comparands): bool
    {
        $matched = false;
        foreach ($comparands as $comparand) {
            if (in_array($comparand, $selected, true)) {
                $matched = true;
                break;
            }
        }

        return match ($operator) {
            'equals', 'contains', 'in' => $matched,
            'not_equals', 'not_contains', 'not_in' => !$matched,
            default => false,
        };
    }

    private static function scalarRule(string $operator, mixed $answer, mixed $value, bool $phone): bool
    {
        $left = self::text($answer, $phone);
        $right = self::text($value, $phone);

        switch ($operator) {
            case 'equals':
                return $left === $right;
            case 'not_equals':
                return $left !== $right;
            case 'contains':
                return str_contains($left, $right);
            case 'not_contains':
                return !str_contains($left, $right);
            case 'in':
                return in_array($left, self::valueList($value, $phone), true);
            case 'not_in':
                return !in_array($left, self::valueList($value, $phone), true);
        }

        $a = self::numeric($answer);
        $b = self::numeric($value);

        if ($a === null || $b === null) {
            return false;
        }

        return match ($operator) {
            'greater_than' => $a > $b,
            'greater_than_or_equal' => $a >= $b,
            'less_than' => $a < $b,
            'less_than_or_equal' => $a <= $b,
            default => false,
        };
    }

    /** A `'0'` string is a real answer, so emptiness is about absence and whitespace only. */
    private static function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    /**
     * The comparand list: an authored array as it stands, a string split on
     * commas so `in`'s "a, b, c" spelling works, and anything else as a single
     * entry.
     *
     * @return list<string>
     */
    private static function valueList(mixed $value, bool $phone): array
    {
        if (is_array($value)) {
            return array_values(array_map(
                static fn (mixed $entry): string => self::text($entry, $phone),
                $value,
            ));
        }

        if (is_string($value)) {
            return array_values(array_map(
                static fn (string $part): string => self::text(trim($part), $phone),
                explode(',', $value),
            ));
        }

        if ($value === null) {
            return [];
        }

        return [self::text($value, $phone)];
    }

    /**
     * A comparable string. Booleans render as `true`/`false` rather than PHP's
     * `1`/`''`, because the authoring UI writes conditions against what the
     * engine's JavaScript would stringify.
     *
     * `$phone` normalises both sides of a phone comparison down to digits and
     * keeps the last ten, so a country code or punctuation on one side only
     * does not defeat the match.
     */
    private static function text(mixed $value, bool $phone): string
    {
        $text = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => '',
        };

        if (!$phone) {
            return $text;
        }

        $digits = preg_replace('/\D+/', '', $text) ?? '';

        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    private static function numeric(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_nan((float) $value) ? null : (float) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $text = trim($value);
        if ($text === '') {
            return null;
        }
        if (is_numeric($text)) {
            return (float) $text;
        }
        // A date is a legitimate comparand -- `[10.41c]` allows a threshold over a
        // derived value, and a date of birth is the commonest one. Both sides go
        // through this same helper, so seconds-vs-milliseconds never arises.
        if (preg_match('/[-\/:Ta-zA-Z]/', $text) === 1) {
            $timestamp = strtotime($text);

            return $timestamp === false ? null : (float) $timestamp;
        }

        return null;
    }
}
