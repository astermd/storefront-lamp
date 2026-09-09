<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Payment;

use AsterMD\Storefront\Payment\Buyer;
use AsterMD\Storefront\Payment\NullPaymentAdapter;
use AsterMD\Storefront\Payment\OrderEnvelope;
use AsterMD\Storefront\Payment\OrderLine;
use AsterMD\Storefront\Payment\PaymentCredential;
use AsterMD\Storefront\Payment\PlacementOutcome;
use PHPUnit\Framework\TestCase;

final class AdapterContractTest extends TestCase
{
    public function testACardCredentialNeverRendersItsNumberInADebugDump(): void
    {
        // print_r, var_dump, a Throwable render and a container dump all go
        // through __debugInfo. Any one of them leaking a PAN is [15.8].
        $credential = PaymentCredential::card('4111 1111 1111 1111', '12', '2030', '123');

        $dump = print_r($credential, true);

        self::assertStringNotContainsString('4111111111111111', $dump);
        self::assertStringNotContainsString('123', $dump);
        self::assertStringContainsString('[REDACTED]', $dump);
    }

    public function testACardCredentialStripsFormattingButKeepsTheLastFour(): void
    {
        $credential = PaymentCredential::card('4111-1111 1111 1111', '12', '2030', '123');

        self::assertSame('1111', $credential->lastFour());
    }

    public function testANonCardCredentialHasNoLastFour(): void
    {
        self::assertSame('', PaymentCredential::token('tok_abc')->lastFour());
    }

    public function testAnEnvelopeSeparatesChargeableLinesFromUnmappedOnes(): void
    {
        // Recorded: the channel's free Syringe add-on carries no provider
        // mapping at all, so there is nothing to send for it.
        $envelope = $this->envelopeWith([
            new OrderLine('tirzepatide', 'Tirzepatide', '337', '3414', 12000, 1),
            new OrderLine('syringe', 'Syringe', null, null, 0, 1),
        ]);

        self::assertCount(1, $envelope->chargeableLines());
        self::assertSame('tirzepatide', $envelope->chargeableLines()[0]->slug);
        self::assertCount(1, $envelope->unmappedLines());
        self::assertTrue($envelope->unmappedLines()[0]->isFree());
    }

    public function testALineIsUnchargeableWhenEitherIdentifierIsMissing(): void
    {
        // The provider refuses an offer without an item: "Item id required for
        // offer 337". Half a mapping is not a mapping.
        self::assertFalse((new OrderLine('x', 'X', '337', null, 100, 1))->isChargeable());
        self::assertFalse((new OrderLine('x', 'X', null, '3414', 100, 1))->isChargeable());
        self::assertFalse((new OrderLine('x', 'X', '', '', 100, 1))->isChargeable());
        self::assertTrue((new OrderLine('x', 'X', '337', '3414', 100, 1))->isChargeable());
    }

    public function testTheNullAdapterDeclinesEveryPlacementWithoutThrowing(): void
    {
        $outcome = (new NullPaymentAdapter())->place(
            $this->envelopeWith([new OrderLine('x', 'X', '337', '3414', 100, 1)]),
            PaymentCredential::card('4111111111111111', '12', '2030', '123'),
        );

        self::assertSame(PlacementOutcome::DECLINED, $outcome->state);
        self::assertNull($outcome->reference);
        self::assertSame(NullPaymentAdapter::DECLINE_MESSAGE, $outcome->reason);
    }

    public function testTheNullAdapterHidesThePromoControl(): void
    {
        self::assertFalse((new NullPaymentAdapter())->capabilities()->supportsPromotions);
    }

    public function testADeclineCanStillCarryAnOrderReference(): void
    {
        // [13.26]: the provider creates the order and then the card fails.
        $outcome = PlacementOutcome::declined('34661', 'Failed test transaction', 'null');

        self::assertFalse($outcome->isPlaced());
        self::assertSame('34661', $outcome->reference);
    }

    /** @param list<OrderLine> $lines */
    private function envelopeWith(array $lines): OrderEnvelope
    {
        $subtotal = array_sum(array_map(static fn (OrderLine $l): int => $l->lineTotalCents(), $lines));

        return new OrderEnvelope(
            lines: $lines,
            buyer: new Buyer('Ada', 'Lovelace', 'ada@example.com', '2125551234', '350 5th Avenue', 'New York', 'NY', '10118'),
            subtotalCents: $subtotal,
            discountCents: 0,
            totalCents: $subtotal,
            currency: 'USD',
            promotionCode: null,
            attribution: [],
            sessionUuid: '4f1b6f2a-8c3d-4e6a-9b7f-2d5e8a1c6f30',
            clientIp: '203.0.113.7',
            userAgent: 'test-agent/1.0',
            idempotencyKey: 'idem-1',
            anchorSlug: 'tirzepatide',
        );
    }
}
