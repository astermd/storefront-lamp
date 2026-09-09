<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * Flattens one authored field, its resolved state and its current answer into
 * the single array shape every `partials/intake/*` template reads.
 *
 * The type-to-partial branching lives here rather than in Twig on purpose. A
 * template that reasoned about field types would put the type vocabulary in
 * two places — {@see FieldTypes} and the template — and the second copy is
 * always the one that rots: a type added to the vocabulary later would keep
 * rendering through whichever branch the template happened to fall into,
 * silently, instead of reaching `field-unsupported.twig`. So the templates are
 * told which partial to include and read nothing but this array; `field.twig`
 * dispatches on `template` and never on `type`.
 *
 * The table deliberately collapses more types than it distinguishes. The four
 * text-family types share one partial and differ only by `input_type`, so the
 * on-screen keyboard matches the question, and the four consent-shaped types
 * share `field-boolean.twig` because a checkbox, a toggle, a terms box and an
 * agreement box are the same control with different wording.
 *
 * Every key is present for every field, whatever its type. A partial reached
 * through the dispatcher's `include ... only` has no access to anything else,
 * and an absent key would be a render-time failure on a page a visitor is
 * mid-way through answering rather than something a test would catch.
 */
final class FieldViewModel
{
    /**
     * The text-family types and the `type` attribute each one wants, so the
     * device offers an email keyboard for an email and a dial pad for a phone
     * (`[25.9]`). Anything outside this table is plain `text`.
     */
    private const array INPUT_TYPES = [
        'text' => 'text',
        'email' => 'email',
        'phone' => 'tel',
        'password' => 'password',
    ];

    /**
     * Types whose partial is not named after the type. Everything absent here
     * resolves to `field-<type>.twig`, which is why `form-progress` and
     * `form-header` need no entry and `picker-date` does.
     */
    private const array TEMPLATE_ALIASES = [
        'email' => 'text',
        'phone' => 'text',
        'password' => 'text',
        'picker-date' => 'date',
        'checkbox' => 'boolean',
        'toggle' => 'boolean',
        'terms' => 'boolean',
        'agreement' => 'boolean',
    ];

    /**
     * Strings a boolean field's stored answer may hold that still mean "no".
     * `RuleEvaluator` stringifies booleans as `true`/`false`, so a round-tripped
     * answer can arrive as the word rather than as a falsy PHP value, and a
     * consent box that ticked itself back on would be consent nobody gave
     * (`[26.2]`).
     */
    private const array FALSE_TEXTS = ['', '0', 'false'];

    /**
     * Builds the array a partial renders.
     *
     * `$answer` is the field's own stored answer, except for a `bmi`
     * composite: its result is two stored answers — the score under the
     * composite's name and the band under `<name>_category` (`[10.32]`) — and
     * the band is data, not something the renderer may recompute, so a
     * composite may be handed the answers keyed by name instead of a bare
     * score. Subfield answers are read from that same map.
     *
     * `$state` is the *resolved* state from {@see RuleEvaluator::state()}, and
     * `required`/`hidden`/`disabled` come from it rather than from the
     * authored flags, so a field a condition concealed cannot be marked
     * required in the markup (`[10.19]`).
     *
     * @return array<string, mixed>
     */
    public static function for(Field $field, FieldState $state, mixed $answer, ?string $error): array
    {
        $composite = $field->type === 'bmi' && is_array($answer) ? $answer : [];
        $own = $composite === [] ? $answer : ($composite[$field->name] ?? null);

        return [
            'id' => $field->id,
            'name' => $field->name,
            'label' => $field->label,
            'type' => $field->type,
            'description' => $field->description,
            'placeholder' => $field->placeholder,
            'template' => self::template($field),
            'input_type' => self::INPUT_TYPES[$field->type] ?? 'text',
            'required' => $state->required,
            'hidden' => !$state->visible,
            'disabled' => $state->disabled,
            'readonly' => $field->readOnly,
            'cols' => $field->cols(),
            'options' => self::options($field, $own),
            'value' => self::value($field, $own),
            'error' => $error,
            // Only minted when there is something to point at: an
            // `aria-describedby` naming an empty node is a screen reader
            // announcing nothing (`[25.4]`).
            'error_id' => $error === null ? null : 'intake-error-' . $field->name,
            'alert_type' => self::alertType($field),
            'alert_text' => self::optionalText($field->properties['alertText'] ?? null),
            'category' => $field->type === 'bmi'
                ? self::optionalText($composite[$field->name . '_category'] ?? null)
                : null,
            'image_url' => $field->type === 'image' ? self::imageUrl($field) : null,
            'button_action' => $field->type === 'button'
                ? (self::optionalText($field->properties['buttonAction'] ?? null) ?? 'next_page')
                : null,
            'max_length' => self::constraint($field, 'maxLength'),
            'min_value' => self::constraint($field, 'minValue'),
            'max_value' => self::constraint($field, 'maxValue'),
            'subfields' => self::subfields($field, $composite),
            // The field's own conditions, for the stepper to re-evaluate as
            // answers change. Encoded here rather than in the template so the
            // escaping is a property of the value: with the tag, ampersand and
            // quote flags set it cannot break out of the attribute it sits in.
            'conditions_json' => $field->conditions === [] ? null : (string) json_encode(
                $field->conditions,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES,
            ),
        ];
    }

    /**
     * An unsupported type is checked before the table rather than after, so a
     * type that merely happens to share a name with a partial on disk cannot
     * sneak past the default-deny vocabulary (`[10.13]`).
     */
    private static function template(Field $field): string
    {
        if (!$field->isSupported()) {
            return 'field-unsupported.twig';
        }

        return 'field-' . (self::TEMPLATE_ALIASES[$field->type] ?? $field->type) . '.twig';
    }

    /**
     * The answer as the partial needs it: a string a text input can carry, a
     * boolean a checkbox can be checked from, a list a multi-select can test
     * against, or — for a composite — a float score, with `null` distinguishing
     * "no score yet" so the result line can stay hidden until there is one.
     */
    private static function value(Field $field, mixed $answer): mixed
    {
        if ($field->type === 'bmi') {
            return is_numeric($answer) ? (float) $answer : null;
        }

        if ($field->isBoolean()) {
            return is_string($answer)
                ? !in_array(strtolower(trim($answer)), self::FALSE_TEXTS, true)
                : (bool) $answer;
        }

        if ($field->isMultiValue()) {
            return self::valueList($answer);
        }

        return $answer ?? '';
    }

    /**
     * The authored options with the current selection marked. A multi-value
     * field's answer is a list and is tested by membership; every other field
     * holds one value and is compared against it directly (`[10.36]`).
     *
     * @return list<array{value: string, label: string, selected: bool}>
     */
    private static function options(Field $field, mixed $answer): array
    {
        $selected = $field->isMultiValue() ? self::valueList($answer) : null;
        $scalar = $selected === null ? self::scalarText($answer) : null;

        $options = [];
        foreach ($field->options() as $option) {
            $options[] = [
                'value' => $option['value'],
                'label' => $option['label'],
                'selected' => $selected === null
                    ? ($scalar !== null && $scalar === $option['value'])
                    : in_array($option['value'], $selected, true),
            ];
        }

        return $options;
    }

    /**
     * A composite's parts as full view models, so `field-bmi.twig` can recurse
     * back through the dispatcher and get the same markup a top-level field of
     * that type would get.
     *
     * Their state is the authored state rather than a resolved one: conditions
     * attach to fields, not to subfields, and this class is handed one resolved
     * state. The forced-non-required rule is mirrored from
     * {@see RuleEvaluator::state()} anyway, so a concealed subfield can never
     * be the thing that blocks a submission (`[10.19]`).
     *
     * @param array<string, mixed> $answers the composite's answers keyed by name
     * @return list<array<string, mixed>>
     */
    private static function subfields(Field $field, array $answers): array
    {
        $models = [];
        foreach ($field->subfields as $subfield) {
            $visible = !$subfield->hidden;
            $models[] = self::for(
                $subfield,
                new FieldState(
                    visible: $visible,
                    disabled: $subfield->disabled,
                    required: $visible && !$subfield->disabled && $subfield->required,
                ),
                $answers[$subfield->name] ?? null,
                null,
            );
        }

        return $models;
    }

    /** An unauthored notice falls back to the one treatment that cannot overstate itself. */
    private static function alertType(Field $field): string
    {
        return self::optionalText($field->properties['alertType'] ?? null) ?? 'info';
    }

    /** Two spellings are in the wild for the same property, and neither is wrong. */
    private static function imageUrl(Field $field): ?string
    {
        return self::optionalText($field->properties['imageUrl'] ?? null)
            ?? self::optionalText($field->properties['url'] ?? null);
    }

    /**
     * An authored constraint as a number, or null when it is absent or is not
     * one — a `maxlength=""` or `min=""` in the markup would be a constraint
     * the browser cannot read.
     */
    private static function constraint(Field $field, string $key): int|float|null
    {
        $value = $field->validation[$key] ?? null;

        return is_numeric($value) ? $value + 0 : null;
    }

    /** @return list<string> */
    private static function valueList(mixed $value): array
    {
        if (is_array($value)) {
            $list = [];
            foreach ($value as $entry) {
                $text = self::scalarText($entry);
                if ($text !== null) {
                    $list[] = $text;
                }
            }

            return $list;
        }

        $text = self::scalarText($value);

        return $text === null ? [] : [$text];
    }

    private static function optionalText(mixed $value): ?string
    {
        $text = self::scalarText($value);

        return $text === null || trim($text) === '' ? null : $text;
    }

    private static function scalarText(mixed $value): ?string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => null,
        };
    }
}
