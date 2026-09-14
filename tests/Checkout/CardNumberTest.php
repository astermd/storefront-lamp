<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Checkout;

use AsterMD\Storefront\Checkout\CardNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one thing the storefront is entitled to say about a card number.
 *
 * `[13.28]` gives the provider the last word on whether a card is good, and
 * that has not changed — a decline is theirs to report, in their words. What
 * this adds is the check they cannot make on our behalf: the box accepted an
 * unbounded string, so a buyer could type forty digits and pay the round trip
 * to find out. A length is not a judgement about the card.
 *
 * Deliberately *not* a Luhn check. Payment providers issue sandbox numbers
 * that fail Luhn on purpose — `1444444444444440` is one — and a storefront
 * that refuses them locally makes the provider's own test cards untestable
 * against it while telling the buyer the number is wrong. Length is the only
 * rule that never disagrees with the provider about a real card.
 */
final class CardNumberTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function acceptedLengths(): iterable
    {
        yield 'Diners, 14' => ['30569309025904'];
        yield 'Amex, 15' => ['378282246310005'];
        yield 'Visa/Mastercard, 16' => ['4111111100084444'];
        yield 'Visa 19-digit' => ['4111111111111111119'];
        yield 'a sandbox number that fails Luhn' => ['1444444444444440'];
        yield 'a 13-digit Visa' => ['4222222222222'];
    }

    #[DataProvider('acceptedLengths')]
    public function testACardOfAnIssuedLengthIsAccepted(string $number): void
    {
        self::assertNull(CardNumber::problem($number), 'a card the provider would have judged was refused here instead');
    }

    /** @return iterable<string, array{string}> */
    public static function refusedLengths(): iterable
    {
        yield 'far too long' => ['41111111111111111111111111111111'];
        yield 'one digit past the longest issued' => ['41111111111111111119'];
        yield 'one digit short of the shortest' => ['411111111111'];
        yield 'a single digit' => ['4'];
    }

    #[DataProvider('refusedLengths')]
    public function testANumberOfNoIssuedLengthIsRefusedBeforeTheProviderIsCalled(string $number): void
    {
        self::assertSame(
            'Enter a card number between 13 and 19 digits.',
            CardNumber::problem($number),
            'the box accepted a number no card can have',
        );
    }

    /**
     * Spaces and dashes are how a card is printed and how a buyer types it,
     * so they are not what makes a number wrong.
     */
    public function testFormattingIsNotPartOfTheLength(): void
    {
        self::assertNull(CardNumber::problem('4111 1111 0008 4444'));
        self::assertNull(CardNumber::problem('3782-822463-10005'));
    }

    /**
     * An empty box is a missing card, not a malformed one. Saying "between 13
     * and 19 digits" to someone who typed nothing answers a question they did
     * not ask.
     */
    public function testAnEmptyNumberIsReportedAsMissingRatherThanAsTheWrongLength(): void
    {
        self::assertSame('Enter the card number.', CardNumber::problem(''));
        self::assertSame('Enter the card number.', CardNumber::problem('   '));
    }
}
