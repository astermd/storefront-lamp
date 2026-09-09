<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * The body-mass-index arithmetic, as a pure function of a unit system, a
 * height and a weight.
 *
 * It is a class of its own rather than a private helper on {@see AnswerSet}
 * because the score is a *clinical* number that a hard-stop rule compares
 * against (`[10.41c]`): a form may disqualify on "BMI below 27", so the
 * rounding, the unit classification and the band boundaries are all
 * observable behaviour that deserves its own tests rather than being reached
 * only through an answer merge.
 *
 * Every entry point either returns a real measurement or returns nothing.
 * There is deliberately no "0.0 means unknown" path: zero satisfies every
 * below-threshold comparison, so a sentinel score would disqualify a visitor
 * who has merely not finished typing.
 */
final class Bmi
{
    public const string METRIC = 'metric';

    public const string IMPERIAL = 'imperial';

    /**
     * Keywords that mark a unit label as metric (`[10.29]`).
     *
     * Checked before anything else so a compound label like
     * `"Metric (kg/cm)"` cannot be misread by an imperial keyword hiding
     * inside it.
     *
     * @var list<string>
     */
    private const array METRIC_KEYWORDS = ['metric', 'kg', 'kilo', 'cm', 'centimet'];

    /**
     * Classifies the unit system a BMI composite was answered in.
     *
     * Imperial is the default, and that default is load-bearing rather than
     * arbitrary: the recorded form carries its unit system in a *hidden*
     * dropdown, so the visitor never picks one and the answer arrives absent.
     * The same form labels its inputs "Height (in inches)" and
     * "Weight (in lbs)", so treating an unstated system as imperial is what
     * matches the numbers actually typed. Metric is only ever chosen when the
     * label says so.
     */
    public static function unitSystem(?string $raw): string
    {
        $normalised = strtolower(trim((string) $raw));

        foreach (self::METRIC_KEYWORDS as $keyword) {
            if ($normalised !== '' && str_contains($normalised, $keyword)) {
                return self::METRIC;
            }
        }

        return self::IMPERIAL;
    }

    /**
     * The score to one decimal place, or null when the inputs are not a
     * measurement (`[10.31]`).
     *
     * Metric reads centimetres and kilograms (`kg / m²`); imperial reads
     * inches and pounds (`lb / in² × 703`). A non-positive height would be a
     * division by zero, and a non-positive weight would produce a zero score
     * that reads as "extremely underweight" — both are absent measurements,
     * not measurements of zero, so both return null.
     */
    public static function score(string $unitSystem, float $height, float $weight): ?float
    {
        if ($height <= 0.0 || $weight <= 0.0) {
            return null;
        }

        if ($unitSystem === self::METRIC) {
            $metres = $height / 100;

            return round($weight / ($metres ** 2), 1);
        }

        return round($weight / ($height ** 2) * 703, 1);
    }

    /** The standard weight band for a score (`[10.33]`). */
    public static function category(float $score): string
    {
        return match (true) {
            $score < 18.5 => 'Underweight',
            $score < 25.0 => 'Normal',
            $score < 30.0 => 'Overweight',
            default => 'Obese',
        };
    }
}
