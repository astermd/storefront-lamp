<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

use AsterMD\Storefront\Support\OperatorLog;

/**
 * Turns answers into the nested EMR record the teleform's own `db_fields` map
 * asks for.
 *
 * The map is authored alongside the form, so no question-to-record mapping is
 * written here (`[11.1]`): a target is a dotted path such as
 * `opportunity.clinical.height.value`, and this class expands it into the
 * nesting the API expects. A form with no map at all produces an empty payload
 * rather than an error — the staging channel really has one.
 *
 * **Why the whole answer set, every time.** {@see self::build()} rebuilds the
 * entire payload from everything known so far rather than from the page that
 * was just saved, which is `[11.5]`: because a write always carries the full
 * record, a later partial save can never unset a field an earlier one wrote.
 * Passing a delta would make the second save look like an instruction to clear
 * everything the first one had filled in.
 *
 * Only the `opportunity` root is written (`[11.3]`, `[11.11]`). Another root is
 * a target this storefront has no call to write, so it is skipped — but skipped
 * *loudly*, once per distinct root, because a silently dropped mapping is a
 * clinician reading an intake with a question missing from it. The log line
 * carries the root and the field's name and never its answer: answers are PHI.
 */
final class RecordMapper
{
    /** The one record this storefront writes. */
    private const string ROOT = 'opportunity';

    /**
     * Which targets hold a body measurement, matched on the **path** rather
     * than the field name, and with or without a trailing `.value`.
     *
     * Real maps are inconsistent about that last segment — the recorded form
     * writes `clinical.height.value` next to `clinical.bmi` — and the name a
     * form gives its own height question is entirely up to its author, so the
     * path is the only reliable signal that a number needs converting
     * (`[11.7]`).
     */
    private const string HEIGHT_TARGET = '/(^|\.)height(\.value)?$/';

    private const string WEIGHT_TARGET = '/(^|\.)weight(\.value)?$/';

    private const float INCHES_TO_CENTIMETRES = 2.54;

    private const float POUNDS_TO_KILOGRAMS = 0.45359237;

    public function __construct(private readonly OperatorLog $log)
    {
    }

    /**
     * The `opportunity`-rooted payload for everything answered so far.
     *
     * Rebuilt in full on every call — see the class docblock for why that is
     * the point rather than an inefficiency. An answer that is absent or empty
     * contributes nothing (`[11.4]`), so an unanswered question leaves whatever
     * the record already holds alone instead of blanking it.
     *
     * @return array<string, mixed>
     */
    public function build(TeleformMetadata $metadata, AnswerSet $answers, Definition $definition): array
    {
        $unitSystem = self::unitSystem($answers, $definition);
        $payload = [];
        /** @var list<string> $reported */
        $reported = [];

        foreach ($metadata->dbFields as $name => $target) {
            $segments = self::segments($target);
            $root = array_shift($segments);

            if ($root !== self::ROOT) {
                if ($root !== null && !in_array($root, $reported, true)) {
                    $reported[] = $root;
                    $this->log->warning('intake.unmapped_target_root', ['root' => $root, 'field' => $name]);
                }

                continue;
            }

            // A target that is the bare root names no property to write into.
            if ($segments === []) {
                continue;
            }

            $value = $answers->value($name);
            if (!self::present($value)) {
                continue;
            }

            // A one-element list is written as the value it holds. Form authors
            // routinely build a single-answer question out of a multi-select
            // control, and the record's fields are scalars — a verified live
            // rejection, not a precaution: an `opportunity.gender` of
            // `["female"]` is refused outright, taking the whole update with
            // it, while `"female"` is accepted. Logged so a flatten that ever
            // turns out to be wrong is traceable rather than invisible.
            if (is_array($value) && count($value) === 1) {
                $this->log->info('intake.multi_value_flattened', ['field' => $name, 'target' => $target]);
                $value = reset($value);
            }

            $payload = self::place($payload, $segments, self::normalise($target, $value, $unitSystem));
        }

        return $payload;
    }

    /**
     * Whether this payload carries enough to be worth sending as a lead.
     *
     * `[11.6]`'s threshold is a first name and an email: below that the record
     * is an anonymous fragment that no one can follow up on, and creating it
     * only fills the EMR with rows nobody can act on.
     *
     * @param array<string, mixed> $payload
     */
    public function ready(array $payload): bool
    {
        return self::filled($payload['first_name'] ?? null) && self::filled($payload['email'] ?? null);
    }

    /**
     * Which unit system the visitor's measurements are in.
     *
     * Read from whichever answer the definition's BMI composite names as its
     * unit part, rather than from a fixed field name, so a metric form is not
     * silently converted as though it were imperial. A form with no composite,
     * or one whose unit dropdown is hidden and therefore unanswered, falls back
     * to {@see Bmi::unitSystem()}'s default (`[10.29]`).
     */
    private static function unitSystem(AnswerSet $answers, Definition $definition): string
    {
        foreach ($definition->fields() as $field) {
            if ($field->type !== 'bmi') {
                continue;
            }

            foreach ($field->subfields as $subfield) {
                $described = strtolower($subfield->name . ' ' . $subfield->label);
                if (str_contains($described, 'unit') || str_contains($described, 'system')) {
                    $answered = $answers->value($subfield->name);

                    return Bmi::unitSystem(is_scalar($answered) ? (string) $answered : null);
                }
            }
        }

        return Bmi::unitSystem(null);
    }

    /**
     * A measurement in the units the record stores, or the answer untouched.
     *
     * Height is whole centimetres and weight is kilograms to one decimal,
     * because that is the precision the record holds; a value that is not a
     * number at all — free text where a measurement was expected — passes
     * through unconverted rather than becoming a nonsense zero.
     */
    private static function normalise(string $target, mixed $value, string $unitSystem): mixed
    {
        if (!is_scalar($value) || !is_numeric(trim((string) $value))) {
            return $value;
        }

        $number = (float) trim((string) $value);
        $imperial = $unitSystem === Bmi::IMPERIAL;

        if (preg_match(self::HEIGHT_TARGET, $target) === 1) {
            return (int) round($imperial ? $number * self::INCHES_TO_CENTIMETRES : $number);
        }

        if (preg_match(self::WEIGHT_TARGET, $target) === 1) {
            return round($imperial ? $number * self::POUNDS_TO_KILOGRAMS : $number, 1);
        }

        return $value;
    }

    /**
     * Writes one value at a dotted path, creating the intermediate levels.
     *
     * @param array<string, mixed> $payload
     * @param list<string> $segments
     *
     * @return array<string, mixed>
     */
    private static function place(array $payload, array $segments, mixed $value): array
    {
        $key = array_shift($segments);

        if ($segments === []) {
            $payload[$key] = $value;

            return $payload;
        }

        $child = $payload[$key] ?? null;
        $payload[$key] = self::place(is_array($child) ? $child : [], $segments, $value);

        return $payload;
    }

    /**
     * The path's segments, with empty ones dropped so a stray dot cannot
     * create a nameless level in the payload.
     *
     * @return list<string>
     */
    private static function segments(string $target): array
    {
        return array_values(array_filter(explode('.', $target), static fn (string $s): bool => trim($s) !== ''));
    }

    /**
     * Whether an answer is worth writing. A `false` counts: an unticked
     * acknowledgement is a real answer, not a missing one.
     */
    private static function present(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }

        if (is_bool($value)) {
            return true;
        }

        return is_scalar($value) && trim((string) $value) !== '';
    }

    private static function filled(mixed $value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }
}
