<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * The form author's own stylesheet (`settings.injectCss`), made safe to put
 * inside a `<style>` element.
 *
 * **Why this is not HTML escaping.** CSS cannot survive it: `>` is the child
 * combinator, `&` is nesting, and `"` appears in every attribute selector, so
 * escaping would turn a working stylesheet into a broken one while protecting
 * nothing that matters. The template therefore renders this with `raw`, and
 * this class is what earns that.
 *
 * **What actually has to be removed.** Inside `<style>` the HTML parser is in
 * RAWTEXT mode: it does not interpret tags, entities or comments, and the only
 * thing that ends the element is a closing `</style` tag. So that one sequence
 * is the whole attack surface, and removing it is sufficient — not a
 * best-effort filter over a long list of dangerous strings, which is the shape
 * of sanitiser that is always missing one.
 *
 * The match allows whitespace after the slash because the parser does too:
 * `</ style>` closes the element, and a guard written as a literal string
 * comparison would let it through.
 *
 * The sequence is deleted rather than escaped. There is no escape for it —
 * a CSS backslash escape inside a selector means something else — and no
 * valid stylesheet contains it, so anything that does is either an attack or
 * a paste accident, and neither is worth rendering.
 *
 * **Deleting it once is not enough**, which is the part that looks like
 * over-engineering and is not. `</sty</stylele>` holds exactly one `</style`,
 * in the middle; remove it and the surviving `</sty` and `le>` splice into a
 * working `</style>`. So removal repeats until the text stops changing, and
 * the loop is bounded by the fact that each pass strictly shortens the string.
 */
final class InjectedCss
{
    /** `</style`, tolerating the whitespace the HTML parser tolerates. */
    private const string CLOSING_TAG = '#</\s*style#i';

    public static function safe(?string $css): ?string
    {
        if ($css === null || trim($css) === '') {
            return null;
        }

        $safe = $css;
        do {
            $previous = $safe;
            $safe = preg_replace(self::CLOSING_TAG, '', $previous);

            // preg_replace answers null only on a failure this pattern cannot
            // produce, but a null reaching a `raw` block would be the one
            // branch nobody tested. Refuse instead: no stylesheet is better
            // than an unexamined one.
            if ($safe === null) {
                return null;
            }
        } while ($safe !== $previous);

        return trim($safe) === '' ? null : $safe;
    }
}
