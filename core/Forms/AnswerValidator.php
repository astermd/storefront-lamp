<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * Checks a set of answers against the definition that asked for them, on the
 * server, before anything is stored or forwarded.
 *
 * `[10.22]` is the hole this closes: validation used to live only in the
 * browser, so the server accepted whatever arrived — a required question could
 * be skipped, a constraint ignored, and the record still written. Everything
 * the client checks is therefore re-checked here, and this is the answer that
 * counts.
 *
 * Visibility is **not** re-derived. It is read from
 * {@see RuleEvaluator::state()}, the same resolution the renderer draws from,
 * so the two can never disagree about which controls were on screen. That is
 * what makes `[10.19]` hold: a required question sitting on a branch the
 * visitor's answers never revealed is not demanded, because demanding it would
 * block a submission on a control that was never shown. A disabled control is
 * skipped for the same reason.
 *
 * Two deliberate narrowings:
 *
 * - A constraint is applied **only when an answer is present**. An unanswered
 *   optional question is valid; `minLength` on an empty box is not a violation,
 *   it is a question the visitor chose not to answer.
 * - Undeclared field names are not re-checked. {@see AnswerSet::merge()}
 *   already drops anything the definition does not declare at the boundary
 *   where answers enter storage, so a name that reached this class is a name
 *   the form asked for.
 *
 * Messages are the visitor's, not the operator's (`[25.6]`): the authored
 * `validation.errorMessages` entry when the form supplies one, otherwise a
 * default that names the field's own label so the sentence still identifies
 * which question needs attention.
 */
final class AnswerValidator
{
    /**
     * The shape the hosted engine accepts, kept deliberately loose.
     *
     * `FILTER_VALIDATE_EMAIL` is stricter and disagrees with the engine on
     * real addresses, which would mean the server rejecting what one of the
     * two shipped renderers had already accepted — the visitor would be told
     * their address is wrong by a form that let them type it (`[28.3]`).
     */
    private const string EMAIL_SHAPE = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/';

    /**
     * Candidate delimiters for an authored `pattern`.
     *
     * Authored patterns are undelimited, because the engine compiles them with
     * `new RegExp(pattern)`. PCRE needs a delimiter, so the first character
     * the pattern does not itself contain is borrowed rather than escaping the
     * pattern — escaping is what turns an author's `\/` into something that
     * matches a different string than it did in the browser.
     *
     * @var list<string>
     */
    private const array PATTERN_DELIMITERS = ['/', '#', '~', '!', '%', '@'];

    public function __construct(private readonly RuleEvaluator $evaluator)
    {
    }

    /**
     * Every field that failed, as field name => message, empty when valid.
     *
     * One message per field: the visitor fixes one thing per box, and a list
     * of every simultaneous complaint about the same control is noise.
     *
     * `$pageIndex` is the step being advanced past, which validates that page
     * alone (`[10.20]`) — a later page's required questions are not yet
     * overdue. `null` is a final submit and validates every page, which is the
     * check that a form cannot be completed by posting the last step directly.
     *
     * @return array<string, string>
     */
    public function validate(Definition $definition, AnswerSet $answers, ?int $pageIndex = null): array
    {
        $values = $answers->all();
        $errors = [];

        foreach (self::fieldsUnderTest($definition, $pageIndex) as $field) {
            $message = $this->check($field, $values, $definition);
            if ($message !== null) {
                $errors[$field->name] = $message;
            }
        }

        return $errors;
    }

    /**
     * The fields one call is answerable for, subfields included — a composite's
     * parts are separately answered and separately constrained (`[10.7]`), so
     * validating only the parent would leave a height of 400 inches unchecked.
     *
     * @return list<Field>
     */
    private static function fieldsUnderTest(Definition $definition, ?int $pageIndex): array
    {
        $pages = $definition->pages();

        if ($pageIndex !== null) {
            $pages = isset($pages[$pageIndex]) ? [$pages[$pageIndex]] : [];
        }

        $fields = [];
        foreach ($pages as $page) {
            foreach ($page->fields as $field) {
                foreach (self::withSubfields($field) as $entry) {
                    $fields[] = $entry;
                }
            }
        }

        return $fields;
    }

    /** @return list<Field> */
    private static function withSubfields(Field $field): array
    {
        $fields = [$field];
        foreach ($field->subfields as $subfield) {
            foreach (self::withSubfields($subfield) as $nested) {
                $fields[] = $nested;
            }
        }

        return $fields;
    }

    /**
     * The one thing wrong with this field, or null when it is acceptable.
     *
     * @param array<string, mixed> $values
     */
    private function check(Field $field, array $values, Definition $definition): ?string
    {
        // Structural elements carry no answer at all, so there is nothing to
        // require and nothing to constrain (`[10.12]`).
        if ($field->isDisplayOnly()) {
            return null;
        }

        $state = $this->evaluator->state($field, $values, $definition);
        if (!$state->visible || $state->disabled) {
            return null;
        }

        $value = $values[$field->name] ?? null;

        if ($state->required && !self::answered($field, $value)) {
            return $this->message($field, 'required', sprintf('%s is required.', self::label($field)));
        }

        if (!self::present($value)) {
            return null;
        }

        return $this->constraintFailure($field, $value);
    }

    /**
     * Whether a required field has actually been answered.
     *
     * The test differs by type because "answered" does: a consent box must be
     * ticked rather than merely submitted, so nothing but `true` will do; a
     * multi-select needs at least one selection, since an empty list is a
     * question that was rendered and left alone; everything else must survive
     * trimming, so a spacebar is not an answer.
     */
    private static function answered(Field $field, mixed $value): bool
    {
        if ($field->isBoolean()) {
            return $value === true;
        }

        if ($field->isMultiValue()) {
            return is_array($value) && $value !== [];
        }

        return is_scalar($value) && trim((string) $value) !== '';
    }

    /** Whether there is an answer here for a constraint to act on at all. */
    private static function present(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }

        if (is_bool($value)) {
            return $value;
        }

        return is_scalar($value) && trim((string) $value) !== '';
    }

    /** The first authored constraint this answer breaks, or null. */
    private function constraintFailure(Field $field, mixed $value): ?string
    {
        $validation = $field->validation;
        $label = self::label($field);
        $text = is_scalar($value) ? (string) $value : '';
        $length = mb_strlen($text);

        $minLength = self::integer($validation['minLength'] ?? null);
        if ($minLength !== null && $length < $minLength) {
            return $this->message($field, 'minLength', sprintf('%s must be at least %d characters.', $label, $minLength));
        }

        $maxLength = self::integer($validation['maxLength'] ?? null);
        if ($maxLength !== null && $length > $maxLength) {
            return $this->message($field, 'maxLength', sprintf('%s must be %d characters or fewer.', $label, $maxLength));
        }

        if (self::breaksPattern($validation['pattern'] ?? null, $text)) {
            return $this->message($field, 'pattern', sprintf('%s is not in the expected format.', $label));
        }

        if ($field->type === 'email' && preg_match(self::EMAIL_SHAPE, $text) !== 1) {
            return $this->message($field, 'pattern', sprintf('%s must be a valid email address.', $label));
        }

        $number = is_numeric(trim($text)) ? (float) trim($text) : null;

        $min = self::number($validation['minValue'] ?? null);
        if ($number !== null && $min !== null && $number < $min) {
            return $this->message($field, 'min', sprintf('%s must be %s or more.', $label, self::plain($min)));
        }

        $max = self::number($validation['maxValue'] ?? null);
        if ($number !== null && $max !== null && $number > $max) {
            return $this->message($field, 'max', sprintf('%s must be %s or less.', $label, self::plain($max)));
        }

        $minItems = self::integer($validation['minItems'] ?? null);
        if ($minItems !== null && is_array($value) && count($value) < $minItems) {
            return $this->message($field, 'minItems', sprintf(
                'Select at least %d option%s for %s.',
                $minItems,
                $minItems === 1 ? '' : 's',
                $label,
            ));
        }

        return null;
    }

    /**
     * Whether an authored pattern rejects this answer.
     *
     * A pattern that will not compile is **ignored**, mirroring the engine's
     * `try`/`catch`: an author's typo in a regex would otherwise make the form
     * permanently unsubmittable, which is a far worse failure than an
     * unenforced format check. The match is suppressed rather than guarded
     * because an invalid pattern is a warning, not a return value.
     */
    private static function breaksPattern(mixed $pattern, string $value): bool
    {
        if (!is_string($pattern) || $pattern === '') {
            return false;
        }

        $delimited = self::delimit($pattern);
        if ($delimited === null) {
            return false;
        }

        return @preg_match($delimited, $value) === 0;
    }

    private static function delimit(string $pattern): ?string
    {
        foreach (self::PATTERN_DELIMITERS as $delimiter) {
            if (!str_contains($pattern, $delimiter)) {
                return $delimiter . $pattern . $delimiter;
            }
        }

        return null;
    }

    /** The authored message for this failure, or the supplied default. */
    private function message(Field $field, string $key, string $default): string
    {
        $authored = $field->validation['errorMessages'] ?? null;
        if (is_array($authored) && is_string($authored[$key] ?? null) && $authored[$key] !== '') {
            return $authored[$key];
        }

        return $default;
    }

    /** A field with no authored label is still named, by the name the form gave it. */
    private static function label(Field $field): string
    {
        return $field->label !== '' ? $field->label : $field->name;
    }

    private static function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /** A threshold as an author wrote it: `36`, not `36.0`. */
    private static function plain(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
