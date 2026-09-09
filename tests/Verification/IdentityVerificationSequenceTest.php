<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Verification;

use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Verification\IdentityGateway;
use AsterMD\Storefront\Verification\IdentityVerdict;
use AsterMD\Storefront\Verification\IdentityVerificationSequence;
use PHPUnit\Framework\TestCase;

/**
 * The sequence is `config/verification.php`'s escalation rule and nothing
 * else: run the configured checks in the configured order, stop on a decisive
 * answer, escalate an inconclusive one, and run out of checks inconclusively.
 *
 * Every gateway here is a fake. The sequence never touches the SDK.
 */
final class IdentityVerificationSequenceTest extends TestCase
{
    private const IDENTITY = [
        'firstName' => 'Ada',
        'lastName' => 'Lovelace',
        'email' => 'ada@example.com',
        'phone' => '+14155550132',
        'dob' => '1815-12-10',
        'ssn' => '123456789',
        'ipAddress' => '203.0.113.7',
        'address' => ['streetAddress' => '12 Kirkby St', 'city' => 'London', 'country' => 'US'],
    ];

    /** @var list<array{slug: string, required: list<string>, narrowing: list<string>}> */
    private const CHECKS = [
        ['slug' => 'crosscheck', 'required' => ['firstName', 'lastName'], 'narrowing' => ['email', 'phone', 'ipAddress', 'address']],
        ['slug' => 'dob_verify', 'required' => ['firstName', 'lastName', 'phone', 'dob'], 'narrowing' => ['email', 'address']],
        ['slug' => 'ssn_verify', 'required' => ['firstName', 'lastName', 'phone', 'ssn'], 'narrowing' => ['email', 'dob', 'address']],
    ];

    public function testTheFirstDecisiveAnswerStopsTheSequence(): void
    {
        $gateway = $this->gateway(['crosscheck' => IdentityVerdict::fromProviderData('crosscheck', ['valid' => true])]);

        $verdict = (new IdentityVerificationSequence($gateway, self::CHECKS))->run(self::IDENTITY);

        self::assertTrue($verdict->isPassed());
        self::assertSame(['crosscheck'], $gateway->called);
    }

    public function testARefusalStopsTheSequenceJustAsAPassDoes(): void
    {
        // A failed identity is decisive. Escalating past it would let a buyer
        // the provider refused be waved through by a later check.
        $gateway = $this->gateway(['crosscheck' => IdentityVerdict::fromProviderData('crosscheck', ['valid' => false])]);

        $verdict = (new IdentityVerificationSequence($gateway, self::CHECKS))->run(self::IDENTITY);

        self::assertTrue($verdict->isFailed());
        self::assertSame(['crosscheck'], $gateway->called);
    }

    public function testAnInconclusiveAnswerEscalatesToTheNextCheck(): void
    {
        $gateway = $this->gateway([
            'crosscheck' => IdentityVerdict::inconclusive('crosscheck'),
            'dob_verify' => IdentityVerdict::inconclusive('dob_verify'),
            'ssn_verify' => IdentityVerdict::fromProviderData('ssn_verify', ['valid' => false]),
        ]);

        $verdict = (new IdentityVerificationSequence($gateway, self::CHECKS))->run(self::IDENTITY);

        self::assertTrue($verdict->isFailed());
        self::assertSame(['crosscheck', 'dob_verify', 'ssn_verify'], $gateway->called);
    }

    public function testRunningOutOfChecksIsInconclusiveAndNeverARefusal(): void
    {
        // Absence assertion: three checks that could not decide anything must
        // not add up to a decision. Under a blocking placement this is the
        // difference between "ask again" and "refuse a real buyer".
        $gateway = $this->gateway([
            'crosscheck' => IdentityVerdict::inconclusive('crosscheck'),
            'dob_verify' => IdentityVerdict::inconclusive('dob_verify'),
            'ssn_verify' => IdentityVerdict::inconclusive('ssn_verify'),
        ]);

        $verdict = (new IdentityVerificationSequence($gateway, self::CHECKS))->run(self::IDENTITY);

        self::assertSame(JourneyState::VERIFICATION_INCONCLUSIVE, $verdict->status);
        self::assertFalse($verdict->isFailed());
        self::assertFalse($verdict->isPassed());
    }

    public function testTheLastInconclusiveAnswerIsTheOneReported(): void
    {
        // So the compliance record names the check that got furthest rather
        // than a bare "we do not know".
        $gateway = $this->gateway([
            'crosscheck' => IdentityVerdict::inconclusive('crosscheck'),
            'dob_verify' => IdentityVerdict::inconclusive('dob_verify'),
            'ssn_verify' => IdentityVerdict::inconclusive('ssn_verify', 'unavailable'),
        ]);

        $verdict = (new IdentityVerificationSequence($gateway, self::CHECKS))->run(self::IDENTITY);

        self::assertSame('ssn_verify', $verdict->check);
        self::assertSame(['unavailable'], $verdict->reasons);
    }

    public function testAnEmptyCheckListIsInconclusiveRatherThanAPass(): void
    {
        $gateway = $this->gateway([]);

        $verdict = (new IdentityVerificationSequence($gateway, []))->run(self::IDENTITY);

        self::assertSame(JourneyState::VERIFICATION_INCONCLUSIVE, $verdict->status);
        self::assertSame([], $gateway->called);
    }

    public function testEveryFieldTheCheckAsksForIsSentWhenTheJourneyHasIt(): void
    {
        // Recorded: called with its required fields alone, crosscheck returns
        // `address_invalid` and `phone_invalid` — penalising two fields that
        // were never sent. Sending the minimum is a way to fail a check that
        // was never going to pass, so the narrowing fields go on the first call.
        $gateway = $this->gateway(['crosscheck' => IdentityVerdict::fromProviderData('crosscheck', ['valid' => false])]);

        (new IdentityVerificationSequence($gateway, self::CHECKS))->run(self::IDENTITY);

        self::assertSame(
            ['firstName', 'lastName', 'email', 'phone', 'ipAddress', 'address'],
            array_keys($gateway->payloads[0]),
        );
        self::assertSame(self::IDENTITY['address'], $gateway->payloads[0]['address']);
    }

    public function testNothingTheCheckDidNotAskForIsSent(): void
    {
        // Absence assertion. `crosscheck` names neither `ssn` nor `dob`, and
        // an SSN sent to a check that ignores it is an SSN handed to a
        // provider for no reason at all (`[22.21]`).
        $gateway = $this->gateway(['crosscheck' => IdentityVerdict::fromProviderData('crosscheck', ['valid' => false])]);

        (new IdentityVerificationSequence($gateway, self::CHECKS))->run(self::IDENTITY);

        self::assertArrayNotHasKey('ssn', $gateway->payloads[0]);
        self::assertArrayNotHasKey('dob', $gateway->payloads[0]);
    }

    public function testANarrowingFieldTheJourneyDoesNotHaveIsSimplyOmitted(): void
    {
        $gateway = $this->gateway(['crosscheck' => IdentityVerdict::fromProviderData('crosscheck', ['valid' => false])]);

        (new IdentityVerificationSequence($gateway, self::CHECKS))
            ->run(['firstName' => 'Ada', 'lastName' => 'Lovelace', 'phone' => '+14155550132']);

        self::assertSame(['firstName', 'lastName', 'phone'], array_keys($gateway->payloads[0]));
    }

    public function testACheckWhoseRequiredFieldsAreMissingIsSkippedRatherThanSentToFail(): void
    {
        // The provider rejects a request missing a required field with a 400,
        // which is inconclusive — the same answer skipping produces, without
        // spending a metered call or logging a warning about a bug we do not
        // have.
        $gateway = $this->gateway([
            'crosscheck' => IdentityVerdict::inconclusive('crosscheck'),
            'ssn_verify' => IdentityVerdict::fromProviderData('ssn_verify', ['valid' => false]),
        ]);

        $verdict = (new IdentityVerificationSequence($gateway, self::CHECKS))->run([
            'firstName' => 'Ada',
            'lastName' => 'Lovelace',
            'phone' => '+14155550132',
            'ssn' => '123456789',
        ]);

        self::assertSame(['crosscheck', 'ssn_verify'], $gateway->called);
        self::assertTrue($verdict->isFailed());
    }

    public function testAnIdentityThatSatisfiesNoCheckIsInconclusiveWithoutCallingAnything(): void
    {
        $gateway = $this->gateway([]);

        $verdict = (new IdentityVerificationSequence($gateway, self::CHECKS))->run(['email' => 'ada@example.com']);

        self::assertSame(JourneyState::VERIFICATION_INCONCLUSIVE, $verdict->status);
        self::assertSame([], $gateway->called);
    }

    public function testABlankFieldCountsAsAbsentForBothRequiredAndNarrowing(): void
    {
        // A form that posts an empty string is the normal case, and sending
        // `phone: ""` to a check that requires a phone earns a 400.
        $gateway = $this->gateway(['crosscheck' => IdentityVerdict::fromProviderData('crosscheck', ['valid' => false])]);

        (new IdentityVerificationSequence($gateway, self::CHECKS))->run([
            'firstName' => 'Ada',
            'lastName' => 'Lovelace',
            'phone' => '   ',
            'dob' => '',
        ]);

        self::assertSame(['crosscheck'], $gateway->called);
        self::assertSame(['firstName', 'lastName'], array_keys($gateway->payloads[0]));
    }

    public function testAMalformedCheckEntryIsIgnoredRatherThanCrashingTheStep(): void
    {
        // `config/verification.php` is a file an operator edits by hand.
        $gateway = $this->gateway(['ssn_verify' => IdentityVerdict::fromProviderData('ssn_verify', ['valid' => false])]);

        $sequence = new IdentityVerificationSequence($gateway, [
            ['required' => ['firstName']],
            ['slug' => '', 'required' => []],
            'not an array',
            ['slug' => 'ssn_verify', 'required' => ['ssn'], 'narrowing' => []],
        ]);

        self::assertTrue($sequence->run(self::IDENTITY)->isFailed());
        self::assertSame(['ssn_verify'], $gateway->called);
    }

    public function testACheckWithNoDeclaredFieldsSendsNothingAndStillRuns(): void
    {
        $gateway = $this->gateway(['crosscheck' => IdentityVerdict::inconclusive('crosscheck')]);

        (new IdentityVerificationSequence($gateway, [['slug' => 'crosscheck']]))->run(self::IDENTITY);

        self::assertSame(['crosscheck'], $gateway->called);
        self::assertSame([], $gateway->payloads[0]);
    }

    /** @param array<string, IdentityVerdict> $verdicts */
    private function gateway(array $verdicts): IdentityGateway
    {
        return new class ($verdicts) implements IdentityGateway {
            /** @var list<string> */
            public array $called = [];

            /** @var list<array<string, mixed>> */
            public array $payloads = [];

            /** @param array<string, IdentityVerdict> $verdicts */
            public function __construct(private readonly array $verdicts)
            {
            }

            public function verify(string $check, array $identity): IdentityVerdict
            {
                $this->called[] = $check;
                $this->payloads[] = $identity;

                return $this->verdicts[$check] ?? IdentityVerdict::inconclusive($check);
            }

            public function isEnabled(): bool
            {
                return true;
            }
        };
    }
}
