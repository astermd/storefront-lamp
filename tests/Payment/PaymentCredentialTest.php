<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Payment;

use AsterMD\Storefront\Payment\PaymentCredential;
use PHPUnit\Framework\TestCase;

final class PaymentCredentialTest extends TestCase
{
    private const string PAN = '4111111100084444';

    private function card(): PaymentCredential
    {
        return PaymentCredential::card(self::PAN, '12', '2030', '123');
    }

    public function testTheDigitsOfTheNumberAreKeptAndTheRestDiscarded(): void
    {
        $credential = PaymentCredential::card('4111-1111 0008.4444', '12', '2030', '123');

        self::assertSame(self::PAN, $credential->number);
        self::assertSame('4444', $credential->lastFour());
    }

    public function testADebugDumpNeverCarriesTheNumber(): void
    {
        self::assertStringNotContainsString(self::PAN, print_r($this->card(), true));

        ob_start();
        var_dump($this->card());
        self::assertStringNotContainsString(self::PAN, (string) ob_get_clean());
    }

    public function testJsonEncodingNeverCarriesTheNumber(): void
    {
        // __debugInfo() covers var_dump and print_r and nothing else. An
        // encoder walking a structure that happens to hold a credential --
        // an error payload, a cache write, a queued job -- reaches the public
        // readonly properties directly.
        self::assertStringNotContainsString(self::PAN, (string) json_encode($this->card()));
    }

    public function testSerialisingNeverCarriesTheNumber(): void
    {
        self::assertStringNotContainsString(self::PAN, serialize($this->card()));
    }

    public function testARoundTripThroughSerialisationIsRefusedRatherThanQuietlyEmptied(): void
    {
        // A restored credential would hold a redacted number, look usable, and
        // be charged. Refusing is the only answer that cannot be missed.
        $this->expectException(\LogicException::class);

        unserialize(serialize($this->card()));
    }

    public function testATokenCredentialHasNoLastFourToOffer(): void
    {
        self::assertSame('', PaymentCredential::token('tok_1')->lastFour());
        self::assertSame('', PaymentCredential::orderReference('34660')->lastFour());
    }

    public function testAStoredInstrumentHoldsAnOpaqueHandleAndRoundTrips(): void
    {
        $credential = PaymentCredential::stored(['customer_id' => '13996', 'customer_card_id' => '16815']);

        self::assertSame(PaymentCredential::KIND_STORED_INSTRUMENT, $credential->kind);
        self::assertSame('13996', $credential->handle['customer_id']);
        self::assertTrue($credential->isReusable());
        self::assertSame('', $credential->lastFour(), 'a stored instrument has no card to take four digits from');

        $restored = PaymentCredential::fromStorable($credential->toStorable());

        self::assertNotNull($restored);
        self::assertSame($credential->handle, $restored->handle);
        self::assertSame($credential->kind, $restored->kind);
    }

    public function testACardRefusesToBecomeStorable(): void
    {
        // [15.8]: journey state is written to the sessions table, so a card
        // that could be made storable is a card at rest in the database.
        $card = PaymentCredential::card(self::PAN, '12', '2030', '737');

        $this->expectException(\LogicException::class);
        $card->toStorable();
    }

    public function testAStoredHandleIsNeverRenderedByAnyEncoder(): void
    {
        // var_export is deliberately absent: a plain object has no hook for it
        // and the class docblock says so, so asserting on it would pin a
        // guarantee this class does not make.
        $credential = PaymentCredential::stored(['customer_id' => '13996', 'customer_card_id' => '16815']);

        foreach ([json_encode($credential), print_r($credential, true)] as $text) {
            self::assertStringNotContainsString('13996', (string) $text);
            self::assertStringNotContainsString('16815', (string) $text);
        }
    }

    public function testFromStorableRefusesAShapeThatIsNotAReusableCredential(): void
    {
        self::assertNull(PaymentCredential::fromStorable([]));
        self::assertNull(PaymentCredential::fromStorable(['kind' => PaymentCredential::KIND_CARD, 'handle' => []]));
        self::assertNull(PaymentCredential::fromStorable(['kind' => 'nonsense', 'handle' => ['a' => 'b']]));
        self::assertNull(PaymentCredential::fromStorable(['kind' => PaymentCredential::KIND_STORED_INSTRUMENT, 'handle' => []]));
    }
}
