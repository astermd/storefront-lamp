<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Checkout;

use AsterMD\Storefront\Checkout\BuyerDetails;
use AsterMD\Storefront\Checkout\BuyerValidator;
use AsterMD\Storefront\Emr\NullVerificationGateway;
use AsterMD\Storefront\Emr\VerificationGateway;
use PHPUnit\Framework\TestCase;

final class BuyerValidatorTest extends TestCase
{
    /**
     * A submission with nothing wrong with it, which each test then spoils in
     * exactly one way.
     *
     * @param array<string, string> $overrides
     */
    private function details(array $overrides = []): BuyerDetails
    {
        return BuyerDetails::fromSubmitted($overrides + [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'phone' => '2125551234',
            'address_line' => '350 5th Avenue',
            'city' => 'New York',
            'territory' => 'NY',
            'postal_code' => '10118',
        ]);
    }

    /**
     * A gateway that reports itself disabled and fails the test if anything
     * calls it anyway — which is what `features.emr_verification` being off
     * has to mean in practice.
     */
    private function offline(): VerificationGateway
    {
        return new class () implements VerificationGateway {
            public function emailIsDeliverable(string $email): ?bool
            {
                throw new \LogicException('The disabled gateway was called.');
            }

            public function normaliseAddress(string $address): ?array
            {
                throw new \LogicException('The disabled gateway was called.');
            }

            public function isEnabled(): bool
            {
                return false;
            }
        };
    }

    /** A granted credential, answering whatever the test wants it to answer. */
    private function answering(?bool $deliverable): VerificationGateway
    {
        return new class ($deliverable) implements VerificationGateway {
            public function __construct(private readonly ?bool $deliverable)
            {
            }

            public function emailIsDeliverable(string $email): ?bool
            {
                return $this->deliverable;
            }

            public function normaliseAddress(string $address): ?array
            {
                return null;
            }

            public function isEnabled(): bool
            {
                return true;
            }
        };
    }

    public function testACompleteSubmissionHasNothingWrongWithIt(): void
    {
        // [13.1]: first name, last name, email, phone, address line, city,
        // territory and postal code are the whole collected set.
        self::assertSame([], BuyerValidator::validate($this->details(), $this->offline()));
    }

    public function testAMissingFieldIsReportedUnderItsOwnFieldName(): void
    {
        // Keyed by field so the template can attach aria-describedby to the
        // control the message is about.
        $errors = BuyerValidator::validate($this->details(['city' => '']), $this->offline());

        self::assertArrayHasKey('city', $errors);
        self::assertNotSame('', $errors['city']);
        self::assertArrayNotHasKey('first_name', $errors);
    }

    public function testAWhitespaceOnlyFieldCountsAsMissing(): void
    {
        // "Present and trimmed" was the old rule; a box holding two spaces
        // satisfied it and reached the provider as an empty address line.
        $errors = BuyerValidator::validate($this->details(['address_line' => "   \t "]), $this->offline());

        self::assertArrayHasKey('address_line', $errors);
    }

    public function testAnEmailWithNoShapeAtAllIsRejected(): void
    {
        // [13.5]: the email shape check the old storefront did not have.
        $errors = BuyerValidator::validate($this->details(['email' => 'not-an-email']), $this->offline());

        self::assertArrayHasKey('email', $errors);
    }

    public function testAWellFormedEmailIsAccepted(): void
    {
        self::assertArrayNotHasKey(
            'email',
            BuyerValidator::validate($this->details(['email' => 'ada@example.com']), $this->offline()),
        );
    }

    public function testAPhoneIsAcceptedHoweverItIsPunctuatedAndStoredAsDigits(): void
    {
        // [13.5]: phone normalisation. The two spellings are one number, and
        // storing them as two would make a reformat look like a correction.
        $typed = $this->details(['phone' => '(212) 555-1234']);
        $bare = $this->details(['phone' => '2125551234']);

        self::assertSame([], BuyerValidator::validate($typed, $this->offline()));
        self::assertSame([], BuyerValidator::validate($bare, $this->offline()));
        self::assertSame('2125551234', $typed->phone);
        self::assertSame('2125551234', $bare->phone);
    }

    public function testAPhoneWithFewerThanTenDigitsIsRejected(): void
    {
        $errors = BuyerValidator::validate($this->details(['phone' => '555-1234']), $this->offline());

        self::assertArrayHasKey('phone', $errors);
    }

    public function testATerritoryIsAcceptedInLowerCaseAndCanonicalisedToUpper(): void
    {
        // [13.1]: the territory is an uppercased two-letter code.
        $details = $this->details(['territory' => 'ny']);

        self::assertSame([], BuyerValidator::validate($details, $this->offline()));
        self::assertSame('NY', $details->territory);
    }

    public function testAThreeLetterTerritoryIsRejected(): void
    {
        // [13.1]: NYC is a city, and the provider's ship_state field is two
        // characters wide.
        $errors = BuyerValidator::validate($this->details(['territory' => 'NYC']), $this->offline());

        self::assertArrayHasKey('territory', $errors);
    }

    public function testATerritoryOutsideTheUnitedStatesIsRejectedAndTheMessageSaysWhy(): void
    {
        // [13.36] is a declared gap: the country is hardcoded and the
        // territory set is US-only. Checking against the set is what makes the
        // gap visible to the buyer instead of silent until delivery fails.
        $errors = BuyerValidator::validate($this->details(['territory' => 'ON']), $this->offline());

        self::assertArrayHasKey('territory', $errors);
        self::assertStringContainsString('United States', $errors['territory']);
    }

    public function testAFourDigitPostalCodeIsRejected(): void
    {
        // [13.5]: the postal-code check the old storefront did not have.
        $errors = BuyerValidator::validate($this->details(['postal_code' => '1234']), $this->offline());

        self::assertArrayHasKey('postal_code', $errors);
    }

    public function testAFiveDigitPostalCodeAndAZipPlusFourAreBothAccepted(): void
    {
        self::assertSame([], BuyerValidator::validate($this->details(['postal_code' => '10118']), $this->offline()));
        self::assertSame([], BuyerValidator::validate($this->details(['postal_code' => '10118-0001']), $this->offline()));
    }

    public function testWithVerificationOffTheGatewayIsNeverCalledAndTheLocalCheckStands(): void
    {
        // The flag ships off because the credential is refused for the whole
        // verification() resource. An address that looks undeliverable but is
        // well formed is accepted on the local check alone -- and no denied
        // round trip is made on the way to accepting it.
        $errors = BuyerValidator::validate(
            $this->details(['email' => 'nobody@example.invalid']),
            new NullVerificationGateway(),
        );

        self::assertSame([], $errors);
        self::assertSame([], BuyerValidator::validate($this->details(), $this->offline()));
    }

    public function testWithVerificationOnAnUndeliverableEmailIsRejected(): void
    {
        $errors = BuyerValidator::validate($this->details(), $this->answering(false));

        self::assertArrayHasKey('email', $errors);
        self::assertStringNotContainsString('gateway', strtolower($errors['email']));
    }

    public function testWithVerificationOnAnUndeterminableAnswerDoesNotBlockTheOrder(): void
    {
        // [20.1]: the permission is denied, or the provider is down. Either
        // way the check did not run, and a check that did not run is not a
        // reason to refuse someone's money.
        self::assertSame([], BuyerValidator::validate($this->details(), $this->answering(null)));
    }

    public function testWithVerificationOnADeliverableEmailPassesUntouched(): void
    {
        self::assertSame([], BuyerValidator::validate($this->details(), $this->answering(true)));
    }
}
