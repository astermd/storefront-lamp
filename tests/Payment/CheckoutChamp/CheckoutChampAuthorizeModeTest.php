<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Payment\CheckoutChamp;

use AsterMD\Storefront\Payment\CheckoutChamp\CheckoutChampAuthorizeMode;
use PHPUnit\Framework\TestCase;

final class CheckoutChampAuthorizeModeTest extends TestCase
{
    public function testTheTwoSpellingsTheConfigurationAcceptsAreTheOnlyOnes(): void
    {
        self::assertSame(CheckoutChampAuthorizeMode::Qa, CheckoutChampAuthorizeMode::parse('qa'));
        self::assertSame(CheckoutChampAuthorizeMode::Preauth, CheckoutChampAuthorizeMode::parse('preauth'));
    }

    public function testCaseAndSurroundingSpaceAreForgiven(): void
    {
        self::assertSame(CheckoutChampAuthorizeMode::Qa, CheckoutChampAuthorizeMode::parse('  QA '));
    }

    public function testAnUnrecognisedSpellingIsNullRatherThanAGuess(): void
    {
        // "pre-auth" and "preAuth" are the spellings a deployment is most
        // likely to reach for, and guessing either would decide how a buyer\'s
        // money is held on the strength of a typo.
        self::assertNull(CheckoutChampAuthorizeMode::parse('pre-auth'));
        self::assertNull(CheckoutChampAuthorizeMode::parse('legacy'));
        self::assertNull(CheckoutChampAuthorizeMode::parse(''));
        self::assertNull(CheckoutChampAuthorizeMode::parse(null));
        self::assertNull(CheckoutChampAuthorizeMode::parse(1));
    }

    public function testOnlyTheQaMechanismReservesTheOrderAmount(): void
    {
        // The whole reason the two are not interchangeable. The older mechanism
        // validates the card and refunds a nominal amount; it reserves nothing,
        // so a later capture can decline for want of funds.
        self::assertTrue(CheckoutChampAuthorizeMode::Qa->holdsTheOrderAmount());
        self::assertFalse(CheckoutChampAuthorizeMode::Preauth->holdsTheOrderAmount());
    }

    public function testTheStoredValuesAreTheConfiguredSpellings(): void
    {
        // Pinned because these strings are what `config/payment.php` and
        // `PAYMENT_CC_AUTHORIZE_MODE` are written against.
        self::assertSame('qa', CheckoutChampAuthorizeMode::Qa->value);
        self::assertSame('preauth', CheckoutChampAuthorizeMode::Preauth->value);
    }
}
