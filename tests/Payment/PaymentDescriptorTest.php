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

    public function testTheCardIsTheBrandTheBinNamesTheFirstSixDigitsAndAnMmYyExpiry(): void
    {
        $card = CardDescriptor::fromCredential(self::visa());

        self::assertSame(['type' => 'visa', 'bin' => '411111', 'exp' => '09/30'], $card->toArray());
    }

    public function testATwoDigitYearIsAcceptedWhereAFourDigitOneWasStored(): void
    {
        $card = CardDescriptor::fromCredential(PaymentCredential::card('4111111111111111', '9', '30', '123'));

        self::assertSame('09/30', $card->toArray()['exp']);
    }

    public function testAnUnplaceableNumberStillReportsTheBinAndExpiryTheBuyerTyped(): void
    {
        // The brand is the one field that may be underivable. The bin and the
        // expiry come off the form the buyer filled in, so they are known
        // whatever the prefix turns out to be, and dropping the card object
        // over the one missing field would throw away the two that are not.
        $card = CardDescriptor::fromCredential(
            PaymentCredential::card('1444444444444440', '09', '2030', '123'),
        );

        self::assertSame(['bin' => '144444', 'exp' => '09/30'], $card->toArray());
        self::assertArrayNotHasKey('type', $card->toArray());
    }

    public function testTheProvidersOwnBrandAnswersOnlyWhenThePrefixCouldNot(): void
    {
        $unplaceable = PaymentCredential::card('1444444444444440', '09', '2030', '123');

        $fallen = CardDescriptor::fromCredential($unplaceable, CardBrand::Jcb);
        self::assertSame('jcb', $fallen->toArray()['type']);

        // The prefix wins where it has an answer: the provider's reading is the
        // answer to "we could not tell", not a second opinion. Nothing else in
        // the card object is ever taken from the provider.
        $placeable = CardDescriptor::fromCredential(self::visa(), CardBrand::Jcb);
        self::assertSame('visa', $placeable->toArray()['type']);
        self::assertSame('411111', $placeable->toArray()['bin']);
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
            'card' => ['type' => 'visa', 'bin' => '411111', 'exp' => '09/30'],
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
