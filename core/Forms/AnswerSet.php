<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * The answers a visitor has given so far, keyed by field name.
 *
 * Two rules shape the whole class. The first is `[10.27]`: answers
 * **accumulate**. A save carries only the page that was just filled in, so
 * merging rather than replacing is what lets a visitor step back, change one
 * box, step forward, or return days later on another device without silently
 * emptying every question they are not currently looking at. The second is
 * `[10.36]`: a multi-select answer is a list however many options are chosen,
 * because a single selection flattened to a bare string is the shape that
 * fails to re-check its own box on the way back in.
 *
 * Immutability is deliberate. The evaluator, the validator and the record
 * mapper all read an answer set while a controller is still deciding what to
 * do with the merge, and a shared mutable bag would let a rejected save leak
 * into an evaluation that had already run. Every mutation returns a new
 * instance instead.
 *
 * `merge()` is also the **filter**: a name the definition does not declare is
 * dropped, and so is a value posted for a display-only field (`[10.12]`,
 * `[10.22]`). Doing it here, at the boundary where answers enter storage,
 * means no later stage has to remember to re-check — an injected field never
 * reaches the record mapper because it never reached the answer set.
 *
 * Derivation is server-side for the same reason (`[10.45]`). The BMI
 * composite's value is not answered, it is computed, and the recorded form
 * disqualifies on `bmi_measurement less_than '27'` — so a score computed only
 * in the browser would let a disqualifying answer straight through whenever
 * scripts are absent, blocked or bypassed. {@see self::withDerived()} runs on
 * the server on every save, before any rule is evaluated.
 */
final class AnswerSet
{
    /** The composite whose value is computed from its subfields rather than answered (`[10.7]`). */
    private const string COMPOSITE_BMI = 'bmi';

    /** Scalars a checkbox-like field accepts as "checked"; everything else is unchecked. */
    private const array TRUTHY = ['true', '1', 'on'];

    /** @param array<string, mixed> $values */
    private function __construct(private readonly array $values)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Rehydrates a stored set.
     *
     * Values that have already been through {@see self::merge()} were filtered
     * and coerced when they were written, so they are trusted as-is here; the
     * definition is not required, which is what lets a resumed journey read
     * its answers back before its form has been fetched.
     *
     * @param array<string, mixed> $stored
     */
    public static function fromArray(array $stored): self
    {
        return new self($stored);
    }

    /**
     * Folds one page's worth of posted values into the set and returns the
     * result, leaving this instance untouched.
     *
     * @param array<string, mixed> $incoming
     */
    public function merge(array $incoming, Definition $definition): self
    {
        $values = $this->values;

        foreach ($incoming as $name => $value) {
            $field = $definition->field((string) $name);
            if ($field === null || $field->isDisplayOnly()) {
                continue;
            }

            // An array posted for a single-value field is malformed rather
            // than empty, so the answer already on file is left alone.
            if (is_array($value) && !$field->isMultiValue()) {
                continue;
            }

            $values[$field->name] = self::coerce($field, $value);
        }

        return new self($values);
    }

    /**
     * Recomputes every derived answer and returns the result.
     *
     * Idempotent by construction — each derived key is written from its
     * sources or removed — so calling it after every merge is safe, and a
     * value posted directly for a composite cannot survive: the server's own
     * computation always overwrites it, or clears it when the composite is
     * incomplete.
     *
     * A composite missing either measurement derives **nothing**, not zero.
     * Zero would satisfy a "BMI below 27" hard stop and disqualify a visitor
     * who has simply not finished typing the second number.
     */
    public function withDerived(Definition $definition): self
    {
        $values = $this->values;

        foreach ($definition->fields() as $field) {
            if ($field->type !== self::COMPOSITE_BMI) {
                continue;
            }

            $score = self::bmiScore($field, $values);
            if ($score === null) {
                unset($values[$field->name], $values[$field->name . '_category']);
                continue;
            }

            $values[$field->name] = $score;
            $values[$field->name . '_category'] = Bmi::category($score);
        }

        return new self($values);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    public function value(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    /**
     * Normalises one posted value to the shape its field's type stores in.
     *
     * A number stays a **string**: the evaluator coerces at comparison time,
     * and reformatting here would replace what the visitor typed with our
     * idea of it — losing a leading zero, a trailing decimal point, or the
     * partial entry they are still mid-way through.
     */
    private static function coerce(Field $field, mixed $value): mixed
    {
        if ($field->isMultiValue()) {
            return self::toList($value);
        }

        if ($field->isBoolean()) {
            return self::toBool($value);
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /** @return list<string> */
    private static function toList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map(
                static fn (mixed $entry): string => (string) $entry,
                array_filter($value, 'is_scalar'),
            ));
        }

        // A cleared multi-select posts nothing at all, or an empty string from
        // a hidden companion input; both mean "no options chosen".
        if ($value === null || $value === '' || $value === false) {
            return [];
        }

        return is_scalar($value) ? [(string) $value] : [];
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return is_scalar($value) && in_array(strtolower(trim((string) $value)), self::TRUTHY, true);
    }

    /**
     * The score for one BMI composite, read from whatever the form named its
     * parts.
     *
     * The subfield names are taken from the definition rather than hardcoded,
     * because the composite's parts are authored like any other field and a
     * second form is free to call them something else — a hardcoded
     * `bmi_height` would derive nothing at all on that form, and a form whose
     * hard stop compares a value that is never computed is a hard stop that
     * never fires.
     *
     * @param array<string, mixed> $values
     */
    private static function bmiScore(Field $composite, array $values): ?float
    {
        $parts = self::bmiParts($composite);

        $height = self::toFloat($parts['height'] === null ? null : ($values[$parts['height']] ?? null));
        $weight = self::toFloat($parts['weight'] === null ? null : ($values[$parts['weight']] ?? null));
        if ($height === null || $weight === null) {
            return null;
        }

        $unit = $parts['unit'] === null ? null : ($values[$parts['unit']] ?? null);

        return Bmi::score(
            Bmi::unitSystem(is_scalar($unit) ? (string) $unit : null),
            $height,
            $weight,
        );
    }

    /**
     * Which declared subfield name plays each role in the composite.
     *
     * Roles are read from the authored name and label first, since that is
     * what an author actually varies, and fall back to the authored order of
     * the numeric subfields — height then weight, the order every recorded
     * form uses — so a composite whose parts are named opaquely still derives.
     * A composite with no unit part at all is normal: the recorded form hides
     * that dropdown, and an absent unit system means imperial (`[10.29]`).
     *
     * @return array{unit: ?string, height: ?string, weight: ?string}
     */
    private static function bmiParts(Field $composite): array
    {
        $unit = null;
        $height = null;
        $weight = null;
        /** @var list<string> $unclaimedNumbers */
        $unclaimedNumbers = [];

        foreach ($composite->subfields as $subfield) {
            $described = strtolower($subfield->name . ' ' . $subfield->label);

            if ($height === null && str_contains($described, 'height')) {
                $height = $subfield->name;
            } elseif ($weight === null && str_contains($described, 'weight')) {
                $weight = $subfield->name;
            } elseif ($unit === null && (str_contains($described, 'unit') || str_contains($described, 'system'))) {
                $unit = $subfield->name;
            } elseif ($subfield->type === 'number') {
                $unclaimedNumbers[] = $subfield->name;
            }
        }

        return [
            'unit' => $unit,
            'height' => $height ?? array_shift($unclaimedNumbers),
            'weight' => $weight ?? array_shift($unclaimedNumbers),
        ];
    }

    private static function toFloat(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' && is_numeric($trimmed) ? (float) $trimmed : null;
    }
}
