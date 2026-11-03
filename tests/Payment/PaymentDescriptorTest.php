<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Payment;

use AsterMD\Storefront\Payment\CardBrand;
use AsterMD\Storefront\Payment\CardDescriptor;
use AsterMD\Storefront\Payment\PaymentCredential;
use AsterMD\Storefront\Payment\PaymentDescriptor;
use PHPUnit\Framework\TestCase;

final class PaymentDescriptorTest extends TestCase
{
    private static function visa(): PaymentCredential
    {
        return PaymentCredential::card('4111111111111111', '09', '2030', '123');
    }

    public function testTheCardIsTheBrandTheLastFourDigitsAndAnMmYyExpiryAndNeverTheBin(): void
    {
        $card = CardDescriptor::fromCredential(self::visa());

        self::assertSame(['type' => 'visa', 'last_4' => '1111', 'exp' => '09/30'], $card->toArray());
    }

    public function testTheLastFourIgnoresTheSpacingTheBuyerTyped(): void
    {
        $card = CardDescriptor::fromCredential(PaymentCredential::card('4111 1111 1111 4321', '09', '2030', '123'));

        self::assertSame('4321', $card->toArray()['last_4']);
    }

    public function testATwoDigitYearIsAcceptedWhereAFourDigitOneWasStored(): void
    {
        $card = CardDescriptor::fromCredential(PaymentCredential::card('4111111111111111', '9', '30', '123'));

        self::assertSame('09/30', $card->toArray()['exp']);
    }

    public function testAnUnplaceableNumberStillReportsTheDigitsAndExpiryTheBuyerTyped(): void
    {
        // The brand is the one field that may be underivable. The digits and
        // the expiry come off the form the buyer filled in, so they are known
        // whatever the prefix turns out to be, and dropping the card object
        // over the one missing field would throw away the rest.
        $card = CardDescriptor::fromCredential(
            PaymentCredential::card('1444444444444440', '09', '2030', '123'),
        );

        self::assertSame(['last_4' => '4440', 'exp' => '09/30'], $card->toArray());
    }

    public function testTheProvidersOwnBrandAnswersOnlyWhenThePrefixCouldNot(): void
    {
        $unplaceable = PaymentCredential::card('1444444444444440', '09', '2030', '123');

        $fallen = CardDescriptor::fromCredential($unplaceable, CardBrand::Jcb);
        self::assertSame('jcb', $fallen->toArray()['type']);

        // The prefix wins where it has an answer: the provider's reading is the
        // answer to "we could not tell", not a second opinion.
        $placeable = CardDescriptor::fromCredential(self::visa(), CardBrand::Jcb, '01/40', '9999');
        self::assertSame('visa', $placeable->toArray()['type']);
        self::assertSame('1111', $placeable->toArray()['last_4'], 'a typed number outranks the provider');
        self::assertSame('09/30', $placeable->toArray()['exp'], 'a typed expiry outranks the provider');
    }

    public function testATokenTheStorefrontNeverSawTakesTheProvidersLastFour(): void
    {
        $card = CardDescriptor::fromCredential(PaymentCredential::token('pm_abc'), CardBrand::Visa, '04/31', '4242');

        self::assertSame(['type' => 'visa', 'last_4' => '4242', 'exp' => '04/31'], $card->toArray());
    }

    public function testAProviderAnswerThatIsNotFourDigitsIsNoLastFour(): void
    {
        foreach (['', '424', '42424', '42a2'] as $answer) {
            $card = CardDescriptor::fromCredential(PaymentCredential::token('pm_abc'), CardBrand::Visa, '04/31', $answer);

            self::assertArrayNotHasKey('last_4', $card->toArray(), var_export($answer, true));
        }
    }

    public function testACapturedOrderSaysItHeldNothing(): void
    {
        $payment = new PaymentDescriptor(
            PaymentDescriptor::TYPE_CREDIT_CARD,
            preAuth: false,
            preAuthQa: null,
            preAuthAmountCents: null,
            card: CardDescriptor::fromCredential(self::visa()),
        );

        self::assertSame([
            'type' => 'credit_card',
            'pre_auth' => false,
            'card' => ['type' => 'visa', 'last_4' => '1111', 'exp' => '09/30'],
        ], $payment->toArray());
    }

    public function testTheHeldAmountIsDollarsAndNotCents(): void
    {
        // The EMR accepts a cents integer where it documents a float dollar
        // amount without complaining, so a skipped conversion reports 100x and
        // is invisible everywhere but here.
        $payment = new PaymentDescriptor(
            PaymentDescriptor::TYPE_CREDIT_CARD,
            preAuth: true,
            preAuthQa: true,
            preAuthAmountCents: 10850,
            card: null,
        );

        self::assertSame(108.5, $payment->toArray()['pre_auth_amount']);
    }

    public function testTheQaKeyIsAbsentUnlessTheOrderActuallyHeldUnderThatMechanism(): void
    {
        // Authorized, but not through the QA mechanism.
        $preauth = new PaymentDescriptor(PaymentDescriptor::TYPE_CREDIT_CARD, true, false, 10850, null);
        self::assertArrayNotHasKey('pre_auth_qa', $preauth->toArray());

        // An adapter with no such mechanism at all.
        $unknown = new PaymentDescriptor(PaymentDescriptor::TYPE_CREDIT_CARD, true, null, 10850, null);
        self::assertArrayNotHasKey('pre_auth_qa', $unknown->toArray());

        // Captured, so nothing was held by any mechanism.
        $captured = new PaymentDescriptor(PaymentDescriptor::TYPE_CREDIT_CARD, false, true, null, null);
        self::assertArrayNotHasKey('pre_auth_qa', $captured->toArray());

        $held = new PaymentDescriptor(PaymentDescriptor::TYPE_CREDIT_CARD, true, true, 10850, null);
        self::assertTrue($held->toArray()['pre_auth_qa']);
    }

    public function testAnAbsentOptionalIsAnAbsentKeyAndNeverAFalseOrAZero(): void
    {
        $payment = new PaymentDescriptor(PaymentDescriptor::TYPE_CREDIT_CARD, false, null, null, null);

        self::assertSame(['type' => 'credit_card', 'pre_auth' => false], $payment->toArray());
    }
}
