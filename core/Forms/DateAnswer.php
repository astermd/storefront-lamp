<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * A `picker-date` answer as the EMR wants it: `YYYY-MM-DD`.
 *
 * **The stored answer stays as the visitor typed it.** This converts at the
 * outbound boundary and nowhere else, the way money converts only on its way
 * to a provider: the journey keeps `MM / DD / YYYY` because that is what goes
 * back into the box when the form re-renders, and a stored ISO value would
 * show the visitor a date in a format the field does not accept.
 *
 * **Month first, and that is the one thing that must not be guessed.** The
 * field's placeholder says `MM / DD / YYYY` and `theme/js/datepicker.js` both
 * writes and parses that order, so `03/04` is the fourth of March. Read the
 * other way it is still a valid date, which is why this reads the same shape
 * the picker does rather than delegating to a permissive parser.
 *
 * **A date that does not exist answers null rather than the nearest one that
 * does.** A permissive parser rolls 31 February forward into March, turning a
 * typo into a plausible wrong date of birth on a clinical record. Null leaves
 * the answer exactly as the visitor gave it, where a human can still see it is
 * wrong.
 */
final class DateAnswer
{
    /** The shape the field accepts, matching `theme/js/datepicker.js`. */
    private const string TYPED = '/^(\d{1,2})\s*\/\s*(\d{1,2})\s*\/\s*(\d{4})$/';

    /** What this converts to, and therefore also passes through untouched. */
    private const string ISO = '/^(\d{4})-(\d{2})-(\d{2})$/';

    /**
     * This answer as `YYYY-MM-DD`, or null when it is not a date this field
     * could have produced.
     *
     * Null is "leave it alone", not "reject it": validation is
     * {@see AnswerValidator}'s job, and a boundary that blanked an answer it
     * could not read would delete a clinician's only copy of it.
     */
    public static function iso(string $answer): ?string
    {
        $value = trim($answer);

        if (preg_match(self::ISO, $value, $iso) === 1) {
            return self::real((int) $iso[1], (int) $iso[2], (int) $iso[3]);
        }

        if (preg_match(self::TYPED, $value, $typed) !== 1) {
            return null;
        }

        return self::real((int) $typed[3], (int) $typed[1], (int) $typed[2]);
    }

    /** The date, or null when those three numbers do not name one. */
    private static function real(int $year, int $month, int $day): ?string
    {
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
