<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Forms;

use AsterMD\Storefront\Forms\InjectedCss;
use PHPUnit\Framework\TestCase;

/**
 * The form author's own stylesheet, carried on the definition as
 * `settings.injectCss`, and the one thing it must not be able to do.
 *
 * It cannot be HTML-escaped: `>` is a child combinator and `&` is used in
 * nesting, so escaping would break ordinary CSS rather than protect anything.
 * What makes that safe is narrow — inside a `<style>` element the HTML parser
 * looks for exactly one thing, the closing tag — so that one sequence is what
 * has to go, and nothing else does.
 */
final class InjectedCssTest extends TestCase
{
    public function testOrdinaryCssIsPassedThroughUnchanged(): void
    {
        $css = '.hide-this{display:none} #my-id > input { color: red }';

        self::assertSame($css, InjectedCss::safe($css));
    }

    /**
     * The combinators and characters escaping would have destroyed. This is
     * the case that says why `|raw` is correct here rather than lazy.
     */
    public function testCssOperatorsSurvive(): void
    {
        $css = '.a > .b + .c ~ .d[data-x="1"] { margin: 0 }';

        self::assertSame($css, InjectedCss::safe($css));
    }

    public function testAClosingStyleTagCannotEndTheBlock(): void
    {
        $css = '.a{}</style><script>alert(1)</script>';

        // The property is that the element cannot be closed. `<script>` left
        // behind as text is inert: inside `<style>` the parser is in RAWTEXT
        // mode and interprets no tags at all, so with no closing tag there is
        // nothing that can start an element.
        self::assertDoesNotMatchRegularExpression('#</\s*style#i', (string) InjectedCss::safe($css));
    }

    /** HTML tag matching is case-insensitive, so the guard has to be too. */
    public function testTheGuardIsCaseInsensitive(): void
    {
        self::assertDoesNotMatchRegularExpression('#</\s*style#i', (string) InjectedCss::safe('.a{}</StYlE><script>alert(1)</script>'));
    }

    /**
     * The parser tolerates whitespace inside a closing tag, so `</ style>`
     * closes the element just as well and a guard matching only the exact
     * string would miss it.
     */
    public function testWhitespaceInsideTheClosingTagDoesNotEvadeTheGuard(): void
    {
        self::assertDoesNotMatchRegularExpression('#</\s*style#i', (string) InjectedCss::safe(".a{}</ style ><script>alert(1)</script>"));
    }

    /**
     * The bypass a single pass invites: a sequence built so that removing the
     * inner match splices the outer halves into a fresh one.
     *
     * `</sty</stylele>` contains exactly one `</style`, in the middle. Delete
     * it and the surviving `</sty` and `le>` join into `</style>`, which
     * closes the element the filter was there to protect. Removal has to run
     * until the text stops changing, not once.
     */
    public function testRemovalCannotSpliceANewClosingTagOutOfWhatIsLeft(): void
    {
        $safe = (string) InjectedCss::safe('.a{}</sty</stylele><script>alert(1)</script>');

        self::assertDoesNotMatchRegularExpression('#</\s*style#i', $safe);
    }

    public function testNothingAuthoredIsNull(): void
    {
        self::assertNull(InjectedCss::safe(null));
        self::assertNull(InjectedCss::safe(''));
        self::assertNull(InjectedCss::safe('   '));
    }
}
