<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Checkout;

use AsterMD\Storefront\Checkout\ConsentRecord;
use AsterMD\Storefront\Checkout\DatabaseOrderRecorder;
use AsterMD\Storefront\Payment\Buyer;
use AsterMD\Storefront\Payment\ChargeDiscrepancy;
use AsterMD\Storefront\Payment\OrderEnvelope;
use AsterMD\Storefront\Payment\OrderLine;
use AsterMD\Storefront\Payment\PaymentCredential;
use AsterMD\Storefront\Payment\PlacementOutcome;
use AsterMD\Storefront\Repository\OrderRepository;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The local order record, against a real migrated database rather than a
 * repository double — the whole class is a translation into columns, and a
 * double would assert the translation against itself.
 */
final class DatabaseOrderRecorderTest extends TestCase
{
    use TempDatabase;

    private const string SESSION = '4f1b6f2a-8c3d-4e6a-9b7f-2d5e8a1c6f30';

    private const string CARD = '4111111100084444';

    private \PDO $pdo;

    private CapturedLog $log;

    protected function setUp(): void
    {
        $this->pdo = $this->tempPdo();
        $this->log = new CapturedLog();
        (new SessionRepository(fn (): \PDO => $this->pdo))->insert(self::SESSION, [], null);
    }

    public function testAPlacedOrderIsWrittenWithItsLinesAndConsents(): void
    {
        $id = $this->recorder()->record(
            $this->envelope(),
            PlacementOutcome::placed('34660', 'approved'),
            PaymentCredential::card(self::CARD, '12', '2030', '123'),
            [new ConsentRecord('terms', true, 'v1', 'I agree to the terms.', '2026-08-24T00:00:00+00:00')],
            'vrio',
        );

        self::assertIsInt($id);

        $row = $this->orders()->findByReference('34660');

        self::assertNotNull($row);
        self::assertSame(self::SESSION, $row['session_uuid']);
        self::assertSame(10800, $row['amount_cents']);
        self::assertSame(1200, $row['discount_cents']);
        self::assertSame('placed', $row['status']);
        self::assertSame('vrio', $row['provider_category']);
        self::assertSame('Ada Lovelace', $row['buyer_name']);
        self::assertSame('CA', $row['buyer_territory']);
        self::assertSame('SAVE', $row['promotion_code']);
        self::assertCount(2, $row['lines']);
        self::assertCount(1, $row['consents']);
        self::assertSame('I agree to the terms.', $row['consents'][0]['copy_shown']);
    }

    public function testOnlyTheLastFourDigitsOfTheCardAreStored(): void
    {
        // `[15.8]`: four digits are what a receipt and a support call need. A
        // row holding the rest would be a card at rest.
        $this->recorder()->record(
            $this->envelope(),
            PlacementOutcome::placed('34660'),
            PaymentCredential::card(self::CARD, '12', '2030', '123'),
            [],
            'vrio',
        );

        $row = $this->orders()->findByReference('34660');

        self::assertSame('4444', $row['card_last_four'] ?? null);
        self::assertStringNotContainsString(self::CARD, (string) json_encode($row));
    }

    public function testALineTheProviderWasNeverToldAboutIsKeptAndFlagged(): void
    {
        // `[13.19]` cannot be honoured for a free attachment with no mapping,
        // but the local record still says what the buyer saw.
        $this->recorder()->record(
            $this->envelope(),
            PlacementOutcome::placed('34660'),
            PaymentCredential::card(self::CARD, '12', '2030', '123'),
            [],
            'vrio',
        );

        $lines = $this->orders()->findByReference('34660')['lines'];

        self::assertTrue($lines[0]['sent_to_provider']);
        self::assertFalse($lines[1]['sent_to_provider'], 'the unmapped attachment is stored, not dropped');
    }

    public function testADiscrepantChargeRecordsWhatWasChargedNotWhatWasDisplayed(): void
    {
        // The money that moved is the money on the record. A row holding the
        // quoted figure shows a human reconciling a $27 debit as $3.00.
        $this->recorder()->record(
            $this->envelope(),
            PlacementOutcome::placed('34788', '3', new ChargeDiscrepancy(expectedCents: 10800, chargedCents: 27000)),
            PaymentCredential::card(self::CARD, '12', '2030', '123'),
            [],
            'vrio',
        );

        $row = $this->orders()->findByReference('34788');

        self::assertSame(27000, $row['amount_cents'] ?? null, 'the money that moved is the money on the record');
    }

    public function testAnUndisputedChargeStillRecordsTheQuotedTotal(): void
    {
        // The discrepancy is the only reason to prefer anything but the
        // envelope's own figure, so its absence must change nothing.
        $this->recorder()->record(
            $this->envelope(),
            PlacementOutcome::placed('34660'),
            PaymentCredential::card(self::CARD, '12', '2030', '123'),
            [],
            'vrio',
        );

        self::assertSame(10800, $this->orders()->findByReference('34660')['amount_cents'] ?? null);
    }

    public function testTheCatalogsKindReachesTheLineColumnRatherThanTheDefault(): void
    {
        // A prescription recorded as `otc` is a durable record that under-states
        // what was sold, and the column defaults to `otc` -- so asserting the
        // parameter exists proves nothing. The mixed envelope is the assertion.
        $this->recorder()->record(
            $this->envelope(),
            PlacementOutcome::placed('34660'),
            PaymentCredential::card(self::CARD, '12', '2030', '123'),
            [],
            'vrio',
        );

        $lines = $this->orders()->findByReference('34660')['lines'];

        self::assertSame(['rx', 'otc'], array_column($lines, 'kind'));
    }

    public function testAnUpsellOrderIsFlaggedAsOneAndACheckoutOrderIsNot(): void
    {
        // `[16.12]`: the column is what separates the money a buyer agreed to
        // at checkout from the money they agreed to afterwards, and a checkout
        // order that claimed to be an upsell would mis-attribute both.
        $this->recorder()->record(
            $this->envelope(),
            PlacementOutcome::placed('34660'),
            PaymentCredential::card(self::CARD, '12', '2030', '123'),
            [],
            'vrio',
        );
        $this->recorder()->record(
            $this->envelope(),
            PlacementOutcome::placed('34790'),
            PaymentCredential::card(self::CARD, '12', '2030', '123'),
            [],
            'vrio',
            isUpsell: true,
        );

        self::assertFalse($this->orders()->findByReference('34660')['is_upsell']);
        self::assertTrue($this->orders()->findByReference('34790')['is_upsell']);
    }

    public function testAnOutcomeWithNoProviderReferenceIsNotRecorded(): void
    {
        // The reference is what `[18.1]`'s reconciliation query is keyed on, so
        // a row without one could never be matched to anything.
        $id = $this->recorder()->record(
            $this->envelope(),
            PlacementOutcome::declined(null, 'Card declined.'),
            PaymentCredential::card(self::CARD, '12', '2030', '123'),
            [],
            'vrio',
        );

        self::assertNull($id);
        self::assertSame('checkout.order_not_recorded', $this->log->lastWarning()['event'] ?? null);
    }

    public function testAFailedWriteReturnsNullRatherThanThrowingAtABuyerWhoHasPaid(): void
    {
        // `[20.1]`: by the time this runs the money has moved, so an exception
        // here would break a purchase that actually succeeded.
        $this->pdo->exec('DROP TABLE order_lines');

        $id = $this->recorder()->record(
            $this->envelope(),
            PlacementOutcome::placed('34660'),
            PaymentCredential::card(self::CARD, '12', '2030', '123'),
            [],
            'vrio',
        );

        self::assertNull($id);
        self::assertSame('checkout.order_not_recorded', $this->log->lastError()['event'] ?? null);
    }

    public function testTheFailureLogNeverCarriesTheDriversOwnMessage(): void
    {
        // A constraint violation quotes the offending values back, and one of
        // the values on this insert is a buyer's email address.
        $this->pdo->exec('DROP TABLE order_lines');

        $this->recorder()->record(
            $this->envelope(),
            PlacementOutcome::placed('34660'),
            PaymentCredential::card(self::CARD, '12', '2030', '123'),
            [],
            'vrio',
        );

        self::assertStringNotContainsString('order_lines', $this->log->contents());
    }

    // ---------------------------------------------------------------- fixtures

    private function recorder(): DatabaseOrderRecorder
    {
        return new DatabaseOrderRecorder($this->orders(), $this->log->log);
    }

    private function orders(): OrderRepository
    {
        return new OrderRepository(fn (): \PDO => $this->pdo);
    }

    /** One mapped Rx line and one free attachment the provider has no identity for -- two kinds, so a hardcoded one cannot pass. */
    private function envelope(): OrderEnvelope
    {
        return new OrderEnvelope(
            lines: [
                new OrderLine('tirzepatide', 'Tirzepatide', '337', '3415', 12000, 1, 'rx'),
                new OrderLine('syringe', 'Syringe', null, null, 0, 1),
            ],
            buyer: new Buyer('Ada', 'Lovelace', 'ada@example.com', '2125551234', '350 5th Avenue', 'San Francisco', 'CA', '94105'),
            subtotalCents: 12000,
            discountCents: 1200,
            totalCents: 10800,
            currency: 'USD',
            promotionCode: 'SAVE',
            attribution: [],
            sessionUuid: self::SESSION,
            clientIp: '203.0.113.4',
            userAgent: 'Mozilla/5.0 (test)',
            idempotencyKey: 'idem-1',
            anchorSlug: 'tirzepatide',
        );
    }
}
