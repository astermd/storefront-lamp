<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Checkout;

use AsterMD\Storefront\Forms\AnswerSet;
use AsterMD\Storefront\Forms\TeleformMetadata;

/**
 * Checkout fields, filled from what the intake already asked (`[13.5b]`).
 *
 * The intake's own `db_fields` map is read **backwards**. That map says which
 * record path each answer writes to -- `date_of_birth → opportunity.dob` --
 * and this class asks the opposite question: which answer ends up at
 * `opportunity.email`, because that is what the checkout page's email box
 * wants. Using the same map in both directions is what `[13.5b]` means by
 * "one mapping, not two": a clinician who renames a question keeps prefill
 * working, because the record path did not move.
 *
 * Precedence is stored answers first, working state second (`[13.5c]`). An
 * empty stored answer does not count as an answer -- someone who skipped an
 * optional question and typed the value at checkout must not have it wiped on
 * the next render.
 *
 * A cart with no questionnaire passes null for both intake arguments and gets
 * working state alone, which is `[13.5g]`'s "checkout falls back to collecting
 * everything" and also what re-fills the form after a decline (`[13.30]`).
 */
final class Prefill
{
    /**
     * Record path → the checkout field that wants it.
     *
     * Only paths with somewhere to go appear. An answer mapped to
     * `opportunity.clinical.bmi` has a record home and no box on this page, and
     * is skipped rather than guessed at.
     *
     * @var array<string, string>
     */
    private const array FIELD_FOR_PATH = [
        'opportunity.first_name' => 'first_name',
        'opportunity.last_name' => 'last_name',
        'opportunity.email' => 'email',
        'opportunity.phone' => 'phone',
        'opportunity.address.line1' => 'address_line',
        'opportunity.address.address1' => 'address_line',
        'opportunity.address.street' => 'address_line',
        'opportunity.address.city' => 'city',
        'opportunity.address.state' => 'territory',
        'opportunity.address.postal_code' => 'postal_code',
        'opportunity.address.zip' => 'postal_code',
    ];

    /** Fields normalised to upper case on the way in (`[13.1]`). */
    private const array UPPERCASED = ['territory'];

    /**
     * @param  array<string, string> $workingState the buyer details already typed on this journey
     * @return array<string, string> checkout field name → value
     */
    public static function from(?TeleformMetadata $metadata, ?AnswerSet $answers, array $workingState): array
    {
        $prefilled = [];

        if ($metadata !== null && $answers !== null) {
            foreach ($metadata->dbFields as $answerName => $recordPath) {
                $field = self::FIELD_FOR_PATH[(string) $recordPath] ?? null;
                if ($field === null) {
                    continue;
                }

                $value = self::scalar($answers->value((string) $answerName));
                if ($value === '') {
                    continue;
                }

                $prefilled[$field] = $value;
            }
        }

        foreach ($workingState as $field => $value) {
            $value = self::scalar($value);
            if ($value === '' || ($prefilled[$field] ?? '') !== '') {
                continue;
            }

            $prefilled[$field] = $value;
        }

        foreach (self::UPPERCASED as $field) {
            if (isset($prefilled[$field])) {
                $prefilled[$field] = strtoupper($prefilled[$field]);
            }
        }

        return $prefilled;
    }

    /**
     * One answer as a string.
     *
     * A single-element list is flattened to the value it holds, matching what
     * {@see \AsterMD\Storefront\Forms\RecordMapper} does on the write side --
     * form authors routinely build a one-answer question out of a multi-select
     * control, and the two sides disagreeing would put one value on the order
     * and another on the clinical record.
     */
    private static function scalar(mixed $value): string
    {
        if (is_array($value)) {
            $value = count($value) === 1 ? reset($value) : '';
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
