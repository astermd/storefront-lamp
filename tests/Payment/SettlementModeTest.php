<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Payment;

use AsterMD\Storefront\Payment\SettlementMode;
use PHPUnit\Framework\TestCase;

final class SettlementModeTest extends TestCase
{
    public function testTheTwoSpellingsTheConfigurationAcceptsAreTheOnlyOnes(): void
    {
        self::assertSame(SettlementMode::Capture, SettlementMode::parse('capture'));
        self::assertSame(SettlementMode::Authorize, SettlementMode::parse('authorize'));
    }

    public function testCaseAndSurroundingSpaceAreForgiven(): void
    {
        // A value typed into a config file or an env var, not a protocol
        // token — a trailing space must not change how a storefront settles.
        self::assertSame(SettlementMode::Authorize, SettlementMode::parse('  AUTHORIZE '));
    }

    public function testAMisspellingIsNullRatherThanAGuess(): void
    {
        // The British spelling is the one a deployment is most likely to
        // reach for, and guessing it would be the storefront deciding to stop
        // charging on the strength of a typo.
        self::assertNull(SettlementMode::parse('authorise'));
        self::assertNull(SettlementMode::parse('preauth'));
        self::assertNull(SettlementMode::parse(''));
    }

    public function testAValueThatIsNotAStringIsNullRatherThanCoerced(): void
    {
        self::assertNull(SettlementMode::parse(null));
        self::assertNull(SettlementMode::parse(1));
        self::assertNull(SettlementMode::parse(['authorize']));
    }

    public function testOneAuthorizeCarriesTheWholeSet(): void
    {
        self::assertSame(
            SettlementMode::Authorize,
            SettlementMode::strictest(SettlementMode::Capture, SettlementMode::Authorize, SettlementMode::Capture),
        );
    }

    public function testAllCaptureStaysCapture(): void
    {
        self::assertSame(
            SettlementMode::Capture,
            SettlementMode::strictest(SettlementMode::Capture, SettlementMode::Capture),
        );
    }

    public function testTheEmptySetIsCaptureRatherThanAnError(): void
    {
        // Not hypothetical: an upsell envelope carries one line and a cart can
        // be re-read after it was cleared, so the caller has no set to offer.
        // Capture is what the storefront did before any of this existed.
        self::assertSame(SettlementMode::Capture, SettlementMode::strictest());
    }

    public function testTheStoredValueIsTheConfiguredSpelling(): void
    {
        // Pinned because this string is what `orders.settlement` holds and what
        // a stored receipt is read back from: renaming a case would silently
        // reinterpret every row already written.
        self::assertSame('capture', SettlementMode::Capture->value);
        self::assertSame('authorize', SettlementMode::Authorize->value);
    }

    public function testOnlyAuthorizeAnswersIsAuthorize(): void
    {
        self::assertTrue(SettlementMode::Authorize->isAuthorize());
        self::assertFalse(SettlementMode::Capture->isAuthorize());
    }
}
