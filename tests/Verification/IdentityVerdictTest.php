<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Verification;

use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Verification\IdentityVerdict;
use PHPUnit\Framework\TestCase;

/**
 * The verdict's whole job is refusing to collapse three states into two.
 *
 * Recorded live on 2026-08-25: every identity call is HTTP 200 and the answer
 * is only in the body, `valid` is nullable, and a request we built wrongly
 * arrives as an exception rather than a verdict. So "not passed" covers three
 * different things and only one of them is a statement about the buyer.
 */
final class IdentityVerdictTest extends TestCase
{
    public function testAProviderVerdictOfTrueIsAPassedIdentity(): void
    {
        // Synthesised, and it has to be: twenty recorded calls across nine
        // identities and all three checks never produced `valid: true`, so the
        // pass branch has no live example and never will in this sandbox.
        $verdict = IdentityVerdict::fromProviderData('crosscheck', [
            'provider' => 'vouched',
            'check' => 'crosscheck',
            'valid' => true,
            'basis' => 'score',
            'score' => 0.91,
            'threshold' => 0.75,
            'reasons' => [],
            'cached' => false,
        ]);

        self::assertSame(JourneyState::VERIFICATION_PASSED, $verdict->status);
        self::assertTrue($verdict->isPassed());
        self::assertFalse($verdict->isInconclusive());
    }

    public function testAProviderVerdictOfFalseIsTheOnlyStateThatFailsAnIdentity(): void
    {
        // The exact recorded crosscheck body, reasons and all.
        $verdict = IdentityVerdict::fromProviderData('crosscheck', [
            'provider' => 'vouched',
            'check' => 'crosscheck',
            'valid' => false,
            'basis' => 'score',
            'score' => 0,
            'threshold' => 0.75,
            'reasons' => [
                ['code' => 'address_invalid', 'message' => 'The address could not be verified.'],
                ['code' => 'phone_invalid', 'message' => 'The phone number could not be verified.'],
            ],
            'cached' => false,
        ]);

        self::assertSame(JourneyState::VERIFICATION_FAILED, $verdict->status);
        self::assertTrue($verdict->isFailed());
        self::assertSame(['address_invalid', 'phone_invalid'], $verdict->reasons);
        self::assertSame('score', $verdict->basis);
    }

    public function testANullVerdictIsInconclusiveAndNotAFailedIdentity(): void
    {
        // `valid: null` means the provider answered and decided nothing. Read
        // as false it would tell a real buyer they failed a check nobody ran.
        $verdict = IdentityVerdict::fromProviderData('ssn_verify', [
            'check' => 'ssn_verify',
            'valid' => null,
            'basis' => 'match',
            'score' => null,
            'threshold' => null,
            'reasons' => [],
            'cached' => false,
        ]);

        self::assertSame(JourneyState::VERIFICATION_INCONCLUSIVE, $verdict->status);
        self::assertFalse($verdict->isFailed());
    }

    public function testAVerdictThatIsNotStrictlyBooleanIsInconclusiveRatherThanPassed(): void
    {
        // Absence assertion: nothing in the shape promises `valid` is a bool,
        // and a truthy string must not open a blocking placement.
        $verdict = IdentityVerdict::fromProviderData('crosscheck', ['valid' => 'true']);

        self::assertSame(JourneyState::VERIFICATION_INCONCLUSIVE, $verdict->status);
        self::assertFalse($verdict->isPassed());
    }

    public function testAMissingVerdictKeyIsInconclusiveRatherThanPassed(): void
    {
        $verdict = IdentityVerdict::fromProviderData('dob_verify', []);

        self::assertSame(JourneyState::VERIFICATION_INCONCLUSIVE, $verdict->status);
        self::assertFalse($verdict->isPassed());
        self::assertSame('dob_verify', $verdict->check);
    }

    public function testTheScoreAndThresholdAreCarriedExactlyAsTheProviderSentThem(): void
    {
        // Recorded: crosscheck scores exactly 0 against a threshold of 0.75.
        // Rounding either would make "below threshold" unprovable from the record.
        $verdict = IdentityVerdict::fromProviderData('crosscheck', [
            'valid' => false,
            'basis' => 'score',
            'score' => 0.7499,
            'threshold' => 0.75,
        ]);

        self::assertSame(0.7499, $verdict->score);
        self::assertSame(0.75, $verdict->threshold);
    }

    public function testAnIntegerScoreStaysAnInteger(): void
    {
        $verdict = IdentityVerdict::fromProviderData('crosscheck', ['valid' => false, 'score' => 0, 'threshold' => 1]);

        self::assertSame(0, $verdict->score);
        self::assertSame(1, $verdict->threshold);
    }

    public function testANonNumericScoreIsDroppedRatherThanCoerced(): void
    {
        $verdict = IdentityVerdict::fromProviderData('crosscheck', ['valid' => false, 'score' => 'high']);

        self::assertNull($verdict->score);
    }

    public function testTheCheckTheProviderSaysItRanWinsOverTheOneWeAskedFor(): void
    {
        // Recorded `slug-override-ignored`: a `slug` inside the payload is
        // ignored and the enum wins, so the echo is the only honest record of
        // which check actually ran.
        $verdict = IdentityVerdict::fromProviderData('ssn_verify', ['check' => 'crosscheck', 'valid' => false]);

        self::assertSame('crosscheck', $verdict->check);
    }

    public function testTheProviderCacheFlagIsCarriedThrough(): void
    {
        $verdict = IdentityVerdict::fromProviderData('ssn_verify', ['valid' => false, 'cached' => true]);

        self::assertTrue($verdict->cached);
    }

    public function testReasonsWithoutACodeAreDroppedRatherThanRenderedAsBlanks(): void
    {
        $verdict = IdentityVerdict::fromProviderData('crosscheck', [
            'valid' => false,
            'reasons' => [
                ['code' => 'address_invalid', 'message' => 'x'],
                ['message' => 'no code here'],
                'not even an array',
                ['code' => ''],
            ],
        ]);

        self::assertSame(['address_invalid'], $verdict->reasons);
    }

    public function testAReasonsFieldThatIsNotAListIsIgnored(): void
    {
        $verdict = IdentityVerdict::fromProviderData('crosscheck', ['valid' => false, 'reasons' => 'address_invalid']);

        self::assertSame([], $verdict->reasons);
    }

    public function testTheProviderMessagesNeverSurviveIntoTheVerdict(): void
    {
        // Absence assertion. `[22.21]` keeps identity detail out of anything
        // that gets logged, and a provider message is free-form text that can
        // quote its own input back. Only the coded reason is carried.
        $verdict = IdentityVerdict::fromProviderData('ssn_verify', [
            'valid' => false,
            'reasons' => [['code' => 'ssn_mismatch', 'message' => 'SSN 123-45-6789 does not match Ada Lovelace.']],
        ]);

        self::assertSame(['ssn_mismatch'], $verdict->reasons);
        self::assertStringNotContainsString('123-45-6789', json_encode($verdict->logContext(), JSON_THROW_ON_ERROR));
    }

    public function testAnInconclusiveVerdictCanBeMadeWithoutAProviderAnswerAtAll(): void
    {
        $verdict = IdentityVerdict::inconclusive('dob_verify');

        self::assertSame(JourneyState::VERIFICATION_INCONCLUSIVE, $verdict->status);
        self::assertSame('dob_verify', $verdict->check);
        self::assertSame([], $verdict->reasons);
        self::assertNull($verdict->score);
        self::assertFalse($verdict->cached);
    }

    public function testAnInconclusiveVerdictCanCarryTheReasonItCouldNotBeDecided(): void
    {
        $verdict = IdentityVerdict::inconclusive('dob_verify', 'unavailable');

        self::assertSame(['unavailable'], $verdict->reasons);
    }

    public function testEveryStatusIsOneJourneyStateAlreadyKnows(): void
    {
        // The verdict is written straight onto the journey by the step, and
        // recordVerification() refuses a status outside its vocabulary. If
        // these ever drift the step throws at runtime rather than here.
        foreach ([
            IdentityVerdict::fromProviderData('c', ['valid' => true]),
            IdentityVerdict::fromProviderData('c', ['valid' => false]),
            IdentityVerdict::inconclusive('c'),
        ] as $verdict) {
            self::assertContains($verdict->status, JourneyState::VERIFICATION_STATUSES);
        }
    }

    public function testTheLogContextNamesTheOutcomeAndNeverTheIdentity(): void
    {
        $verdict = IdentityVerdict::fromProviderData('crosscheck', [
            'valid' => false,
            'basis' => 'score',
            'score' => 0,
            'threshold' => 0.75,
            'reasons' => [['code' => 'address_invalid']],
            'cached' => true,
        ]);

        self::assertSame([
            'check' => 'crosscheck',
            'status' => JourneyState::VERIFICATION_FAILED,
            'basis' => 'score',
            'score' => 0,
            'threshold' => 0.75,
            'reasons' => ['address_invalid'],
            'cached' => true,
        ], $verdict->logContext());
    }
}
