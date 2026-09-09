<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * Which authored field types this renderer implements, and — the part that
 * matters — which it refuses.
 *
 * `[10.13]` is the failure this class exists to prevent: a form authored with
 * a type the renderer does not know used to lose those questions with no
 * error at all, so a clinician read an intake that silently omitted whatever
 * was asked. The rule adopted instead is implement-or-fail-loudly, and the
 * classification is **default-deny**: a type in neither list is unsupported,
 * so a type the EMR adds after this ships surfaces as a stated error on a
 * blocked form rather than as a question nobody answered.
 *
 * `hidden` sits in the value set rather than the display set on purpose
 * (`[10.11]`): it is not displayed but it does carry a value, which is how a
 * computed answer like a BMI score reaches the record.
 */
final class FieldTypes
{
    /** Types that hold a submitted answer. */
    public const array VALUE_TYPES = [
        'text', 'textarea', 'email', 'phone', 'number', 'password',
        'picker-date', 'choice-single', 'choice-multi', 'dropdown',
        'checkbox', 'toggle', 'terms', 'agreement', 'bmi', 'hidden',
    ];

    /**
     * Structural and display-only elements. They render, and they carry no
     * submitted value — so they are excluded from validation and from
     * everything sent to the EMR (`[10.12]`).
     */
    public const array DISPLAY_TYPES = [
        'heading', 'form-header', 'paragraph', 'divider',
        'alert', 'image', 'form-progress', 'button',
    ];

    /** Types whose answer is always a list, however many options are selected (`[10.36]`). */
    public const array MULTI_VALUE_TYPES = ['choice-multi'];

    /** Types whose answer is a boolean rather than a string. */
    public const array BOOLEAN_TYPES = ['checkbox', 'toggle', 'terms', 'agreement'];

    public static function supported(string $type): bool
    {
        return in_array($type, self::VALUE_TYPES, true) || in_array($type, self::DISPLAY_TYPES, true);
    }

    public static function isDisplayOnly(string $type): bool
    {
        return in_array($type, self::DISPLAY_TYPES, true);
    }

    public static function isMultiValue(string $type): bool
    {
        return in_array($type, self::MULTI_VALUE_TYPES, true);
    }

    public static function isBoolean(string $type): bool
    {
        return in_array($type, self::BOOLEAN_TYPES, true);
    }
}
