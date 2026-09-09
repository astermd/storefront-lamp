<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * Rewrites an answer set as the field list the intake-submission API stores.
 *
 * The wire shape is not ours to choose: each answer travels as a self-
 * describing entry — its id, name, label, type and a **list** of values — so
 * the submission stays readable years later, after the form has been
 * republished and its questions reworded. A bare name-to-value map would leave
 * a clinician reading an answer with no record of what was asked. The list
 * wrapper is what lets one field carry several selections.
 *
 * Choice answers carry their option **label** beside the stored value for the
 * same reason: `value` is a slug the form author chose and `label` is the
 * sentence the visitor actually read.
 *
 * Three exclusions, all of them `[10.12]`: a display-only element has no
 * answer to send, a field the form marked `sendToProvider: false` was asked for
 * the storefront's own purposes rather than the record's, and an unanswered
 * field is left out entirely rather than sent as an empty string — a blank in
 * the record reads as "answered, and the answer was nothing". An answer set
 * with nothing to send encodes as `[]`, which is what the API expects rather
 * than an omitted key.
 */
final class AnswerEncoder
{
    /**
     * @return list<array{id: string, name: string, label: string, type: string, value: list<array<string, mixed>>}>
     */
    public static function encode(Definition $definition, AnswerSet $answers): array
    {
        $encoded = [];

        foreach ($definition->fields() as $field) {
            if ($field->isDisplayOnly() || !$field->sendToProvider) {
                continue;
            }

            $values = self::values($field, $answers->value($field->name));
            if ($values === []) {
                continue;
            }

            $encoded[] = [
                'id' => $field->id,
                'name' => $field->name,
                'label' => $field->label,
                'type' => $field->type,
                'value' => $values,
            ];
        }

        return $encoded;
    }

    /**
     * One field's answer as the API's value list.
     *
     * An empty list means "nothing to send" and drops the field, which is why
     * an absent, blank or unselected answer returns one. A stored `false` is
     * not blank — an acknowledgement the visitor declined is a real answer, and
     * the reviewing clinician needs it.
     *
     * @return list<array<string, mixed>>
     */
    private static function values(Field $field, mixed $answer): array
    {
        if (is_array($answer)) {
            $values = [];
            foreach ($answer as $entry) {
                if (is_scalar($entry)) {
                    $values[] = self::entry($field, (string) $entry);
                }
            }

            return $values;
        }

        if (is_bool($answer)) {
            return [['value' => $answer]];
        }

        if (!is_scalar($answer) || trim((string) $answer) === '') {
            return [];
        }

        return [self::entry($field, $answer)];
    }

    /**
     * One value, with the label of the option it came from when the field
     * declares options and this value is one of them.
     *
     * @return array<string, mixed>
     */
    private static function entry(Field $field, string|int|float $value): array
    {
        $entry = ['value' => $value];

        foreach ($field->options() as $option) {
            if ($option['value'] === (string) $value) {
                $entry['label'] = $option['label'];
                break;
            }
        }

        return $entry;
    }
}
