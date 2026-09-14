<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Checkout;

/**
 * The only thing this storefront is entitled to say about a card number.
 *
 * `[13.28]` gives the payment provider the last word on whether a card is
 * good, and it still has it: a decline is reported in the provider's own
 * words, and nothing here duplicates or contradicts that judgement. What this
 * closes is a gap on the other side of it. The card box accepted an unbounded
 * string, so a buyer who typed their number twice, or pasted a line of a
 * statement, submitted it, waited for the provider round trip, and was shown a
 * decline written for a developer. A length is not an opinion about the card.
 *
 * **Deliberately not a Luhn check.** Luhn would be the obvious next rule and
 * it is the wrong one here: providers issue sandbox numbers that fail it on
 * purpose — `1444444444444440` is one in use against this storefront — so
 * checking it locally makes the provider's own test cards unusable while
 * telling whoever typed one that their card number is wrong. Length is the
 * only rule that cannot disagree with the provider about a card that exists.
 *
 * The bounds are ISO/IEC 7812 as actually issued: 14 digits (Diners Club), 15
 * (American Express), 16 (Visa, Mastercard, Discover, JCB), and up to 19 for
 * Visa and UnionPay, with 13 still valid on older Visa stock. The standard
 * permits as few as 8 for an issuer identification number that is not a
 * payment card; no card presented at a checkout has fewer than 13.
 */
final class CardNumber
{
    /** The shortest number any issued payment card carries (older Visa stock). */
    private const int SHORTEST = 13;

    /** The longest ISO/IEC 7812 permits, issued by Visa and UnionPay. */
    private const int LONGEST = 19;

    private const string MISSING = 'Enter the card number.';

    private const string WRONG_LENGTH = 'Enter a card number between 13 and 19 digits.';

    /**
     * What is wrong with this number, in the buyer's words, or null when
     * nothing this storefront can see is.
     *
     * Spaces and dashes are stripped before counting: they are how a card is
     * printed and how people type it, so they are not what makes a number
     * wrong. An empty box is reported as a missing card rather than as the
     * wrong length, because telling someone who typed nothing that they need
     * between 13 and 19 digits answers a question they did not ask.
     */
    public static function problem(string $number): ?string
    {
        $digits = preg_replace('/\D/', '', $number) ?? '';

        if ($digits === '') {
            return self::MISSING;
        }

        $length = strlen($digits);

        return $length < self::SHORTEST || $length > self::LONGEST ? self::WRONG_LENGTH : null;
    }
}
