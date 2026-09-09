<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Completion;

use AsterMD\Storefront\Completion\Receipt;
use PHPUnit\Framework\TestCase;

final class ReceiptTest extends TestCase
{
    public function testTwoPlacedOrdersFoldIntoOnePaidTotal(): void
    {
        // [16.10]: an accepted upsell folds into the running totals, and the
        // arithmetic is over the local rows rather than a counter kept in
        // journey state that could disagree with them.
        $receipt = Receipt::fromOrderRows(
            [
                self::row('34788', 'placed', amountCents: 12000, discountCents: 1000, lines: [
                    self::line('semaglutide', 'Semaglutide', 'rx', 13000, 1),
                ]),
                self::row('34790', 'placed', amountCents: 4900, lines: [
                    self::line('wellness-pack', 'Wellness Pack', 'otc', 4900, 1),
                ]),
            ],
            self::buyer(),
            'USD',
        );

        self::assertSame(['34788', '34790'], $receipt->references);
        self::assertSame([], $receipt->declinedReferences);
        self::assertSame(16900, $receipt->paidTotalCents);
        self::assertSame(17900, $receipt->subtotalCents);
        self::assertSame(1000, $receipt->discountCents);
        self::assertSame('USD', $receipt->currency);
        self::assertSame('NEW10', $receipt->promotionCode);
        self::assertSame(['semaglutide', 'wellness-pack'], array_column($receipt->lines, 'slug'));
        self::assertSame(['rx', 'otc'], array_column($receipt->lines, 'kind'));
    }

    public function testADeclinedOrderIsNamedButIsNotMoneyTheBuyerPaid(): void
    {
        // [13.26]: a declined placement still creates a provider reference and
        // still gets a row. It is on the receipt so support can reconcile it,
        // and it is not in the total because nobody was charged for it.
        $receipt = Receipt::fromOrderRows(
            [
                self::row('34788', 'placed', amountCents: 12000, lines: [
                    self::line('semaglutide', 'Semaglutide', 'rx', 12000, 1),
                ]),
                self::row('34791', 'declined', amountCents: 4900, lines: [
                    self::line('sleep-kit', 'Sleep Kit', 'otc', 4900, 1),
                ]),
            ],
            self::buyer(),
            'USD',
        );

        self::assertSame(['34788'], $receipt->references);
        self::assertSame(['34791'], $receipt->declinedReferences);
        self::assertSame(12000, $receipt->paidTotalCents);
        self::assertSame(12000, $receipt->subtotalCents);
        self::assertSame(
            ['semaglutide'],
            array_column($receipt->lines, 'slug'),
            'a line nobody paid for is not a line on the receipt',
        );
    }

    public function testAPendingActionOrderIsNotCountedAsPaidEither(): void
    {
        // The third placement state. It reaches this class only on a journey
        // that came back from a challenge, and a total that counted it would
        // tell the buyer they had paid for something still unresolved.
        $receipt = Receipt::fromOrderRows(
            [self::row('34792', 'pending_action', amountCents: 4900)],
            self::buyer(),
            'USD',
        );

        self::assertSame([], $receipt->references);
        self::assertSame(['34792'], $receipt->declinedReferences);
        self::assertSame(0, $receipt->paidTotalCents);
    }

    public function testTheStoredShapeRoundTripsLosslessly(): void
    {
        $receipt = Receipt::fromOrderRows(
            [
                self::row('34788', 'placed', amountCents: 12000, discountCents: 1000, lines: [
                    self::line('semaglutide', 'Semaglutide', 'rx', 13000, 1),
                ]),
                self::row('34791', 'declined', amountCents: 4900),
            ],
            self::buyer(),
            'USD',
        );

        $restored = Receipt::fromArray($receipt->toArray());

        self::assertSame($receipt->toArray(), $restored->toArray());
        self::assertSame(['34788'], $restored->references);
        self::assertSame(['34791'], $restored->declinedReferences);
        self::assertSame(12000, $restored->paidTotalCents);
        self::assertSame('4444', $restored->cardLastFour);
        self::assertSame('card', $restored->paymentMethod);
        self::assertSame('Ada Lovelace', $restored->buyerName);
        self::assertSame('ada@example.com', $restored->buyerEmail);
        self::assertSame('2125551234', $restored->buyerPhone);
        self::assertSame('1 Nightingale Way', $restored->shipping['address_line']);
        self::assertSame('CA', $restored->shipping['territory']);
        self::assertTrue($restored->pendingReview);
    }

    public function testNoOrdersAtAllYieldsAnEmptyReceiptRatherThanAnError(): void
    {
        // The completion step runs on whatever the order tables actually hold.
        // A write that failed after the charge leaves no row (`[13.19]`'s
        // sibling case), and the buyer still has to reach a page.
        $receipt = Receipt::fromOrderRows([], [], 'USD');

        self::assertSame([], $receipt->references);
        self::assertSame([], $receipt->declinedReferences);
        self::assertSame([], $receipt->lines);
        self::assertSame(0, $receipt->subtotalCents);
        self::assertSame(0, $receipt->discountCents);
        self::assertSame(0, $receipt->paidTotalCents);
        self::assertSame('USD', $receipt->currency, 'the configured currency, since no row can name one');
        self::assertNull($receipt->promotionCode);
        self::assertNull($receipt->cardLastFour);
        self::assertNull($receipt->placedAt);
        self::assertSame('', $receipt->buyerEmail);
        self::assertTrue($receipt->pendingReview);
    }

    public function testACardHandedInWithTheBuyerNeverReachesTheStoredReceipt(): void
    {
        // The receipt is the one thing that survives the journey wipe, so a
        // card here is a card at rest in the `sessions` table for good.
        // `$buyer` is a loose map and the only defence is that this class
        // copies the fields it names rather than whatever it was given.
        $receipt = Receipt::fromOrderRows(
            [self::row('34788', 'placed', amountCents: 12000)],
            self::buyer() + [
                'number' => '4111111100084444',
                'card_number' => '4111111100084444',
                'security_code' => '737',
            ],
            'USD',
        );

        $encoded = (string) json_encode($receipt->toArray());

        self::assertStringNotContainsString('4111111100084444', $encoded);
        self::assertStringNotContainsString('737', $encoded);
        self::assertSame('4444', $receipt->cardLastFour, 'four digits are all a receipt needs');
        self::assertSame(
            ['name', 'address_line', 'city', 'territory', 'postal_code', 'country'],
            array_keys($receipt->shipping),
            'the shipping block is a named field set, not a copy of whatever arrived',
        );
    }

    public function testALineTheProviderNeverHeardAboutIsStillOnTheReceipt(): void
    {
        // [13.19]: a line the catalog could not map to a provider offer is
        // recorded rather than dropped, so the local record still says what the
        // buyer saw. The receipt is that record read back.
        $receipt = Receipt::fromOrderRows(
            [self::row('34788', 'placed', amountCents: 12000, lines: [
                self::line('semaglutide', 'Semaglutide', 'rx', 9000, 1),
                self::line('mystery-kit', 'Mystery Kit', 'otc', 3000, 1, sentToProvider: false),
            ])],
            self::buyer(),
            'USD',
        );

        self::assertSame(['semaglutide', 'mystery-kit'], array_column($receipt->lines, 'slug'));
        self::assertSame(12000, $receipt->subtotalCents);
    }

    public function testAQuantityGreaterThanOneIsPricedAsTheLineNotAsTheUnit(): void
    {
        $receipt = Receipt::fromOrderRows(
            [self::row('34788', 'placed', amountCents: 9000, lines: [
                self::line('wellness-pack', 'Wellness Pack', 'otc', 3000, 3),
            ])],
            self::buyer(),
            'USD',
        );

        self::assertSame(9000, $receipt->subtotalCents);
        self::assertSame(3, $receipt->lines[0]['quantity']);
        self::assertSame(3000, $receipt->lines[0]['unit_price_cents']);
    }

    public function testAStoredReceiptWrittenInAShapeThatNoLongerParsesDegradesRatherThanThrows(): void
    {
        // The stored blob is read back from a JSON column written by whatever
        // release last touched it. A shape that no longer parses has to become
        // an empty receipt, because the alternative is a fatal on the one page
        // a buyer who has just been charged is entitled to see.
        $restored = Receipt::fromArray([
            'references' => 'thirty-four-thousand',
            'lines' => [['name' => 'no slug'], 'not a line'],
            'paid_total_cents' => 'twelve thousand',
            'shipping' => 'somewhere',
            'pending_review' => 'yes',
        ]);

        self::assertSame([], $restored->references);
        self::assertSame([], $restored->lines);
        self::assertSame(0, $restored->paidTotalCents);
        self::assertSame(
            ['name', 'address_line', 'city', 'territory', 'postal_code', 'country'],
            array_keys($restored->shipping),
        );
        self::assertTrue($restored->pendingReview, 'this revision has exactly one clinical status');
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return array<string, mixed> as OrderRepository::findByReference() returns it
     */
    private static function row(
        string $reference,
        string $status,
        int $amountCents,
        int $discountCents = 0,
        array $lines = [],
    ): array {
        return [
            'id' => 1,
            'session_uuid' => 'sess-1',
            'provider_reference' => $reference,
            'anchor_slug' => 'semaglutide',
            'amount_cents' => $amountCents,
            'currency' => 'USD',
            'status' => $status,
            'treatment_reference' => null,
            'buyer_email' => 'row@example.com',
            'buyer_name' => 'Row Name',
            'buyer_territory' => 'NY',
            'discount_cents' => $discountCents,
            'promotion_code' => $discountCents > 0 ? 'NEW10' : null,
            'payment_method' => 'card',
            'card_last_four' => '4444',
            'idempotency_key' => 'key-' . $reference,
            'provider_category' => 'vrio',
            'placed_at' => '2026-08-24T12:00:00+00:00',
            'created_at' => '2026-08-24T12:00:00+00:00',
            'updated_at' => '2026-08-24T12:00:00+00:00',
            'lines' => $lines,
            'consents' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function line(
        string $slug,
        string $name,
        string $kind,
        int $unitPriceCents,
        int $quantity,
        bool $sentToProvider = true,
    ): array {
        return [
            'slug' => $slug,
            'name' => $name,
            'kind' => $kind,
            'provider_offer' => $sentToProvider ? '337' : null,
            'provider_item' => $sentToProvider ? '3414' : null,
            'unit_price_cents' => $unitPriceCents,
            'quantity' => $quantity,
            'sent_to_provider' => $sentToProvider,
        ];
    }

    /** @return array<string, string> as JourneyState::$buyer holds it */
    private static function buyer(): array
    {
        return [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'phone' => '2125551234',
            'address_line' => '1 Nightingale Way',
            'city' => 'Oakland',
            'territory' => 'CA',
            'postal_code' => '94601',
        ];
    }
}
